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
    'rejection_note',
    'onboarding_completed',
    'api_token',
])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function socialAccounts()
    {
        return $this->belongsToMany(SocialAccount::class)
            ->withPivot(['access_type', 'status'])
            ->withTimestamps();
    }

    public function roleMaster()
    {
        return $this->belongsTo(Role::class, 'role', 'slug');
    }

    public function ownedBrands()
    {
        return $this->hasMany(Brand::class);
    }

    public function brands()
    {
        return $this->belongsToMany(Brand::class)
            ->withPivot(['access_type', 'status'])
            ->withTimestamps();
    }

    public function submissions()
    {
        return $this->hasMany(CampaignSubmission::class);
    }

    public function incomes()
    {
        return $this->hasMany(Income::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function notifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function adminMessages()
    {
        return $this->hasMany(AdminMessage::class);
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
            'onboarding_completed' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
