<?php

use App\Modules\Anamnesis\Http\Controllers\AnamnesisController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:api-default')->group(function () {
        Route::get('/anamneses', [AnamnesisController::class, 'index'])->middleware('can:anamnesis.read');
        Route::get('/anamneses/{id}', [AnamnesisController::class, 'show'])->middleware('can:anamnesis.read');
        Route::patch('/anamneses/{id}', [AnamnesisController::class, 'update'])->middleware('can:anamnesis.update');
        Route::put('/anamneses/{id}', [AnamnesisController::class, 'update'])->middleware('can:anamnesis.update');
        Route::delete('/anamneses/{id}', [AnamnesisController::class, 'destroy'])->middleware('can:anamnesis.delete');
    });

    Route::middleware('throttle:ai-write')
        ->post('/chats/{chatId}/anamnesis', [AnamnesisController::class, 'generateFromChat'])
        ->middleware(['can:anamnesis.create', 'idempotency']);
});
