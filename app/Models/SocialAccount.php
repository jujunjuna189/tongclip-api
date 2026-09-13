<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'email', 'handle', 'platform', 'status', 'avatar_url', 'bank_name', 'bank_account_number', 'bank_account_name', 'balance'])]
class SocialAccount extends Model
{
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['access_type', 'status'])
            ->withTimestamps();
    }

    public function incomes()
    {
        return $this->hasMany(Income::class);
    }
}
