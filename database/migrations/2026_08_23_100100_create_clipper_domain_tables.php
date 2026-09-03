<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('handle');
            $table->string('platform')->default('tiktok');
            $table->string('status')->default('review');
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('brand');
            $table->string('image_url')->nullable();
            $table->unsignedInteger('rate_per_view');
            $table->string('category');
            $table->unsignedTinyInteger('budget_percent')->default(0);
            $table->unsignedInteger('views_target')->default(0);
            $table->string('type')->default('CLIPPING');
            $table->boolean('exclusive')->default(false);
            $table->text('brief')->nullable();
            $table->json('assets')->nullable();
            $table->json('platforms')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('campaign_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('joined');
            $table->string('video_url')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedBigInteger('estimated_payout')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('earned_at');
            $table->string('source');
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('review');
            $table->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('bank_name');
            $table->string('bank_account_number');
            $table->string('bank_account_name');
            $table->string('status')->default('requested');
            $table->timestamp('requested_at');
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('image_url')->nullable();
            $table->string('duration')->nullable();
            $table->string('level')->default('Pemula');
            $table->string('url')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_messages');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('campaign_submissions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('social_accounts');
    }
};
