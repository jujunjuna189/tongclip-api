<?php

use App\Http\Controllers\Api\ClipperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [ClipperController::class, 'login']);
    Route::post('/auth/register', [ClipperController::class, 'register']);

    Route::get('/me', [ClipperController::class, 'me']);
    Route::get('/dashboard', [ClipperController::class, 'dashboard']);
    Route::get('/campaigns', [ClipperController::class, 'campaigns']);
    Route::get('/campaigns/{slug}', [ClipperController::class, 'campaign']);
    Route::post('/campaigns/{slug}/join', [ClipperController::class, 'joinCampaign']);
    Route::post('/campaigns/{slug}/submit', [ClipperController::class, 'submitCampaign']);
    Route::get('/incomes', [ClipperController::class, 'incomes']);
    Route::get('/income-summary', [ClipperController::class, 'incomeSummary']);
    Route::post('/withdrawals', [ClipperController::class, 'requestWithdrawal']);
    Route::post('/profile', [ClipperController::class, 'updateProfile']);
    Route::patch('/profile', [ClipperController::class, 'updateProfile']);
    Route::get('/leaderboard', [ClipperController::class, 'leaderboard']);
    Route::get('/announcements', [ClipperController::class, 'announcements']);
    Route::get('/notifications', [ClipperController::class, 'notifications']);
    Route::post('/notifications/read', [ClipperController::class, 'markNotificationsRead']);
    Route::get('/courses', [ClipperController::class, 'courses']);
    Route::get('/courses/{course}', [ClipperController::class, 'course']);
    Route::get('/contact-admin', [ClipperController::class, 'contactTickets']);
    Route::post('/contact-admin', [ClipperController::class, 'contactAdmin']);
    Route::get('/onboarding', [ClipperController::class, 'onboarding']);
    Route::post('/onboarding/brand', [ClipperController::class, 'joinOnboardingBrand']);
    Route::post('/onboarding/complete', [ClipperController::class, 'completeOnboarding']);
    Route::post('/brands/join', [ClipperController::class, 'joinBrand']);
    Route::get('/brand-requests', [ClipperController::class, 'brandRequests']);
    Route::patch('/brand-requests/{brand}/{user}', [ClipperController::class, 'updateBrandRequest']);

    Route::get('/admin/campaigns', [ClipperController::class, 'adminCampaigns']);
    Route::post('/admin/campaigns', [ClipperController::class, 'createAdminCampaign']);
    Route::get('/admin/campaigns/{campaign}', [ClipperController::class, 'adminCampaign']);
    Route::patch('/admin/campaigns/{campaign}', [ClipperController::class, 'updateAdminCampaign']);
    Route::delete('/admin/campaigns/{campaign}', [ClipperController::class, 'deleteAdminCampaign']);
    Route::get('/admin/submissions', [ClipperController::class, 'adminSubmissions']);
    Route::patch('/admin/submissions/{submission}', [ClipperController::class, 'updateAdminSubmission']);
    Route::delete('/admin/submissions/{submission}', [ClipperController::class, 'deleteAdminSubmission']);
    Route::get('/admin/creators', [ClipperController::class, 'adminCreators']);
    Route::post('/admin/creators', [ClipperController::class, 'createAdminCreator']);
    Route::post('/admin/creators/invite', [ClipperController::class, 'inviteAdminCreator']);
    Route::patch('/admin/creators/{user}', [ClipperController::class, 'updateAdminCreator']);
    Route::delete('/admin/creators/{user}', [ClipperController::class, 'deleteAdminCreator']);
    Route::get('/admin/payouts', [ClipperController::class, 'adminPayouts']);
    Route::patch('/admin/payouts/{payout}', [ClipperController::class, 'updateAdminPayout']);
    Route::delete('/admin/payouts/{payout}', [ClipperController::class, 'deleteAdminPayout']);
    Route::get('/admin/tickets', [ClipperController::class, 'adminTickets']);
    Route::patch('/admin/tickets/{message}', [ClipperController::class, 'updateAdminTicket']);
    Route::get('/admin/courses', [ClipperController::class, 'adminCourses']);
    Route::post('/admin/courses', [ClipperController::class, 'createAdminCourse']);
    Route::patch('/admin/courses/{course}', [ClipperController::class, 'updateAdminCourse']);
    Route::delete('/admin/courses/{course}', [ClipperController::class, 'deleteAdminCourse']);
});
