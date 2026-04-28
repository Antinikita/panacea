<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth-strict')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'throttle:api-default'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::post('/tokens', [AuthController::class, 'createToken']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
});
