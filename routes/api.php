<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ComplaintAIController;
use App\Http\Controllers\RecommendationController;

// Auth routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    Route::post('/logout', [AuthController::class, 'logout']);

    // POST /complaints/analyze должен идти до apiResource, иначе конфликтует с маршрутами /complaints/{complaint}
    Route::post('/complaints/analyze', [ComplaintAIController::class, 'analyze']);
    Route::apiResource('complaints', ComplaintController::class);

    Route::apiResource('recommendations', RecommendationController::class);
});