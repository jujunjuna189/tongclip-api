<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Campaign;
use App\Models\Course;
use App\Models\Income;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->seedUser(
            ['email' => 'alya@example.com'],
            [
                'name' => 'Alya Pramesti',
                'handle' => '@alya.clip',
                'bank_name' => 'BCA',
                'bank_account_number' => '1234567890',
                'bank_account_name' => 'Alya Pramesti',
            ],
        );

        $accounts = $this->seedAccounts($user, [
            ['name' => 'Tongkrongan Main', 'handle' => '@tongkrongan.clip', 'status' => 'active', 'balance' => 18450000],
            ['name' => 'Clipper Food', 'handle' => '@clipper.foodies', 'status' => 'review', 'balance' => 6280000],
            ['name' => 'Daily Finds', 'handle' => '@dailyfinds.id', 'status' => 'active', 'balance' => 11720000],
        ]);

        $raka = $this->seedUser(
            ['email' => 'raka@example.com'],
            [
                'name' => 'Raka Mahendra',
                'handle' => '@rakamhd',
                'bank_name' => 'Mandiri',
                'bank_account_number' => '9876543210',
                'bank_account_name' => 'Raka Mahendra',
            ],
        );

        $rakaAccounts = $this->seedAccounts($raka, [
            ['name' => 'Raka Gaming Clips', 'handle' => '@raka.gaming', 'platform' => 'tiktok', 'status' => 'active', 'balance' => 7420000],
            ['name' => 'Raka Review ID', 'handle' => '@raka.review', 'platform' => 'instagram', 'status' => 'active', 'balance' => 3180000],
        ]);

        $nina = $this->seedUser(
            ['email' => 'nina@example.com'],
            [
                'name' => 'Nina Saras',
                'handle' => '@ninasaras.id',
                'bank_name' => 'BNI',
                'bank_account_number' => '4561237890',
                'bank_account_name' => 'Nina Saras',
            ],
        );

        $ninaAccounts = $this->seedAccounts($nina, [
            ['name' => 'Nina Beauty Lab', 'handle' => '@nina.beauty', 'platform' => 'tiktok', 'status' => 'active', 'balance' => 5180000],
            ['name' => 'Nina Daily Shorts', 'handle' => '@nina.shorts', 'platform' => 'youtube', 'status' => 'review', 'balance' => 920000],
        ]);

        $campaigns = [
            ['slug' => 'sulianto-indria-putra', 'title' => 'Sulianto Indria Putra', 'brand' => 'Suli', 'image_url' => 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 7500, 'category' => 'EDUCATION', 'budget_percent' => 39, 'deadline_at' => '2026-09-05', 'views_target' => 6821, 'type' => 'CLIPPING', 'exclusive' => true],
            ['slug' => 'bybit-grand-launch', 'title' => 'Bybit Indonesia Grand Launch Campaign', 'brand' => 'Bybit Indonesia', 'image_url' => 'https://images.unsplash.com/photo-1603386329225-868f9b1ee6c9?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 5000, 'category' => 'UGC', 'budget_percent' => 99, 'deadline_at' => '2026-09-12', 'views_target' => 1899, 'type' => 'UGC'],
            ['slug' => 'wardah-color-circuit', 'title' => 'Wardah Color Circuit', 'brand' => 'Wardah', 'image_url' => 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 3000, 'category' => 'ENTERTAINMENT', 'budget_percent' => 92, 'deadline_at' => '2026-09-18', 'views_target' => 1823, 'type' => 'CLIPPING'],
            ['slug' => 'beauty-flash-clip', 'title' => 'Beauty Flash Clip', 'brand' => 'Glowkit', 'image_url' => 'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 4000, 'category' => 'BEAUTY', 'budget_percent' => 64, 'deadline_at' => '2026-09-22', 'views_target' => 2110, 'type' => 'CLIPPING'],
            ['slug' => 'gadget-weekly-review', 'title' => 'Gadget Weekly Review', 'brand' => 'TeknoMart', 'image_url' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 6000, 'category' => 'TECH', 'budget_percent' => 72, 'deadline_at' => '2026-09-25', 'views_target' => 4281, 'type' => 'UGC'],
            ['slug' => 'foodies-daily-finds', 'title' => 'Foodies Daily Finds', 'brand' => 'Rasa Lokal', 'image_url' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 2500, 'category' => 'FOOD', 'budget_percent' => 84, 'deadline_at' => '2026-09-28', 'views_target' => 3459, 'type' => 'CLIPPING'],
            ['slug' => 'flash-peak-football', 'title' => 'Game baru - Flash Peak 4v4 Freestyle Football', 'brand' => 'Flash Peak', 'image_url' => 'https://images.unsplash.com/photo-1575361204480-aadea25e6e68?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 4000, 'category' => 'GAMING', 'budget_percent' => 1, 'deadline_at' => '2026-08-30', 'views_target' => 12149, 'type' => 'CLIPPING'],
            ['slug' => 'enhypen-jakarta', 'title' => 'ENHYPEN Jakarta - Last Chance to Get Your Ticket', 'brand' => 'PK Entertainment', 'image_url' => 'https://images.unsplash.com/photo-1501386761578-eac5c94b800a?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 3000, 'category' => 'ENTERTAINMENT', 'budget_percent' => 82, 'deadline_at' => '2026-10-02', 'views_target' => 3149, 'type' => 'CLIPPING'],
            ['slug' => 'skincare-amorgia', 'title' => 'SKINCARE AMORGIA', 'brand' => 'Artha Idt', 'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 7000, 'category' => 'LIFESTYLE', 'budget_percent' => 100, 'deadline_at' => '2026-10-07', 'views_target' => 432, 'type' => 'UGC'],
            ['slug' => 'bodycare-artha', 'title' => 'BODYCARE ARTHA LDT', 'brand' => 'Artha Idt', 'image_url' => 'https://images.unsplash.com/photo-1608248597279-f99d160bfcbc?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 7000, 'category' => 'LIFESTYLE', 'budget_percent' => 63, 'deadline_at' => '2026-10-10', 'views_target' => 4705, 'type' => 'UGC'],
            ['slug' => 'podcast-raditya-dika', 'title' => 'Podcast Raditya Dika dan Aqeela', 'brand' => 'Emina', 'image_url' => 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 3000, 'category' => 'LIFESTYLE', 'budget_percent' => 3, 'deadline_at' => '2026-08-29', 'views_target' => 10030, 'type' => 'CLIPPING'],
            ['slug' => 'teh-pucuk-milyaran', 'title' => 'Teh Pucuk Berhadiah Milyaran - UGC', 'brand' => 'Teh Pucuk', 'image_url' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?auto=format&fit=crop&w=1000&q=80', 'rate_per_view' => 7500, 'category' => 'ENTERTAINMENT', 'budget_percent' => 90, 'deadline_at' => '2026-10-15', 'views_target' => 11302, 'type' => 'UGC'],
        ];

        foreach ($campaigns as $campaign) {
            Campaign::updateOrCreate(
                ['slug' => $campaign['slug']],
                [
                    'brief' => 'Ikuti brief, posting clip di platform yang terhubung, lalu submit link video untuk validasi views dan payout.',
                    'assets' => ['Brand guideline', 'Raw footage', 'Caption sample'],
                    'platforms' => ['TikTok', 'IG', 'YT'],
                    'status' => 'active',
                    ...$campaign,
                ],
            );
        }

        $incomeRows = [
            ['earned_at' => '2026-07-12', 'source' => 'Sulianto Indria Putra', 'amount' => 4250000, 'status' => 'valid', 'account' => '@tongkrongan.clip', 'campaign' => 'sulianto-indria-putra'],
            ['earned_at' => '2026-07-10', 'source' => 'Beauty Flash Clip', 'amount' => 2900000, 'status' => 'valid', 'account' => '@dailyfinds.id', 'campaign' => 'beauty-flash-clip'],
            ['earned_at' => '2026-07-08', 'source' => 'Gadget Weekly Review', 'amount' => 1150000, 'status' => 'review', 'account' => '@clipper.foodies', 'campaign' => 'gadget-weekly-review'],
        ];

        foreach ($incomeRows as $row) {
            Income::updateOrCreate(
                ['user_id' => $user->id, 'source' => $row['source'], 'earned_at' => $row['earned_at']],
                [
                    'campaign_id' => Campaign::where('slug', $row['campaign'])->value('id'),
                    'social_account_id' => $accounts->firstWhere('handle', $row['account'])?->id,
                    'amount' => $row['amount'],
                    'status' => $row['status'],
                ],
            );
        }

        foreach ([
            ['user' => $raka, 'earned_at' => '2026-07-14', 'source' => 'Flash Peak 4v4 Freestyle Football', 'amount' => 6420000, 'status' => 'valid', 'account' => $rakaAccounts->firstWhere('handle', '@raka.gaming'), 'campaign' => 'flash-peak-football'],
            ['user' => $nina, 'earned_at' => '2026-07-13', 'source' => 'Wardah Color Circuit', 'amount' => 5180000, 'status' => 'valid', 'account' => $ninaAccounts->firstWhere('handle', '@nina.beauty'), 'campaign' => 'wardah-color-circuit'],
        ] as $row) {
            Income::updateOrCreate(
                ['user_id' => $row['user']->id, 'source' => $row['source'], 'earned_at' => $row['earned_at']],
                [
                    'campaign_id' => Campaign::where('slug', $row['campaign'])->value('id'),
                    'social_account_id' => $row['account']?->id,
                    'amount' => $row['amount'],
                    'status' => $row['status'],
                ],
            );
        }

        foreach ([
            ['title' => 'Jadwal withdraw Juli 2026', 'body' => 'Penarikan dana dibuka tanggal 15 dan 16 Juli 2026 pukul 09.00-18.00 WIB.'],
            ['title' => 'Campaign beauty buka slot', 'body' => 'Glowkit membuka tambahan 50 slot clipper untuk akun dengan performa video di atas 3%.'],
            ['title' => 'Update peninjauan akun', 'body' => 'Akun baru wajib melengkapi validasi sosial sebelum mengikuti campaign berbayar.'],
        ] as $announcement) {
            Announcement::updateOrCreate(['title' => $announcement['title']], ['published_at' => now(), ...$announcement]);
        }

        foreach ([
            [
                'title' => 'Hook 3 Detik Pertama',
                'description' => 'Cara membuka video dengan kalimat dan visual yang bikin penonton berhenti scroll.',
                'image_url' => 'https://images.unsplash.com/photo-1611162617474-5b21e879e113?auto=format&fit=crop&w=900&q=80',
                'duration' => '18 menit',
                'level' => 'Pemula',
            ],
            [
                'title' => 'Editing Cepat di Mobile',
                'description' => 'Workflow potong footage, tambah caption, dan export clip siap upload dari HP.',
                'image_url' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=80',
                'duration' => '27 menit',
                'level' => 'Pemula',
            ],
            [
                'title' => 'Membaca Brief Campaign',
                'description' => 'Bedah brief brand, ambil poin wajib, dan hindari kesalahan yang bikin submission ditolak.',
                'image_url' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=900&q=80',
                'duration' => '21 menit',
                'level' => 'Menengah',
            ],
            [
                'title' => 'Optimasi Caption dan CTA',
                'description' => 'Template caption, hashtag, dan CTA yang membantu video lebih mudah dipahami audience.',
                'image_url' => 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=900&q=80',
                'duration' => '16 menit',
                'level' => 'Pemula',
            ],
        ] as $course) {
            Course::updateOrCreate(['title' => $course['title']], $course);
        }
    }

    private function seedUser(array $lookup, array $attributes): User
    {
        return User::updateOrCreate(
            $lookup,
            [
                'password' => Hash::make('password'),
                'role' => 'clipper',
                'status' => 'active',
                'api_token' => Str::random(60),
                ...$attributes,
            ],
        );
    }

    private function seedAccounts(User $user, array $accounts)
    {
        return collect($accounts)->map(fn (array $account) => SocialAccount::updateOrCreate(
            ['handle' => $account['handle']],
            [
                'user_id' => $user->id,
                'platform' => 'tiktok',
                ...$account,
            ],
        ));
    }
}
