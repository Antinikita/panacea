<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AnamnesisController;

// ===== Public Routes =====

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google', [AuthController::class, 'googleLogin']);

Route::get('/health', fn() => response()->json(['status' => 'ok']));

// ===== Protected Routes =====

Route::middleware('auth:sanctum')->group(function () {

    // User & token management
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::post('/tokens', [AuthController::class, 'createToken']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);

    // ===== Chat =====
    Route::get('/chats', [ChatController::class, 'index'])->middleware('can:chat.read');
    Route::post('/chats', [ChatController::class, 'store'])->middleware('can:chat.create');
    Route::get('/chats/{id}', [ChatController::class, 'show'])->middleware('can:chat.read');
    Route::put('/chats/{id}', [ChatController::class, 'update'])->middleware('can:chat.update');
    Route::patch('/chats/{id}', [ChatController::class, 'update'])->middleware('can:chat.update');
    Route::delete('/chats/{id}', [ChatController::class, 'destroy'])->middleware('can:chat.delete');

    Route::post('/chats/{id}/messages', [ChatController::class, 'sendMessage'])->middleware('can:chat.create');
    Route::post('/chats/{id}/messages/stream', [ChatController::class, 'streamMessage'])->middleware('can:chat.create');
    Route::post('/chats/{id}/regenerate', [ChatController::class, 'regenerate'])->middleware('can:chat.update');
    Route::patch('/chats/{id}/messages/{messageId}', [ChatController::class, 'updateMessage'])->middleware('can:chat.update');
    Route::delete('/chats/{id}/messages/{messageId}', [ChatController::class, 'deleteMessage'])->middleware('can:chat.delete');

    // ===== Anamneses =====
    Route::get('/anamneses', [AnamnesisController::class, 'index'])->middleware('can:anamnesis.read');
    Route::get('/anamneses/{id}', [AnamnesisController::class, 'show'])->middleware('can:anamnesis.read');
    Route::patch('/anamneses/{id}', [AnamnesisController::class, 'update'])->middleware('can:anamnesis.update');
    Route::put('/anamneses/{id}', [AnamnesisController::class, 'update'])->middleware('can:anamnesis.update');
    Route::delete('/anamneses/{id}', [AnamnesisController::class, 'destroy'])->middleware('can:anamnesis.delete');
    Route::post('/chats/{chatId}/anamnesis', [AnamnesisController::class, 'generateFromChat'])->middleware('can:anamnesis.create');
});
