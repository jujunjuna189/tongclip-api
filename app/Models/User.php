<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'handle',
    'role',
    'status',
    'avatar_url',
    'bank_name',
    'bank_account_number',
    'bank_account_name',
    'api_token',
])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function submissions()
    {
        return $this->hasMany(CampaignSubmission::class);
    }

    public function incomes()
    {
        return $this->hasMany(Income::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
