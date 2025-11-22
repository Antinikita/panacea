<?php

use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\PythonController;
use App\Http\Controllers\RegisteredUserController;
use App\Http\Controllers\SessionController;
use App\Models\Complaint;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/send-to-py',[PythonController::class,'sendToPy']);

// Route
// Route::post('/complaint',[ComplaintController::class,'store']);


// Route::view('/index','index')->middleware(['auth']);

// Route::get('/register',[RegisteredUserController::class,'create']);
// Route::post('/register',[RegisteredUserController::class,'store']);

// Route::get('/login',[SessionController::class,'create'])->name('login');
// Route::post('/login',[SessionController::class,'store']);

// Route::get('/logout',[SessionController::class,'destroy']);

