<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth-strict')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// The verification.verify route is hit by the link inside the verification
// email — `signed` middleware checks the URL HMAC against APP_KEY, so it
// doesn't need its own auth.
Route::middleware('signed')
    ->get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->name('verification.verify');

Route::middleware(['auth:sanctum', 'throttle:api-default'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::delete('/user', [AuthController::class, 'deleteAccount']);
    Route::post('/email/verification-notification', [AuthController::class, 'resendEmailVerification'])
        ->middleware('throttle:6,1');
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::post('/tokens', [AuthController::class, 'createToken']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
});
