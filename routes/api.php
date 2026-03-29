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
    
    // Complaint routes
    Route::apiResource('complaints', ComplaintController::class);

    Route::post('/complaints/analyze', [ComplaintAIController::class, 'analyze']);

    Route::apiResource('recommendations', RecommendationController::class);
});