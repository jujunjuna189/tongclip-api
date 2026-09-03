<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'campaign_id', 'social_account_id', 'earned_at', 'source', 'amount', 'status'])]
class Income extends Model
{
    protected function casts(): array
    {
        return ['earned_at' => 'date'];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
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
