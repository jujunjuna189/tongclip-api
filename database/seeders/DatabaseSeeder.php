<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->clearSeedData();

        $brandUser = $this->seedUser(
            ['email' => 'brand@example.com'],
            [
                'name' => 'Brand Demo',
                'handle' => '@brand.demo',
                'role' => 'brand',
            ],
        );
        $this->seedAccounts($brandUser, [
            ['name' => 'Brand Demo Official', 'handle' => '@brand.demo.official', 'platform' => 'instagram', 'status' => 'active', 'balance' => 0, 'bank_name' => 'BCA', 'bank_account_number' => '1122334455', 'bank_account_name' => 'Brand Demo'],
        ]);
        $brand = $this->seedBrand($brandUser, [
            'name' => 'Brand Demo',
            'handle' => '@brand.demo',
            'status' => 'active',
        ]);
        $this->attachBrandMember($brand, $brandUser, 'active', 'owner');
    }

    private function clearSeedData(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'admin_messages',
            'notifications',
            'withdrawals',
            'incomes',
            'campaign_submissions',
            'campaigns',
            'brand_user',
            'brands',
            'social_account_user',
            'social_accounts',
            'users',
            'announcements',
            'courses',
        ] as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }

    private function seedUser(array $lookup, array $attributes): User
    {
        $role = $attributes['role'] ?? 'creator';
        Role::firstOrCreate(['slug' => $role], ['name' => Str::headline($role)]);
        $userAttributes = collect($attributes)
            ->except(['avatar_url', 'bank_name', 'bank_account_number', 'bank_account_name'])
            ->all();

        return User::updateOrCreate(
            $lookup,
            [
                'password' => Hash::make('password'),
                'role' => $role,
                'status' => 'active',
                'onboarding_completed' => true,
                'api_token' => Str::random(60),
                ...$userAttributes,
            ],
        );
    }

    private function seedAccounts(User $user, array $accounts)
    {
        return collect($accounts)->map(function (array $account) use ($user) {
            if (($account['handle'] ?? null) === $user->handle && empty($account['email'])) {
                $account['email'] = $user->email;
            }

            $socialAccount = SocialAccount::updateOrCreate(
                ['handle' => $account['handle']],
                [
                    'platform' => 'tiktok',
                    ...$account,
                ],
            );

            $user->socialAccounts()->syncWithoutDetaching([
                $socialAccount->id => [
                    'access_type' => $account['access_type'] ?? 'owner',
                    'status' => $account['pivot_status'] ?? 'active',
                ],
            ]);

            return $socialAccount;
        });
    }

    private function seedBrand(User $owner, array $attributes): Brand
    {
        $brand = Brand::updateOrCreate(
            ['handle' => $attributes['handle']],
            [
                'user_id' => $owner->id,
                ...$attributes,
            ],
        );

        $this->attachBrandMember($brand, $owner, 'active', 'owner');

        return $brand;
    }

    private function attachBrandMember(Brand $brand, User $user, string $status = 'pending', string $accessType = 'member'): void
    {
        $brand->members()->syncWithoutDetaching([
            $user->id => [
                'access_type' => $accessType,
                'status' => $status,
            ],
        ]);
    }
}
