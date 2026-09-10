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
            $table->string('role')->default('creator')->after('handle');
            $table->string('status')->default('review')->after('role');
            $table->boolean('onboarding_completed')->default(false)->after('status');
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
                'onboarding_completed',
                'api_token',
            ]);
        });
    }
};
