<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RouterController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::redirect('/', '/routers');

    Route::post('/routers/detect', [RouterController::class, 'detect'])
        ->middleware('throttle:20,1')
        ->name('routers.detect');

    Route::resource('routers', RouterController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy']);

    Route::post('/routers/{router}/provision', [RouterController::class, 'provision'])
        ->name('routers.provision');
});
