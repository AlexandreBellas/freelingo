<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDashboardBannerController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardBannerController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FreemiumController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MemoryController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/api/contact', [ContactController::class, 'store']);
Route::post('/api/billing/webhook', [BillingController::class, 'webhook']);
Route::post('/api/analytics/public', [AnalyticsController::class, 'publicEvent']);
Route::get('/api/reviews/public', [ReviewController::class, 'publicIndex']);

Route::middleware('auth.api')->group(function () {
    Route::get('/api/languages', [LanguageController::class, 'index']);
    Route::get('/api/languages/active', [LanguageController::class, 'active']);
    Route::post('/api/languages', [LanguageController::class, 'store']);
    Route::put('/api/languages/active', [LanguageController::class, 'switch']);
    Route::delete('/api/languages/{targetLanguage}', [LanguageController::class, 'destroy']);

    Route::get('/api/memories', [MemoryController::class, 'index']);
    Route::post('/api/memories', [MemoryController::class, 'store']);
    Route::delete('/api/memories', [MemoryController::class, 'clear']);
    Route::delete('/api/memories/{memoryId}', [MemoryController::class, 'destroy'])->whereNumber('memoryId');

    Route::get('/api/freemium/status', [FreemiumController::class, 'status']);
    Route::post('/api/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/api/billing/portal', [BillingController::class, 'portal']);
    Route::post('/api/analytics/ui', [AnalyticsController::class, 'ui']);
    Route::put('/api/dashboard-banner/dismiss', [DashboardBannerController::class, 'dismiss']);

    Route::get('/api/reviews/me', [ReviewController::class, 'me']);
    Route::post('/api/reviews', [ReviewController::class, 'store']);
    Route::patch('/api/reviews/me', [ReviewController::class, 'update']);
    Route::delete('/api/reviews/me', [ReviewController::class, 'destroyMine']);

    Route::get('/api/feedback/unread-summary', [FeedbackController::class, 'unread']);
    Route::get('/api/feedback', [FeedbackController::class, 'index']);
    Route::post('/api/feedback', [FeedbackController::class, 'store']);
    Route::post('/api/feedback/{entryId}/read', [FeedbackController::class, 'markReadEndpoint'])->whereNumber('entryId');
    Route::get('/api/feedback/{entryId}', [FeedbackController::class, 'show'])->whereNumber('entryId');
    Route::delete('/api/feedback/{entryId}', [FeedbackController::class, 'destroy'])->whereNumber('entryId');
    Route::post('/api/feedback/{entryId}/vote', [FeedbackController::class, 'vote'])->whereNumber('entryId');
    Route::patch('/api/feedback/{entryId}/status', [FeedbackController::class, 'updateStatus'])->whereNumber('entryId');
    Route::get('/api/feedback/{entryId}/comments', [FeedbackController::class, 'comments'])->whereNumber('entryId');
    Route::post('/api/feedback/{entryId}/comments', [FeedbackController::class, 'addComment'])->whereNumber('entryId');
    Route::delete('/api/feedback/{entryId}/comments/{commentId}', [FeedbackController::class, 'deleteComment'])->whereNumber(['entryId', 'commentId']);
});

Route::middleware(['auth.api', 'admin'])->group(function () {
    Route::get('/api/admin/stats', [AdminController::class, 'stats']);
    Route::get('/api/admin/users', [AdminController::class, 'users']);
    Route::post('/api/admin/users', [AdminController::class, 'storeUser']);
    Route::get('/api/admin/users/{userId}/stats', [AdminController::class, 'userStats'])->whereNumber('userId');
    Route::get('/api/admin/users/{userId}/quota', [AdminController::class, 'quota'])->whereNumber('userId');
    Route::get('/api/admin/users/{userId}', [AdminController::class, 'showUser'])->whereNumber('userId');
    Route::patch('/api/admin/users/{userId}', [AdminController::class, 'updateUser'])->whereNumber('userId');
    Route::delete('/api/admin/users/{userId}', [AdminController::class, 'destroyUser'])->whereNumber('userId');
    Route::post('/api/admin/invite', [AdminController::class, 'invite']);
    Route::get('/api/admin/maintenance', [AdminController::class, 'maintenance']);
    Route::patch('/api/admin/maintenance', [AdminController::class, 'toggleMaintenance']);
    Route::put('/api/admin/maintenance', [AdminController::class, 'setMaintenance']);
    Route::get('/api/admin/dashboard-banner', [AdminDashboardBannerController::class, 'show']);
    Route::post('/api/admin/dashboard-banner/translate', [AdminDashboardBannerController::class, 'translate']);
    Route::put('/api/admin/dashboard-banner', [AdminDashboardBannerController::class, 'update']);
    Route::get('/api/admin/reviews', [ReviewController::class, 'adminIndex']);
    Route::patch('/api/admin/reviews/{reviewId}', [ReviewController::class, 'adminUpdate'])->whereNumber('reviewId');
    Route::delete('/api/admin/reviews/{reviewId}', [ReviewController::class, 'adminDestroy'])->whereNumber('reviewId');
});
