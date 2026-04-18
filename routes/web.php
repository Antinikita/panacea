<?php

use Illuminate\Support\Facades\Route;

use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// Единственный web-маршрут: SPA запрашивает CSRF-cookie перед POST к /api/*
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->name('sanctum.csrf-cookie');

