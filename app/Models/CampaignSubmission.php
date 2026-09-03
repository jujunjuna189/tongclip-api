<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'campaign_id',
    'user_id',
    'social_account_id',
    'status',
    'video_url',
    'views',
    'estimated_payout',
    'submitted_at',
])]
class CampaignSubmission extends Model
{
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
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
