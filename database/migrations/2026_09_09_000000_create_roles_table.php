<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        DB::table('roles')->insert([
            ['slug' => 'creator', 'name' => 'Creator', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'brand', 'name' => 'Brand', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'admin', 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
