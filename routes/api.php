<?php

use Illuminate\Support\Facades\Route;

// Module routes are loaded by each Modules/<Name>/<Name>ServiceProvider.
// Only global infrastructure routes belong here.

Route::get('/health', fn () => response()->json(['status' => 'ok']));
