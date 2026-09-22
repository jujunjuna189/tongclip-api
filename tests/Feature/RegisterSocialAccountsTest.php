<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterSocialAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_multiple_social_accounts_for_one_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Creator Baru',
            'email' => 'creator-baru@example.com',
            'handle' => 'creator-baru',
            'password' => 'password123',
            'whatsapp_number' => '081234567890',
            'social_accounts' => [
                ['platform' => 'tiktok', 'handle' => '@creator.tiktok', 'social_url' => 'https://www.tiktok.com/@creator.tiktok'],
                ['platform' => 'instagram', 'handle' => 'creator.ig', 'social_url' => 'https://www.instagram.com/creator.ig/'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('user.status', 'review');

        $user = User::where('email', 'creator-baru@example.com')->firstOrFail();
        $this->assertCount(2, $user->socialAccounts);
        $this->assertDatabaseHas('social_accounts', [
            'handle' => '@creator.tiktok',
            'platform' => 'tiktok',
            'status' => 'review',
        ]);
        $this->assertDatabaseHas('social_accounts', [
            'handle' => '@creator.ig',
            'platform' => 'instagram',
            'status' => 'review',
        ]);
    }
}
