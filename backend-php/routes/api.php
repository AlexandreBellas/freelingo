<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/api/admin/health', [HealthController::class, 'adminHealth'])->middleware(['auth.api', 'admin']);
Route::get('/api/config', [ConfigController::class, 'show']);

Route::prefix('/api/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth.api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/quota', [AuthController::class, 'quota']);
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::delete('/me', [AuthController::class, 'deleteMe']);
        Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    });
});

foreach (glob(__DIR__.'/domains/*.php') ?: [] as $domainRoutes) {
    require $domainRoutes;
}
