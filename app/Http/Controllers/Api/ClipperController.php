<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminMessage;
use App\Models\Announcement;
use App\Models\Campaign;
use App\Models\CampaignSubmission;
use App\Models\Course;
use App\Models\Income;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClipperController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'identifier' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = str_replace('\\@', '@', trim($credentials['identifier'] ?? $credentials['email'] ?? ''));

        if (! $identifier) {
            throw ValidationException::withMessages(['identifier' => 'Email atau handle akun wajib diisi.']);
        }

        $user = User::where('email', $identifier)
            ->orWhere('handle', $identifier)
            ->first();

        if (! $user) {
            $user = SocialAccount::where('handle', $identifier)->first()?->user;
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['identifier' => 'Email, handle, atau password tidak sesuai.']);
        }

        $user->forceFill(['api_token' => Str::random(60)])->save();

        return response()->json(['token' => $user->api_token, 'user' => $this->userPayload($user)]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'handle' => ['nullable', 'string', 'max:255', 'unique:users,handle'],
        ]);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'status' => 'review',
            'api_token' => Str::random(60),
        ]);

        return response()->json(['token' => $user->api_token, 'user' => $this->userPayload($user)], 201);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($this->currentUser($request)));
    }

    public function dashboard(Request $request)
    {
        $user = $this->currentUser($request);
        $accounts = $user->socialAccounts()->latest()->get();
        $selectedAccountId = (int) $request->query('social_account_id') ?: $accounts->first()?->id;

        if ($selectedAccountId) {
            abort_unless(
                $accounts->contains('id', $selectedAccountId),
                422,
                'Akun sosial tidak terhubung ke user ini.',
            );
        }

        $incomeQuery = $user->incomes()->where('status', 'valid');
        $submissionQuery = $user->submissions()->whereNotNull('video_url');

        if ($selectedAccountId) {
            $incomeQuery->where('social_account_id', $selectedAccountId);
            $submissionQuery->where('social_account_id', $selectedAccountId);
        }

        $validIncome = $incomeQuery->sum('amount');
        $videoCount = (clone $submissionQuery)->count();

        return response()->json([
            'stats' => [
                ['label' => 'Total Pendapatan', 'value' => $this->rupiah($validIncome)],
                ['label' => 'Bisa Dicairkan', 'value' => $this->rupiah($validIncome)],
                ['label' => 'Total Video', 'value' => $videoCount.' Video'],
            ],
            'selected_account_id' => $selectedAccountId,
            'accounts' => $accounts->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'handle' => $account->handle,
                'platform' => $account->platform,
                'status' => Str::headline($account->status),
                'balance' => $this->rupiah($account->balance),
                'balance_value' => $account->balance,
            ]),
            'submissions' => (clone $submissionQuery)
                ->with(['campaign', 'socialAccount'])
                ->latest('submitted_at')
                ->get()
                ->values()
                ->map(fn (CampaignSubmission $submission) => [
                    'id' => $submission->id,
                    'submitted_at' => $submission->submitted_at?->translatedFormat('d M Y H:i'),
                    'caption' => $submission->campaign?->title ?? '-',
                    'type' => $submission->campaign?->type ?? '-',
                    'status' => Str::headline($submission->status),
                    'link' => $submission->video_url,
                    'account' => $submission->socialAccount?->handle,
                ]),
            'campaigns' => $this->campaignQuery()->take(12)->get()->map(fn ($campaign) => $this->campaignPayload($campaign)),
            'announcements' => Announcement::latest('published_at')->take(3)->get(),
        ]);
    }

    public function campaigns(Request $request)
    {
        $query = $this->campaignQuery();
        $user = $this->optionalUser($request);

        if ($search = $request->query('search')) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($campaign) => $this->campaignPayload($campaign, false, $user)),
            'filters' => [
                'categories' => Campaign::where('status', 'active')->distinct()->orderBy('category')->pluck('category'),
                'types' => Campaign::where('status', 'active')->distinct()->orderBy('type')->pluck('type'),
            ],
        ]);
    }

    public function campaign(Request $request, string $slug)
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();

        return response()->json($this->campaignPayload($campaign, true, $this->optionalUser($request)));
    }

    public function joinCampaign(Request $request, string $slug)
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();
        $data = $request->validate(['social_account_id' => ['nullable', 'exists:social_accounts,id']]);

        $user = $this->currentUser($request);
        $accountId = $this->validatedSocialAccountId($request, $user, $data['social_account_id'] ?? null);

        $submission = CampaignSubmission::firstOrCreate(
            ['campaign_id' => $campaign->id, 'user_id' => $user->id],
            ['social_account_id' => $accountId, 'status' => 'joined'],
        );

        $submission->update(['social_account_id' => $accountId]);

        return response()->json([
            'message' => 'Campaign berhasil diambil.',
            'submission' => $submission->refresh(),
            'campaign' => $this->campaignPayload($campaign, true, $user),
        ]);
    }

    public function submitCampaign(Request $request, string $slug)
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();
        $data = $request->validate([
            'video_url' => ['required', 'string', 'max:2048'],
            'social_account_id' => ['nullable', 'exists:social_accounts,id'],
        ]);

        $user = $this->currentUser($request);
        $accountId = $this->validatedSocialAccountId($request, $user, $data['social_account_id'] ?? null);

        $submission = CampaignSubmission::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'social_account_id' => $accountId,
            'video_url' => $data['video_url'],
            'status' => 'review',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Link video masuk peninjauan.',
            'submission' => $submission,
            'campaign' => $this->campaignPayload($campaign, true, $user),
        ]);
    }

    public function incomes(Request $request)
    {
        return response()->json($this->currentUser($request)->incomes()
            ->with('socialAccount')
            ->latest('earned_at')
            ->get()
            ->map(fn ($income) => [
                'id' => $income->id,
                'date' => $income->earned_at->translatedFormat('d M Y'),
                'source' => $income->source,
                'account' => $income->socialAccount?->handle,
                'amount' => $this->rupiah($income->amount),
                'amount_value' => $income->amount,
                'status' => Str::headline($income->status),
            ]));
    }

    public function incomeSummary(Request $request)
    {
        $user = $this->currentUser($request);
        $incomes = $user->incomes()->with('campaign')->get();
        $submissions = $user->submissions()->get();
        $validIncomes = $incomes->where('status', 'valid');

        $latestIncomeDate = $validIncomes->max('earned_at');
        $end = $latestIncomeDate && $latestIncomeDate->lt(now()->subDays(29))
            ? $latestIncomeDate->copy()->endOfDay()
            : now()->endOfDay();
        $start = $end->copy()->subDays(29)->startOfDay();

        $chart = collect(range(0, 29))->map(function (int $offset) use ($validIncomes, $start): array {
            $date = $start->copy()->addDays($offset);
            $amount = $validIncomes
                ->filter(fn ($income) => $income->earned_at->isSameDay($date))
                ->sum('amount');

            return [
                'label' => $date->format('d M'),
                'amount' => $amount,
                'amount_label' => $this->rupiah($amount),
            ];
        });

        return response()->json([
            'stats' => [
                [
                    'label' => 'Total Campaign',
                    'value' => $incomes->pluck('campaign_id')->filter()->unique()->count(),
                    'suffix' => 'Campaign',
                ],
                [
                    'label' => 'Total Video',
                    'value' => $submissions->whereNotNull('video_url')->count(),
                    'suffix' => 'Video',
                ],
                [
                    'label' => 'Total Approved',
                    'value' => $validIncomes->count(),
                    'suffix' => 'Videos',
                ],
            ],
            'total_income' => $this->rupiah($validIncomes->sum('amount')),
            'chart' => $chart,
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:50000']]);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $data['amount'],
            'bank_name' => $user->bank_name ?: 'BCA',
            'bank_account_number' => $user->bank_account_number ?: '1234567890',
            'bank_account_name' => $user->bank_account_name ?: $user->name,
            'requested_at' => now(),
        ]);

        return response()->json(['message' => 'Permintaan withdraw dibuat.', 'withdrawal' => $withdrawal], 201);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $this->currentUser($request);
        $user->update($data);

        return response()->json($this->userPayload($user->refresh()));
    }

    public function leaderboard()
    {
        return response()->json(User::query()
            ->withSum(['incomes as income_total' => fn ($query) => $query->where('status', 'valid')], 'amount')
            ->orderByDesc('income_total')
            ->take(20)
            ->get()
            ->map(fn ($user) => [
                'name' => $user->name,
                'handle' => $user->handle,
                'income' => $this->rupiah($user->income_total ?? 0),
                'income_value' => $user->income_total ?? 0,
            ]));
    }

    public function announcements()
    {
        return response()->json(Announcement::latest('published_at')->get());
    }

    public function courses()
    {
        return response()->json(Course::latest()->get());
    }

    public function adminCampaigns()
    {
        return response()->json(Campaign::query()
            ->withCount(['submissions as submissions_count' => fn ($query) => $query->whereNotNull('video_url')])
            ->latest()
            ->get()
            ->map(fn (Campaign $campaign) => [
                ...$this->campaignPayload($campaign),
                'status' => Str::headline($campaign->status),
                'submissions_count' => $campaign->submissions_count,
            ]));
    }

    public function createAdminCampaign(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'rate_per_view' => ['required', 'integer', 'min:0'],
            'category' => ['required', 'string', 'max:255'],
            'budget_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'views_target' => ['required', 'integer', 'min:0'],
            'type' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'deadline_at' => ['nullable', 'date'],
            'brief' => ['nullable', 'string'],
        ]);

        $baseSlug = Str::slug($data['title']);
        $slug = $baseSlug;
        $suffix = 2;

        while (Campaign::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        $campaign = Campaign::create([
            'slug' => $slug,
            'exclusive' => false,
            'assets' => [],
            'platforms' => ['TikTok', 'IG', 'YT'],
            ...$data,
        ]);

        return response()->json([
            'message' => 'Campaign dibuat.',
            'campaign' => $this->campaignPayload($campaign),
        ], 201);
    }

    public function adminCampaign(Campaign $campaign)
    {
        return response()->json([
            ...$this->campaignPayload($campaign, true),
            'status' => $campaign->status,
        ]);
    }

    public function updateAdminCampaign(Request $request, Campaign $campaign)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'brand' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:255'],
            'rate_per_view' => ['sometimes', 'integer', 'min:0'],
            'budget_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'views_target' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'max:255'],
        ]);

        $campaign->update($data);

        return response()->json(['message' => 'Campaign diperbarui.', 'campaign' => $this->campaignPayload($campaign->refresh())]);
    }

    public function deleteAdminCampaign(Campaign $campaign)
    {
        $campaign->delete();

        return response()->json(['message' => 'Campaign dihapus.']);
    }

    public function adminSubmissions()
    {
        return response()->json(CampaignSubmission::query()
            ->with(['campaign', 'user', 'socialAccount'])
            ->whereNotNull('video_url')
            ->latest('submitted_at')
            ->get()
            ->map(fn (CampaignSubmission $submission) => [
                'id' => $submission->id,
                'submitted_at' => $submission->submitted_at?->translatedFormat('d M Y H:i'),
                'caption' => $submission->campaign?->title,
                'campaign' => $submission->campaign?->title,
                'creator' => $submission->user?->name,
                'account' => $submission->socialAccount?->handle,
                'type' => $submission->campaign?->type,
                'status' => Str::headline($submission->status),
                'link' => $submission->video_url,
                'views' => number_format($submission->views, 0, ',', '.'),
                'estimated_payout' => $this->rupiah($submission->estimated_payout),
            ]));
    }

    public function updateAdminSubmission(Request $request, CampaignSubmission $submission)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:255'],
            'views' => ['sometimes', 'integer', 'min:0'],
            'estimated_payout' => ['sometimes', 'integer', 'min:0'],
        ]);

        $submission->update($data);

        return response()->json(['message' => 'Submission diperbarui.']);
    }

    public function deleteAdminSubmission(CampaignSubmission $submission)
    {
        $submission->delete();

        return response()->json(['message' => 'Submission dihapus.']);
    }

    public function adminCreators()
    {
        return response()->json(User::query()
            ->withCount('socialAccounts')
            ->withCount(['submissions as submissions_count' => fn ($query) => $query->whereNotNull('video_url')])
            ->withSum(['incomes as income_total' => fn ($query) => $query->where('status', 'valid')], 'amount')
            ->latest()
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'handle' => $user->handle,
                'role' => $user->role,
                'status' => Str::headline($user->status),
                'accounts_count' => $user->social_accounts_count,
                'submissions_count' => $user->submissions_count,
                'income' => $this->rupiah($user->income_total ?? 0),
                'income_value' => $user->income_total ?? 0,
            ]));
    }

    public function createAdminCreator(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'handle' => ['required', 'string', 'max:255', 'unique:users,handle'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'api_token' => Str::random(60),
        ]);

        return response()->json([
            'message' => 'Creator dibuat.',
            'creator' => $this->userPayload($user),
        ], 201);
    }

    public function inviteAdminCreator(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string'],
        ]);

        return response()->json([
            'message' => 'Undangan creator disiapkan.',
            'invite' => [
                'email' => $data['email'],
                'name' => $data['name'] ?? null,
                'message' => $data['message'] ?? null,
                'status' => 'pending',
            ],
        ], 201);
    }

    public function updateAdminCreator(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'max:255'],
        ]);

        $user->update($data);

        return response()->json(['message' => 'Creator diperbarui.', 'creator' => $this->userPayload($user->refresh())]);
    }

    public function deleteAdminCreator(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'Creator dihapus.']);
    }

    public function adminPayouts()
    {
        $incomes = Income::query()
            ->with(['user', 'socialAccount', 'campaign'])
            ->latest('earned_at')
            ->get()
            ->map(fn (Income $income) => [
                'id' => 'income-'.$income->id,
                'date' => $income->earned_at->translatedFormat('d M Y'),
                'source' => $income->source,
                'creator' => $income->user?->name,
                'account' => $income->socialAccount?->handle,
                'amount' => $this->rupiah($income->amount),
                'amount_value' => $income->amount,
                'status' => Str::headline($income->status),
                'type' => 'Income',
            ]);

        $withdrawals = Withdrawal::query()
            ->with('user')
            ->latest('requested_at')
            ->get()
            ->map(fn (Withdrawal $withdrawal) => [
                'id' => 'withdrawal-'.$withdrawal->id,
                'date' => $withdrawal->requested_at->translatedFormat('d M Y'),
                'source' => 'Withdraw Request',
                'creator' => $withdrawal->user?->name,
                'account' => $withdrawal->bank_name.' '.$withdrawal->bank_account_number,
                'amount' => $this->rupiah($withdrawal->amount),
                'amount_value' => $withdrawal->amount,
                'status' => Str::headline($withdrawal->status),
                'type' => 'Withdrawal',
            ]);

        return response()->json([
            'total_income' => $this->rupiah($incomes->where('status', 'Valid')->sum('amount_value')),
            'total_requested' => $this->rupiah($withdrawals->sum('amount_value')),
            'items' => $incomes->concat($withdrawals)->sortByDesc('date')->values(),
        ]);
    }

    public function updateAdminPayout(Request $request, string $payout)
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:255']]);
        [$type, $id] = explode('-', $payout, 2);

        if ($type === 'income') {
            Income::findOrFail($id)->update($data);
        } elseif ($type === 'withdrawal') {
            Withdrawal::findOrFail($id)->update($data);
        } else {
            abort(404);
        }

        return response()->json(['message' => 'Payout diperbarui.']);
    }

    public function deleteAdminPayout(string $payout)
    {
        [$type, $id] = explode('-', $payout, 2);

        if ($type === 'income') {
            Income::findOrFail($id)->delete();
        } elseif ($type === 'withdrawal') {
            Withdrawal::findOrFail($id)->delete();
        } else {
            abort(404);
        }

        return response()->json(['message' => 'Payout dihapus.']);
    }

    public function course(Course $course)
    {
        $videoUrl = 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';

        return response()->json([
            ...$course->toArray(),
            'lessons' => [
                ['title' => 'Pembukaan', 'duration' => '3 menit', 'video_url' => $videoUrl],
                ['title' => 'Contoh Praktik', 'duration' => '8 menit', 'video_url' => $videoUrl],
                ['title' => 'Checklist Upload', 'duration' => '5 menit', 'video_url' => $videoUrl],
            ],
            'resources' => ['Template script', 'Checklist approval', 'Caption starter pack'],
        ]);
    }

    public function contactAdmin(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        $message = AdminMessage::create(['user_id' => $this->currentUser($request)?->id, ...$data]);

        return response()->json(['message' => 'Pesan terkirim ke admin.', 'ticket' => $message], 201);
    }

    private function currentUser(Request $request): User
    {
        $token = $request->bearerToken() ?: $request->header('X-Api-Token');
        $user = $token ? User::where('api_token', $token)->first() : User::first();

        abort_if(! $user, 401, 'Unauthenticated.');

        return $user;
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken() ?: $request->header('X-Api-Token');

        return $token ? User::where('api_token', $token)->first() : null;
    }

    private function validatedSocialAccountId(Request $request, User $user, ?int $socialAccountId): ?int
    {
        if (! $socialAccountId) {
            return $user->socialAccounts()->value('id');
        }

        abort_unless(
            $user->socialAccounts()->whereKey($socialAccountId)->exists(),
            422,
            'Akun sosial tidak terhubung ke user ini.',
        );

        return $socialAccountId;
    }

    private function campaignQuery()
    {
        return Campaign::where('status', 'active')->orderByDesc('exclusive')->latest();
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'handle' => $user->handle,
            'role' => $user->role,
            'status' => $user->status,
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->bank_account_number,
            'bank_account_name' => $user->bank_account_name,
        ];
    }

    private function campaignPayload(Campaign $campaign, bool $detail = false, ?User $user = null): array
    {
        $submission = $user
            ? $campaign->submissions()->where('user_id', $user->id)->latest()->first()
            : null;
        $submissions = $user
            ? $campaign->submissions()
                ->with('socialAccount')
                ->where('user_id', $user->id)
                ->whereNotNull('video_url')
                ->latest('submitted_at')
                ->get()
            : collect();

        $payload = [
            'id' => $campaign->id,
            'slug' => $campaign->slug,
            'title' => $campaign->title,
            'brand' => $campaign->brand,
            'image' => $campaign->image_url,
            'rate' => $this->rupiah($campaign->rate_per_view),
            'rate_value' => $campaign->rate_per_view,
            'category' => $campaign->category,
            'budget' => $campaign->budget_percent,
            'deadline' => $campaign->deadline_at?->translatedFormat('d M Y'),
            'deadline_value' => $campaign->deadline_at?->toDateString(),
            'views' => number_format($campaign->views_target, 0, ',', '.'),
            'views_value' => $campaign->views_target,
            'type' => $campaign->type,
            'exclusive' => $campaign->exclusive,
            'joined' => (bool) $submission,
            'submission_status' => $submission?->status,
            'submitted_video_url' => $submission?->video_url,
            'submissions' => $submissions->map(fn (CampaignSubmission $submission) => [
                'id' => $submission->id,
                'video_url' => $submission->video_url,
                'status' => Str::headline($submission->status),
                'account' => $submission->socialAccount?->handle,
                'submitted_at' => $submission->submitted_at?->translatedFormat('d M Y H:i'),
            ]),
        ];

        if ($detail) {
            $payload += [
                'brief' => $campaign->brief,
                'assets' => $campaign->assets ?? [],
                'platforms' => $campaign->platforms ?? [],
            ];
        }

        return $payload;
    }

    private function rupiah(int|float $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }
}
