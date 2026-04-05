<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\ComplaintAIController;
use App\Http\Controllers\RecommendationController;

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn(Request $r) => $r->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    // ⚠️ analyze MUST be before apiResource
    Route::post('/complaints/analyze', [ComplaintAIController::class, 'analyze']);
    Route::apiResource('complaints', ComplaintController::class);
    Route::apiResource('recommendations', RecommendationController::class);

    
});

