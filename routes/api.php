<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\JsonSessionController;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// Route::post('/login',[JsonSessionController::class,'show']);
// Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });


// CSRF cookie для React фронта
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show']);

// Login через токен для Swift или cookie для React
Route::post('/login', [AuthController::class, 'login']);


Route::post('/register', [AuthController::class, 'register']);

// Logout
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Получение данных пользователя (React + Swift)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/complaints',[ComplaintController::class,'index']);
Route::middleware('auth:sanctum')->post('/complaints',[ComplaintController::class,'store']);
Route::middleware('auth:sanctum')->put('/complaints/{id}',[ComplaintController::class,'update']);
Route::middleware('auth:sanctum')->delete('/complaints/{id}',[ComplaintController::class,'destroy']);



// Route::post('/logout', [JsonSessionController::class, 'logout'])->middleware('auth:sanctum');