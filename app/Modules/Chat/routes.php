<?php

use App\Modules\Chat\Http\Controllers\ChatController;
use App\Modules\Chat\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:api-default')->group(function () {
        Route::get('/chats', [ChatController::class, 'index'])->middleware('can:chat.read');
        Route::post('/chats', [ChatController::class, 'store'])->middleware('can:chat.create');
        Route::get('/chats/{id}', [ChatController::class, 'show'])->middleware('can:chat.read');
        Route::put('/chats/{id}', [ChatController::class, 'update'])->middleware('can:chat.update');
        Route::patch('/chats/{id}', [ChatController::class, 'update'])->middleware('can:chat.update');
        Route::delete('/chats/{id}', [ChatController::class, 'destroy'])->middleware('can:chat.delete');
        Route::delete('/chats/{id}/messages/{messageId}', [ChatController::class, 'deleteMessage'])->middleware('can:chat.delete');

        Route::get('/search', SearchController::class)->middleware('can:chat.read');
    });

    Route::middleware('throttle:ai-write')->group(function () {
        Route::post('/chats/{id}/messages', [ChatController::class, 'sendMessage'])->middleware(['can:chat.create', 'idempotency']);
        Route::post('/chats/{id}/messages/stream', [ChatController::class, 'streamMessage'])->middleware('can:chat.create');
        Route::post('/chats/{id}/regenerate', [ChatController::class, 'regenerate'])->middleware(['can:chat.update', 'idempotency']);
        Route::patch('/chats/{id}/messages/{messageId}', [ChatController::class, 'updateMessage'])->middleware(['can:chat.update', 'idempotency']);
    });
});
