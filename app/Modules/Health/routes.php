<?php

use App\Modules\Health\Http\Controllers\HealthMetricController;
use App\Modules\Health\Http\Controllers\HealthNormsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api-default'])->group(function () {
    Route::post('/health/metrics', [HealthMetricController::class, 'store'])->middleware(['can:chat.create']);
    Route::get('/health/metrics', [HealthMetricController::class, 'index'])->middleware(['can:chat.read']);
    Route::get('/health/summary', [HealthMetricController::class, 'summary'])->middleware(['can:chat.read']);
    Route::get('/health/norms', [HealthNormsController::class, 'show'])->middleware(['can:chat.read']);
});
