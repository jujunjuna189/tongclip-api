<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->date('deadline_at')->nullable()->after('budget_percent');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('deadline_at');
        });
    }
};
