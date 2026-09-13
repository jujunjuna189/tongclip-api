<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminMessage;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignSubmission;
use App\Models\Course;
use App\Models\Income;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $handleIdentifier = ltrim($identifier, '@');
        $prefixedHandleIdentifier = "@{$handleIdentifier}";

        if (! $identifier) {
            throw ValidationException::withMessages(['identifier' => 'Email atau handle akun wajib diisi.']);
        }

        $loginViaSocialAccount = false;
        $socialAccount = null;
        $user = User::where('email', $identifier)
            ->orWhereIn('handle', [$identifier, $handleIdentifier, $prefixedHandleIdentifier])
            ->first();

        if (! $user) {
            $socialAccount = SocialAccount::where('email', $identifier)
                ->orWhereIn('handle', [$identifier, $handleIdentifier, $prefixedHandleIdentifier])
                ->first();
            $user = $socialAccount
                ?->users()
                ->wherePivot('status', 'active')
                ->first();
            $loginViaSocialAccount = (bool) $user;
        }

        $passwordValid = $user && (
            Hash::check($credentials['password'], $user->password)
            || ($loginViaSocialAccount && $credentials['password'] === 'password')
        );

        if (! $passwordValid) {
            throw ValidationException::withMessages(['identifier' => 'Email, handle, atau password tidak sesuai.']);
        }

        $user->forceFill(['api_token' => Str::random(60)])->save();

        return response()->json([
            'token' => $user->api_token,
            'user' => [
                ...$this->userPayload($user),
                'login_social_account_id' => $loginViaSocialAccount ? $socialAccount?->id : null,
            ],
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email', 'unique:social_accounts,email'],
            'password' => ['required', 'string', 'min:8'],
            'handle' => ['nullable', 'string', 'max:255'],
        ]);
        $handle = $data['handle'] ?? Str::before($data['email'], '@');
        $data['handle'] = '@'.ltrim($handle, '@');

        if (
            User::where('handle', $data['handle'])->exists()
            || SocialAccount::where('handle', $data['handle'])->exists()
        ) {
            throw ValidationException::withMessages(['handle' => 'Handle sudah digunakan.']);
        }

        $user = User::create([
            ...$data,
            'password' => Hash::make($data['password']),
            'status' => 'review',
            'role' => 'creator',
            'onboarding_completed' => false,
            'api_token' => Str::random(60),
        ]);
        $this->ensureMainSocialAccount($user);

        return response()->json(['token' => $user->api_token, 'user' => $this->userPayload($user)], 201);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($this->currentUser($request)));
    }

    public function dashboard(Request $request)
    {
        $user = $this->currentUser($request);
        $userAccountId = -$user->id;
        $accounts = $user->socialAccounts()
            ->wherePivot('status', 'active')
            ->latest()
            ->get();
        $primaryAccount = $this->primarySocialAccount($user);
        $selectedAccountId = (int) $request->query('social_account_id') ?: $userAccountId;

        if ($selectedAccountId > 0) {
            abort_unless(
                $accounts->contains('id', $selectedAccountId),
                422,
                'Akun sosial tidak terhubung ke user ini.',
            );
        }

        $incomeQuery = $user->incomes()->where('status', 'valid');
        $submissionQuery = $user->submissions()->whereNotNull('video_url');

        if ($selectedAccountId > 0) {
            $incomeQuery->where('social_account_id', $selectedAccountId);
            $submissionQuery->where('social_account_id', $selectedAccountId);
        }

        $validIncome = $incomeQuery->sum('amount');
        $withdrawnAmount = $user->withdrawals()
            ->whereIn('status', ['requested', 'approved', 'paid'])
            ->sum('amount');
        $withdrawableAmount = max(0, $validIncome - $withdrawnAmount);
        $videoCount = (clone $submissionQuery)->count();
        $questStart = now()->startOfDay()->subDays(6);
        $questEnd = now()->endOfDay();
        $questDates = (clone $submissionQuery)
            ->whereBetween('submitted_at', [$questStart, $questEnd])
            ->get()
            ->map(fn (CampaignSubmission $submission) => $submission->submitted_at?->toDateString())
            ->filter()
            ->unique()
            ->values();
        $questDays = collect(range(0, 6))->map(function (int $offset) use ($questStart, $questDates) {
            $date = $questStart->copy()->addDays($offset);

            return [
                'day' => $offset + 1,
                'date' => $date->toDateString(),
                'label' => $date->translatedFormat('d M'),
                'completed' => $questDates->contains($date->toDateString()),
                'is_today' => $date->isToday(),
            ];
        });
        $questCompletedDays = $questDays->where('completed', true)->count();

        return response()->json([
            'stats' => [
                ['label' => 'Total Pendapatan', 'value' => $this->rupiah($validIncome)],
                ['label' => 'Bisa Dicairkan', 'value' => $this->rupiah($withdrawableAmount)],
                ['label' => 'Total Video', 'value' => $videoCount.' Video'],
            ],
            'daily_quest' => [
                'reward' => $this->rupiah(15000),
                'completed_days' => $questCompletedDays,
                'target_days' => 7,
                'remaining_days' => max(0, 7 - $questCompletedDays),
                'today_completed' => $questDays->firstWhere('is_today', true)['completed'] ?? false,
                'claimed_count' => 1000,
                'days' => $questDays->values(),
            ],
            'selected_account_id' => $selectedAccountId,
            'accounts' => collect([[
                'id' => $userAccountId,
                'name' => $user->name,
                'handle' => $user->handle,
                'platform' => 'user',
                'status' => Str::headline($user->status),
                'balance' => $this->rupiah($validIncome),
                'balance_value' => $validIncome,
                'avatar_url' => $this->publicAssetUrl($primaryAccount?->avatar_url),
                'bank_name' => $primaryAccount?->bank_name,
                'bank_account_number' => $primaryAccount?->bank_account_number,
                'bank_account_name' => $primaryAccount?->bank_account_name,
                'type' => 'user',
                'access_type' => 'owner',
            ]])->merge($accounts->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'handle' => $account->handle,
                'platform' => $account->platform,
                'status' => Str::headline($account->status),
                'balance' => $this->rupiah($account->balance),
                'balance_value' => $account->balance,
                'avatar_url' => $this->publicAssetUrl($account->avatar_url),
                'bank_name' => $account->bank_name,
                'bank_account_number' => $account->bank_account_number,
                'bank_account_name' => $account->bank_account_name,
                'type' => 'social_account',
                'access_type' => $account->pivot?->access_type ?: 'member',
            ]))->values(),
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
        $account = $accountId ? SocialAccount::find($accountId) : null;

        $this->notifyAdmins(
            'submission_review',
            'Submission baru masuk',
            "{$user->name} mengirim video untuk {$campaign->title}.",
            [
                'submission_id' => $submission->id,
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'social_account_id' => $account?->id,
            ],
        );

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

    public function withdrawals(Request $request)
    {
        $user = $this->currentUser($request);
        $accountId = $this->validatedSocialAccountId($request, $user, $request->query('social_account_id'));
        $account = $accountId ? SocialAccount::find($accountId) : null;

        return response()->json($user->withdrawals()
            ->when($accountId, function ($query) use ($accountId, $account): void {
                $query->where(function ($builder) use ($accountId, $account): void {
                    $builder->where('social_account_id', $accountId);

                    if ($account) {
                        $builder->orWhere(function ($legacyQuery) use ($account): void {
                            $legacyQuery
                                ->whereNull('social_account_id')
                                ->where('bank_name', $account->bank_name)
                                ->where('bank_account_number', $account->bank_account_number)
                                ->where('bank_account_name', $account->bank_account_name);
                        });
                    }
                });
            })
            ->latest('requested_at')
            ->get()
            ->map(fn (Withdrawal $withdrawal) => [
                'id' => $withdrawal->id,
                'social_account_id' => $withdrawal->social_account_id,
                'date' => $withdrawal->requested_at?->translatedFormat('d M Y H:i'),
                'amount' => $this->rupiah($withdrawal->amount),
                'amount_value' => $withdrawal->amount,
                'bank_name' => $withdrawal->bank_name,
                'bank_account_number' => $withdrawal->bank_account_number,
                'bank_account_name' => $withdrawal->bank_account_name,
                'status' => Str::headline($withdrawal->status),
            ]));
    }

    public function requestWithdrawal(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'social_account_id' => ['nullable', 'exists:social_accounts,id'],
        ]);

        // Hanya bisa diajukan tanggal 15 atau 16
        $today = now()->day;
        if (!in_array($today, [15, 16])) {
            throw ValidationException::withMessages([
                'amount' => 'Pencairan hanya bisa diajukan pada tanggal 15 atau 16 setiap bulan.',
            ]);
        }
        $accountId = $this->validatedSocialAccountId($request, $user, $data['social_account_id'] ?? null);
        $account = $accountId ? SocialAccount::find($accountId) : $this->primarySocialAccount($user);
        $validIncome = $user->incomes()
            ->where('status', 'valid')
            ->when($accountId, fn ($query) => $query->where('social_account_id', $accountId))
            ->sum('amount');
        $withdrawnAmount = $user->withdrawals()
            ->whereIn('status', ['requested', 'approved', 'paid'])
            ->when($accountId, fn ($query) => $query->where('social_account_id', $accountId))
            ->sum('amount');
        $withdrawableAmount = max(0, $validIncome - $withdrawnAmount);

        if ($data['amount'] > $withdrawableAmount) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal withdraw melebihi saldo yang bisa dicairkan.',
            ]);
        }

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'social_account_id' => $account?->id,
            'amount' => $data['amount'],
            'bank_name' => $account?->bank_name ?: 'BCA',
            'bank_account_number' => $account?->bank_account_number ?: '1234567890',
            'bank_account_name' => $account?->bank_account_name ?: $user->name,
            'requested_at' => now(),
        ]);
        $this->notifyAdmins(
            'withdrawal_request',
            'Pengajuan pencairan baru',
            "{$user->name} mengajukan pencairan {$this->rupiah($withdrawal->amount)}.",
            [
                'withdrawal_id' => $withdrawal->id,
                'user_id' => $user->id,
                'social_account_id' => $account?->id,
            ],
        );

        return response()->json(['message' => 'Permintaan withdraw dibuat.', 'withdrawal' => $withdrawal], 201);
    }

    public function createSocialAccount(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email', 'unique:social_accounts,email'],
            'handle' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $data['handle'] = '@'.ltrim($data['handle'], '@');
        unset($data['avatar']);

        if (
            User::where('handle', $data['handle'])->exists()
            || SocialAccount::where('handle', $data['handle'])->exists()
        ) {
            throw ValidationException::withMessages(['handle' => 'Handle sudah digunakan.']);
        }

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar_url'] = $this->publicStoragePath($path);
        }

        $account = SocialAccount::create([
            ...$data,
            'status' => 'active',
            'balance' => 0,
        ]);

        $user->socialAccounts()->attach($account->id, [
            'access_type' => 'member',
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Social account ditambahkan.',
            'account' => $account,
        ], 201);
    }

    public function updateProfile(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'image', 'max:2048'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_account_id' => ['sometimes', 'nullable', 'exists:social_accounts,id'],
        ]);

        if (array_key_exists('handle', $data) && $data['handle']) {
            $data['handle'] = '@'.ltrim($data['handle'], '@');

            if (
                User::where('handle', $data['handle'])->whereKeyNot($user->id)->exists()
                || SocialAccount::where('handle', $data['handle'])
                    ->whereDoesntHave('users', fn ($query) => $query->whereKey($user->id))
                    ->exists()
            ) {
                throw ValidationException::withMessages(['handle' => 'Handle sudah digunakan.']);
            }
        }

        $selectedAccountId = $this->validatedSocialAccountId($request, $user, $data['social_account_id'] ?? null);
        $primaryAccount = $selectedAccountId ? SocialAccount::find($selectedAccountId) : $this->primarySocialAccount($user);
        $oldHandle = $user->handle;
        $accountData = collect($data)->only(['avatar_url', 'bank_name', 'bank_account_number', 'bank_account_name'])->all();
        $userData = collect($data)->except(['avatar_url', 'avatar', 'bank_name', 'bank_account_number', 'bank_account_name', 'social_account_id'])->all();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $accountData['avatar_url'] = $this->publicStoragePath($path);
        }

        if ($userData) {
            $user->update($userData);
        }

        if ($primaryAccount && ($accountData || ($data['handle'] ?? null))) {
            if (($data['handle'] ?? null) && $primaryAccount->handle === $oldHandle) {
                $accountData['handle'] = $data['handle'];
                $accountData['email'] = $user->email;
            }

            $primaryAccount->update($accountData);
        }

        return response()->json($this->userPayload($user->refresh()));
    }

    public function leaderboard(Request $request)
    {
        $user = $this->currentUser($request);
        $socialAccountIds = $user->socialAccounts()
            ->wherePivot('status', 'active')
            ->pluck('social_accounts.id');

        return response()->json(SocialAccount::query()
            ->whereIn('id', $socialAccountIds)
            ->withSum(['incomes as income_total' => fn ($query) => $query->where('status', 'valid')], 'amount')
            ->orderByDesc('income_total')
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'handle' => $account->handle,
                'platform' => $account->platform,
                'income' => $this->rupiah($account->income_total ?? 0),
                'income_value' => (int) ($account->income_total ?? 0),
            ]));
    }

    public function announcements()
    {
        return response()->json(Announcement::latest('published_at')->get());
    }

    public function courses()
    {
        return response()->json(Course::latest()->get()->map(fn (Course $course) => $this->coursePayload($course)));
    }

    public function adminCourses()
    {
        return response()->json(Course::latest()->get()->map(fn (Course $course) => $this->coursePayload($course)));
    }

    public function createAdminCourse(Request $request)
    {
        $data = $this->validateCourse($request);
        $this->storeCourseImage($request, $data);

        return response()->json([
            'message' => 'Course dibuat.',
            'course' => $this->coursePayload(Course::create($data)),
        ], 201);
    }

    public function updateAdminCourse(Request $request, Course $course)
    {
        $data = $this->validateCourse($request, true);
        $this->storeCourseImage($request, $data);
        $course->update($data);

        return response()->json([
            'message' => 'Course diperbarui.',
            'course' => $this->coursePayload($course->refresh()),
        ]);
    }

    public function deleteAdminCourse(Course $course)
    {
        $course->delete();

        return response()->json(['message' => 'Course dihapus.']);
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
            'hero_image' => ['nullable', 'image', 'max:4096'],
            'rate_per_view' => ['required', 'integer', 'min:0'],
            'category' => ['required', 'string', 'max:255'],
            'budget_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'views_target' => ['required', 'integer', 'min:0'],
            'type' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'exclusive' => ['nullable', 'boolean'],
            'deadline_at' => ['nullable', 'date'],
            'brief' => ['nullable', 'string'],
            'rules' => ['nullable', 'array'],
            'rules.*' => ['string', 'max:1000'],
            'assets' => ['nullable', 'array'],
            'assets.*.title' => ['nullable', 'string', 'max:255'],
            'assets.*.url' => ['nullable', 'string', 'max:2048'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:255'],
        ]);

        $baseSlug = Str::slug($data['title']);
        $slug = $baseSlug;
        $suffix = 2;

        while (Campaign::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        if ($request->hasFile('hero_image')) {
            $data['image_url'] = $this->publicStoragePath($request->file('hero_image')->store('campaigns', 'public'));
        }

        unset($data['hero_image']);

        $campaign = Campaign::create([
            ...$data,
            'slug' => $slug,
            'image_url' => $data['image_url'] ?? null,
            'exclusive' => $data['exclusive'] ?? false,
            'rules' => $data['rules'] ?? [],
            'assets' => $data['assets'] ?? [],
            'platforms' => $data['platforms'] ?? ['TikTok', 'IG', 'YT'],
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
            'exclusive' => ['sometimes', 'boolean'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'hero_image' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'brief' => ['sometimes', 'nullable', 'string'],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.*' => ['string', 'max:1000'],
            'assets' => ['sometimes', 'nullable', 'array'],
            'assets.*.title' => ['nullable', 'string', 'max:255'],
            'assets.*.url' => ['nullable', 'string', 'max:2048'],
            'platforms' => ['sometimes', 'nullable', 'array'],
            'platforms.*' => ['string', 'max:255'],
        ]);

        if ($request->hasFile('hero_image')) {
            $data['image_url'] = $this->publicStoragePath($request->file('hero_image')->store('campaigns', 'public'));
        }

        unset($data['hero_image']);

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
                'campaign_brand' => $submission->campaign?->brand,
                'campaign_category' => $submission->campaign?->category,
                'campaign_rate' => $this->rupiah($submission->campaign?->rate_per_view ?? 0),
                'campaign_rate_value' => $submission->campaign?->rate_per_view ?? 0,
                'campaign_deadline' => $submission->campaign?->deadline_at?->translatedFormat('d M Y'),
                'campaign_type' => $submission->campaign?->type,
                'creator' => $submission->user?->name,
                'account' => $submission->socialAccount?->handle,
                'type' => $submission->campaign?->type,
                'status' => Str::headline($submission->status),
                'reviewed_at' => $submission->updated_at?->toIso8601String(),
                'link' => $submission->video_url,
                'views' => number_format($submission->views, 0, ',', '.'),
                'views_value' => $submission->views,
                'estimated_payout' => $this->rupiah($submission->estimated_payout),
                'estimated_payout_value' => $submission->estimated_payout,
            ]));
    }

    public function updateAdminSubmission(Request $request, CampaignSubmission $submission)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:255'],
            'views' => ['sometimes', 'integer', 'min:0'],
            'estimated_payout' => ['sometimes', 'integer', 'min:0'],
        ]);

        $oldStatus = strtolower($submission->status);
        $newStatus = strtolower($data['status']);

        $submission->update($data);

        // Buat income dan update balance saat di-approve
        if ($newStatus === 'approved' && $oldStatus !== 'approved') {
            $amount = $submission->estimated_payout ?? 0;

            Income::create([
                'user_id' => $submission->user_id,
                'campaign_id' => $submission->campaign_id,
                'social_account_id' => $submission->social_account_id,
                'earned_at' => now()->toDateString(),
                'source' => ($submission->campaign?->title ?? 'Campaign') . ' #' . $submission->id,
                'amount' => $amount,
                'status' => 'valid',
            ]);

            if ($submission->social_account_id) {
                SocialAccount::where('id', $submission->social_account_id)
                    ->increment('balance', $amount);
            }
        }

        // Hapus income dan rollback balance kalau di-reject / dikembalikan dari approved
        if ($oldStatus === 'approved' && $newStatus !== 'approved') {
            $income = Income::where('user_id', $submission->user_id)
                ->where('campaign_id', $submission->campaign_id)
                ->where('social_account_id', $submission->social_account_id)
                ->where('source', 'like', '%#' . $submission->id)
                ->first();

            if ($income) {
                if ($submission->social_account_id) {
                    SocialAccount::where('id', $submission->social_account_id)
                        ->decrement('balance', $income->amount);
                }
                $income->delete();
            }
        }

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
            ->where('role', 'creator')
            ->withCount('socialAccounts')
            ->withCount(['submissions as submissions_count' => fn ($query) => $query->whereNotNull('video_url')])
            ->withSum(['incomes as income_total' => fn ($query) => $query->where('status', 'valid')], 'amount')
            ->with(['socialAccounts' => fn ($query) => $query->orderBy('social_account_user.id')])
            ->latest()
            ->get()
            ->map(function (User $user) {
                $primaryAccount = $user->socialAccounts->first();

                return [
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
                    'avatar_url' => $this->publicAssetUrl($primaryAccount?->avatar_url),
                    'bank_name' => $primaryAccount?->bank_name,
                    'bank_account_number' => $primaryAccount?->bank_account_number,
                    'bank_account_name' => $primaryAccount?->bank_account_name,
                ];
            }));
    }

    public function createAdminCreator(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email', 'unique:social_accounts,email'],
            'handle' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'password' => ['required', 'string', 'min:8'],
            'status' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $accountData = collect($data)->only(['bank_name', 'bank_account_number', 'bank_account_name'])->all();
        $userData = collect($data)->except(['avatar', 'bank_name', 'bank_account_number', 'bank_account_name'])->all();
        $userData['handle'] = '@'.ltrim($userData['handle'], '@');

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $accountData['avatar_url'] = $this->publicStoragePath($path);
        }

        if (
            User::where('handle', $userData['handle'])->exists()
            || SocialAccount::where('handle', $userData['handle'])->exists()
        ) {
            throw ValidationException::withMessages(['handle' => 'Handle sudah digunakan.']);
        }

        $user = User::create([
            ...$userData,
            'role' => 'creator',
            'onboarding_completed' => true,
            'password' => Hash::make($data['password']),
            'api_token' => Str::random(60),
        ]);
        $account = SocialAccount::updateOrCreate(
            ['handle' => $user->handle],
            [
                'name' => "{$user->name} Main",
                'email' => $user->email,
                'platform' => 'tiktok',
                'status' => $user->status,
                'balance' => 0,
                ...$accountData,
            ],
        );
        $user->socialAccounts()->syncWithoutDetaching([
            $account->id => ['access_type' => 'owner', 'status' => 'active'],
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
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id), Rule::unique('social_accounts', 'email')->where(fn ($query) => $query->whereNotIn('id', $user->socialAccounts()->pluck('social_accounts.id')))],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:2048'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'status' => ['sometimes', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('handle', $data) && $data['handle']) {
            $data['handle'] = '@'.ltrim($data['handle'], '@');

            if (
                User::where('handle', $data['handle'])->whereKeyNot($user->id)->exists()
                || SocialAccount::where('handle', $data['handle'])
                    ->whereDoesntHave('users', fn ($query) => $query->whereKey($user->id))
                    ->exists()
            ) {
                throw ValidationException::withMessages(['handle' => 'Handle sudah digunakan.']);
            }
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $accountData = collect($data)->only(['bank_name', 'bank_account_number', 'bank_account_name'])->all();
        $userData = collect($data)->except(['avatar', 'bank_name', 'bank_account_number', 'bank_account_name'])->all();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $accountData['avatar_url'] = $this->publicStoragePath($path);
        }

        $user->update($userData);

        $primaryAccount = $this->primarySocialAccount($user);
        if ($primaryAccount && $accountData) {
            $primaryAccount->update($accountData);
        }

        return response()->json(['message' => 'Creator diperbarui.', 'creator' => $this->userPayload($user->refresh())]);
    }

    public function deleteAdminCreator(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'Creator dihapus.']);
    }

    public function adminCreatorHistory(User $user)
    {
        return response()->json([
            'creator' => [
                'id' => $user->id,
                'name' => $user->name,
                'handle' => $user->handle,
                'email' => $user->email,
            ],
            'incomes' => $user->incomes()
                ->with(['socialAccount', 'campaign'])
                ->latest('earned_at')
                ->get()
                ->map(fn (Income $income) => [
                    'id' => $income->id,
                    'date' => $income->earned_at?->translatedFormat('d M Y'),
                    'source' => $income->source,
                    'campaign' => $income->campaign?->title,
                    'account' => $income->socialAccount?->handle,
                    'amount' => $this->rupiah($income->amount),
                    'amount_value' => $income->amount,
                    'status' => Str::headline($income->status),
                ]),
            'withdrawals' => $user->withdrawals()
                ->with('socialAccount')
                ->latest('requested_at')
                ->get()
                ->map(fn (Withdrawal $withdrawal) => [
                    'id' => $withdrawal->id,
                    'date' => $withdrawal->requested_at?->translatedFormat('d M Y H:i'),
                    'account' => $withdrawal->bank_name.' '.$withdrawal->bank_account_number,
                    'social_account' => $withdrawal->socialAccount?->handle,
                    'amount' => $this->rupiah($withdrawal->amount),
                    'amount_value' => $withdrawal->amount,
                    'status' => Str::headline($withdrawal->status),
                ]),
        ]);
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
            ->with(['user', 'socialAccount'])
            ->latest('requested_at')
            ->get()
            ->map(fn (Withdrawal $withdrawal) => [
                'id' => 'withdrawal-'.$withdrawal->id,
                'date' => $withdrawal->requested_at->translatedFormat('d M Y'),
                'source' => 'Withdraw Request',
                'creator' => $withdrawal->user?->name,
                'account' => $withdrawal->bank_name.' '.$withdrawal->bank_account_number,
                'social_account' => $withdrawal->socialAccount?->handle,
                'amount' => $this->rupiah($withdrawal->amount),
                'amount_value' => $withdrawal->amount,
                'status' => Str::headline($withdrawal->status),
                'reviewed_at' => $withdrawal->updated_at?->toIso8601String(),
                'type' => 'Withdrawal',
            ]);

        $totalIncome = $incomes->where('status', 'Valid')->sum('amount_value');
        $totalProcessedWithdrawals = $withdrawals
            ->whereIn('status', ['Requested', 'Approved', 'Paid'])
            ->sum('amount_value');
        $totalPaidWithdrawals = $withdrawals
            ->where('status', 'Paid')
            ->sum('amount_value');

        return response()->json([
            'total_income' => $this->rupiah($totalIncome),
            'total_requested' => $this->rupiah($totalPaidWithdrawals),
            'total_remaining' => $this->rupiah(max(0, $totalIncome - $totalProcessedWithdrawals)),
            'items' => $incomes->concat($withdrawals)->sortByDesc('date')->values(),
        ]);
    }

    public function updateAdminPayout(Request $request, string $payout)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['requested', 'approved', 'rejected', 'paid'])]]);
        [$type, $id] = explode('-', $payout, 2);

        if ($type === 'income') {
            Income::findOrFail($id)->update($data);
        } elseif ($type === 'withdrawal') {
            $withdrawal = Withdrawal::findOrFail($id);
            $oldStatus = strtolower($withdrawal->status);
            $newStatus = strtolower($data['status']);

            abort_if($oldStatus === 'paid', 422, 'Pengajuan yang sudah selesai tidak bisa diubah.');
            abort_if($newStatus === 'paid' && $oldStatus !== 'approved', 422, 'Pengajuan harus disetujui sebelum diselesaikan.');
            abort_if(in_array($newStatus, ['approved', 'rejected'], true) && $oldStatus !== 'requested', 422, 'Pengajuan ini sudah direview.');
            abort_if($newStatus === 'requested' && ! in_array($oldStatus, ['approved', 'rejected'], true), 422, 'Status ini tidak bisa dibatalkan.');
            abort_if(
                $newStatus === 'requested' && $withdrawal->updated_at?->lt(now()->subDay()),
                422,
                'Pengajuan hanya bisa dibatalkan dalam 24 jam setelah review.',
            );

            if ($newStatus === 'approved' && $oldStatus !== 'approved') {
                $account = $withdrawal->socialAccount ?: $this->withdrawalSocialAccount($withdrawal);

                if ($account) {
                    $account->update([
                        'balance' => max(0, $account->balance - $withdrawal->amount),
                    ]);
                    $withdrawal->social_account_id = $account->id;
                }
            }

            if ($oldStatus === 'approved' && $newStatus === 'requested') {
                $account = $withdrawal->socialAccount ?: $this->withdrawalSocialAccount($withdrawal);

                if ($account) {
                    $account->increment('balance', $withdrawal->amount);
                }
            }

            $withdrawal->update($data);
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
            $withdrawal = Withdrawal::findOrFail($id);

            abort_if(strtolower($withdrawal->status) === 'paid', 422, 'Pengajuan yang sudah selesai tidak bisa dihapus.');

            $withdrawal->delete();
        } else {
            abort(404);
        }

        return response()->json(['message' => 'Payout dihapus.']);
    }

    public function course(Course $course)
    {
        return response()->json([
            ...$this->coursePayload($course),
            'lessons' => $course->lessons ?: [[
                'title' => $course->title,
                'duration' => $course->duration,
                'video_url' => $course->url,
            ]],
            'resources' => $course->resources ?: [],
        ]);
    }

    public function contactAdmin(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        $user = $this->currentUser($request);
        $message = AdminMessage::create(['user_id' => $user->id, ...$data, 'status' => 'processing']);

        $this->notifyAdmins(
            'support_ticket',
            'Log tiket baru',
            "{$user->name} mengirim tiket: {$message->subject}.",
            [
                'ticket_id' => $message->id,
                'user_id' => $user->id,
            ],
        );

        return response()->json(['message' => 'Pesan terkirim ke admin.', 'ticket' => $message], 201);
    }

    public function contactTickets(Request $request)
    {
        return response()->json($this->currentUser($request)
            ->adminMessages()
            ->latest()
            ->get()
            ->map(fn (AdminMessage $message) => $this->ticketPayload($message)));
    }

    public function adminTickets()
    {
        return response()->json(AdminMessage::with('user')
            ->latest()
            ->get()
            ->map(fn (AdminMessage $message) => $this->ticketPayload($message, true)));
    }

    public function updateAdminTicket(Request $request, AdminMessage $message)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['processing', 'resolved'])],
        ]);

        $message->update($data);

        return response()->json([
            'message' => 'Status tiket diperbarui.',
            'ticket' => $this->ticketPayload($message->refresh(), true),
        ]);
    }

    public function notifications(Request $request)
    {
        $user = $this->currentUser($request);
        $notifications = $user->notifications()
            ->latest()
            ->take(20)
            ->get()
            ->map(fn (AppNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'items' => $notifications,
            'unread_count' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    public function markNotificationsRead(Request $request)
    {
        $this->currentUser($request)
            ->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    public function markNotificationRead(Request $request, AppNotification $notification)
    {
        abort_unless($notification->user_id === $this->currentUser($request)->id, 404);

        $notification->update(['read_at' => $notification->read_at ?: now()]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }

    public function joinBrand(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:255'],
        ]);
        $handle = '@'.ltrim(str_replace('\\@', '@', trim($data['handle'])), '@');
        $brand = Brand::where('handle', $handle)->firstOrFail();

        if ($brand->user_id === $user->id) {
            return response()->json(['message' => 'Kamu adalah owner brand ini.']);
        }

        $existing = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->first();

        if ($existing?->status === 'active') {
            return response()->json(['message' => 'Kamu sudah terhubung ke brand ini.', 'brand' => $brand]);
        }

        if ($existing?->status === 'pending') {
            return response()->json([
                'message' => 'Permintaan join brand sudah dikirim.',
                'brand' => $brand,
                'request_status' => 'pending',
            ]);
        }

        $brand->members()->syncWithoutDetaching([
            $user->id => [
                'access_type' => $existing?->access_type ?: 'member',
                'status' => 'pending',
            ],
        ]);
        $this->notifyUser(
            $brand->owner,
            'brand_join_request',
            'Request join brand baru',
            "{$user->name} ingin bergabung ke {$brand->name}.",
            ['brand_id' => $brand->id, 'user_id' => $user->id],
        );

        return response()->json([
            'message' => 'Permintaan join brand dikirim.',
            'brand' => $brand,
            'request_status' => 'pending',
        ], 201);
    }

    public function onboarding(Request $request)
    {
        $user = $this->currentUser($request);
        $brands = Brand::where('status', 'active')->orderBy('name')->get();
        $activeBrand = $user->brands()->wherePivot('status', 'active')->first();

        if (! $activeBrand && $brands->count() === 1) {
            $activeBrand = $brands->first();
            $activeBrand->members()->syncWithoutDetaching([
                $user->id => ['access_type' => 'member', 'status' => 'active'],
            ]);
        }

        $ownerQuery = User::query()
            ->whereKeyNot($user->id)
            ->where('role', 'creator')
            ->where('status', 'active');

        if ($activeBrand) {
            $ownerQuery->whereHas('brands', fn ($query) => $query
                ->whereKey($activeBrand->id)
                ->where('brand_user.status', 'active'));
        } else {
            $ownerQuery->whereRaw('1 = 0');
        }

        return response()->json([
            'user' => $this->userPayload($user->refresh()),
            'brands' => $brands->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'handle' => $brand->handle,
            ])->values(),
            'active_brand' => $activeBrand ? [
                'id' => $activeBrand->id,
                'name' => $activeBrand->name,
                'handle' => $activeBrand->handle,
            ] : null,
            'owners' => $ownerQuery
                ->orderBy('name')
                ->get()
                ->map(fn (User $owner) => [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'handle' => $owner->handle,
                ])
                ->values(),
        ]);
    }

    public function joinOnboardingBrand(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'brand_id' => ['nullable', 'exists:brands,id'],
        ]);
        $brand = isset($data['brand_id'])
            ? Brand::where('status', 'active')->findOrFail($data['brand_id'])
            : Brand::where('status', 'active')->firstOrFail();

        $brand->members()->syncWithoutDetaching([
            $user->id => ['access_type' => 'member', 'status' => 'active'],
        ]);

        return response()->json([
            'message' => 'Brand terhubung.',
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'handle' => $brand->handle,
            ],
        ]);
    }

    public function completeOnboarding(Request $request)
    {
        $user = $this->currentUser($request);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['owner', 'member'])],
            'owner_user_id' => ['required_if:mode,member', 'nullable', 'exists:users,id'],
        ]);
        $brandIds = $user->brands()->wherePivot('status', 'active')->pluck('brands.id');

        abort_if($brandIds->isEmpty(), 422, 'Pilih brand dulu sebelum lanjut.');

        if ($data['mode'] === 'owner') {
            $user->forceFill([
                'status' => 'active',
                'onboarding_completed' => true,
            ])->save();

            return response()->json([
                'message' => 'Onboarding selesai.',
                'user' => $this->userPayload($user->refresh()),
            ]);
        }

        $owner = User::query()
            ->whereKey($data['owner_user_id'])
            ->where('role', 'creator')
            ->where('status', 'active')
            ->whereHas('brands', fn ($query) => $query
                ->whereIn('brands.id', $brandIds)
                ->where('brand_user.status', 'active'))
            ->firstOrFail();
        $account = $this->ensureMainSocialAccount($user);

        DB::transaction(function () use ($user, $owner, $account): void {
            $owner->socialAccounts()->syncWithoutDetaching([
                $account->id => ['access_type' => 'member', 'status' => 'active'],
            ]);

            $user->delete();
        });
        $this->notifyUser(
            $owner,
            'creator_member_joined',
            'Akun member baru terhubung',
            "{$account->name} ({$account->handle}) masuk ke akun kamu.",
            ['social_account_id' => $account->id],
        );

        return response()->json([
            'message' => 'Akun dipindahkan menjadi social account member.',
            'logout' => true,
            'credentials' => [
                'identifier' => $account->handle,
                'password' => 'password',
            ],
        ]);
    }

    public function brandRequests(Request $request)
    {
        $owner = $this->currentUser($request);
        $ownedBrandIds = $owner->ownedBrands()->pluck('id');

        return response()->json(DB::table('brand_user')
            ->join('users', 'users.id', '=', 'brand_user.user_id')
            ->join('brands', 'brands.id', '=', 'brand_user.brand_id')
            ->where('brand_user.status', 'pending')
            ->whereIn('brands.id', $ownedBrandIds)
            ->orderByDesc('brand_user.updated_at')
            ->get([
                'brands.id as brand_id',
                'brands.name as brand_name',
                'brands.handle as brand_handle',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.handle as user_handle',
                'brand_user.access_type',
                'brand_user.status',
                'brand_user.updated_at',
            ]));
    }

    public function updateBrandRequest(Request $request, Brand $brand, User $user)
    {
        $owner = $this->currentUser($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'rejected'])],
        ]);

        abort_unless($brand->user_id === $owner->id, 403, 'Kamu bukan owner brand ini.');

        abort_unless(
            $brand->members()
                ->wherePivot('status', 'pending')
                ->whereKey($user->id)
                ->exists(),
            404,
            'Permintaan join brand tidak ditemukan.',
        );

        $brand->members()->updateExistingPivot($user->id, [
            'status' => $data['status'],
        ]);
        $this->notifyUser(
            $user,
            'brand_join_'.$data['status'],
            $data['status'] === 'active' ? 'Request join disetujui' : 'Request join ditolak',
            $data['status'] === 'active'
                ? "Kamu sudah terhubung ke {$brand->name}."
                : "Request join kamu ke {$brand->name} ditolak.",
            ['brand_id' => $brand->id],
        );

        return response()->json(['message' => $data['status'] === 'active' ? 'Permintaan disetujui.' : 'Permintaan ditolak.']);
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
            return $user->socialAccounts()->wherePivot('status', 'active')->value('social_accounts.id');
        }

        abort_unless(
            $user->socialAccounts()->wherePivot('status', 'active')->whereKey($socialAccountId)->exists(),
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
        $account = $this->primarySocialAccount($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'handle' => $user->handle,
            'role' => $user->role,
            'status' => $user->status,
            'onboarding_completed' => $user->onboarding_completed,
            'avatar_url' => $this->publicAssetUrl($account?->avatar_url),
            'bank_name' => $account?->bank_name,
            'bank_account_number' => $account?->bank_account_number,
            'bank_account_name' => $account?->bank_account_name,
        ];
    }

    private function ticketPayload(AdminMessage $message, bool $includeUser = false): array
    {
        return [
            'id' => $message->id,
            'subject' => $message->subject,
            'message' => $message->message,
            'status' => $message->status === 'resolved' ? 'resolved' : 'processing',
            'status_label' => $message->status === 'resolved' ? 'Terselesaikan' : 'Sedang Diproses',
            'created_at' => $message->created_at?->translatedFormat('d M Y H:i'),
            'creator' => $includeUser ? $message->user?->name : null,
            'creator_handle' => $includeUser ? $message->user?->handle : null,
            'creator_email' => $includeUser ? $message->user?->email : null,
        ];
    }

    private function primarySocialAccount(User $user): ?SocialAccount
    {
        return $user->socialAccounts()
            ->wherePivot('status', 'active')
            ->orderByRaw('social_accounts.handle = ? desc', [$user->handle])
            ->orderBy('social_account_user.id')
            ->first();
    }

    private function withdrawalSocialAccount(Withdrawal $withdrawal): ?SocialAccount
    {
        return $withdrawal->user?->socialAccounts()
            ->wherePivot('status', 'active')
            ->where('bank_name', $withdrawal->bank_name)
            ->where('bank_account_number', $withdrawal->bank_account_number)
            ->where('bank_account_name', $withdrawal->bank_account_name)
            ->first();
    }

    private function ensureMainSocialAccount(User $user): SocialAccount
    {
        $socialAccount = SocialAccount::firstOrCreate(
            ['handle' => $user->handle],
            [
                'name' => "{$user->name} Main",
                'email' => $user->email,
                'platform' => 'tiktok',
                'status' => 'active',
                'balance' => 0,
            ],
        );
        $socialAccount->fill([
            'email' => $socialAccount->email ?: $user->email,
        ])->save();

        $user->socialAccounts()->syncWithoutDetaching([
            $socialAccount->id => ['access_type' => 'member', 'status' => 'active'],
        ]);

        return $socialAccount;
    }

    private function notifyUser(?User $user, string $type, string $title, ?string $body = null, array $data = []): void
    {
        if (! $user) {
            return;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    private function notifyAdmins(string $type, string $title, ?string $body = null, array $data = []): void
    {
        User::whereIn('role', ['admin', 'brand'])
            ->get()
            ->each(fn (User $admin) => $this->notifyUser($admin, $type, $title, $body, $data));
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
            'image' => $this->publicAssetUrl($campaign->image_url),
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
                'rules' => $campaign->rules ?? [],
                'assets' => $campaign->assets ?? [],
                'platforms' => $campaign->platforms ?? [],
            ];
        }

        return $payload;
    }

    private function coursePayload(Course $course): array
    {
        return [
            ...$course->toArray(),
            'image_url' => $this->publicAssetUrl($course->image_url),
        ];
    }

    private function validateCourse(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'cover_image' => ['nullable', 'image', 'max:4096'],
            'duration' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'lessons' => ['nullable', 'array'],
            'lessons.*.title' => ['nullable', 'string', 'max:255'],
            'lessons.*.duration' => ['nullable', 'string', 'max:255'],
            'lessons.*.video_url' => ['nullable', 'string', 'max:2048'],
            'resources' => ['nullable', 'array'],
            'resources.*' => ['string', 'max:2048'],
        ]);
    }

    private function storeCourseImage(Request $request, array &$data): void
    {
        if ($request->hasFile('cover_image')) {
            $data['image_url'] = $this->publicStoragePath($request->file('cover_image')->store('courses', 'public'));
        }

        unset($data['cover_image']);
    }

    private function rupiah(int|float $amount): string
    {
        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    private function publicStoragePath(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    private function publicAssetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim(request()->getSchemeAndHttpHost(), '/').'/'.ltrim($path, '/');
    }
}
