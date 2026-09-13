<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'social_account_id',
    'amount',
    'bank_name',
    'bank_account_number',
    'bank_account_name',
    'status',
    'requested_at',
])]
class Withdrawal extends Model
{
    protected function casts(): array
    {
        return ['requested_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount()
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
