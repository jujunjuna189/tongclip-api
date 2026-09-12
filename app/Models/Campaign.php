<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'slug',
    'title',
    'brand',
    'image_url',
    'rate_per_view',
    'category',
    'budget_percent',
    'deadline_at',
    'views_target',
    'type',
    'exclusive',
    'brief',
    'rules',
    'assets',
    'platforms',
    'status',
])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return [
            'exclusive' => 'boolean',
            'deadline_at' => 'date',
            'rules' => 'array',
            'assets' => 'array',
            'platforms' => 'array',
        ];
    }

    public function submissions()
    {
        return $this->hasMany(CampaignSubmission::class);
    }
}
