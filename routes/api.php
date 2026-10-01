<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeminiTestController;
use Illuminate\Support\Facades\Auth;

Route::middleware(['throttle:login'])->group(function() {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum']);

Route::match(['get', 'post'], '/test-gemini', GeminiTestController::class);
