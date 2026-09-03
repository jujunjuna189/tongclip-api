<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('handle')->nullable()->unique()->after('email');
            $table->string('role')->default('clipper')->after('handle');
            $table->string('status')->default('review')->after('role');
            $table->string('avatar_url')->nullable()->after('status');
            $table->string('bank_name')->nullable()->after('avatar_url');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_number');
            $table->string('api_token', 80)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'handle',
                'role',
                'status',
                'avatar_url',
                'bank_name',
                'bank_account_number',
                'bank_account_name',
                'api_token',
            ]);
        });
    }
};
