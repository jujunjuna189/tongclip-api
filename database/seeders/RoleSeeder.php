<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['slug' => 'creator', 'name' => 'Creator'],
            ['slug' => 'brand', 'name' => 'Brand'],
            ['slug' => 'admin', 'name' => 'Admin'],
        ] as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }

        User::where('role', 'clipper')->update(['role' => 'creator']);
        Role::where('slug', 'clipper')->delete();
    }
}
