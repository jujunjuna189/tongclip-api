<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'body', 'published_at'])]
class Announcement extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
