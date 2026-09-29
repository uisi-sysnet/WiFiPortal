<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NetworkDeviceController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalMediaController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SplashPageController;
use Illuminate\Support\Facades\Route;

// Public captive portal (reached by phones through the hotspot)
Route::get('/portal/{router:portal_code}', [PortalController::class, 'show'])->name('portal.show');
Route::post('/portal/{router:portal_code}', [PortalController::class, 'login'])->name('portal.login');
Route::get('/portal/{router:portal_code}/terms', [PortalController::class, 'terms'])->name('portal.terms');
Route::get('/portal/{router:portal_code}/welcome', [PortalController::class, 'welcome'])->name('portal.welcome');
// No per-IP limit: every phone behind a router shares one public IP. The session guards it.
Route::post('/portal/{router:portal_code}/connect', [PortalController::class, 'connect'])->name('portal.connect');
Route::get('/hotspot-files/{router:portal_code}/login.html', [PortalController::class, 'routerLoginFile'])
    ->name('portal.router-file');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/map-data', [DashboardController::class, 'mapData'])->name('dashboard.map-data');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/barangays', [SettingsController::class, 'storeBarangay'])->name('barangays.store');
    Route::put('/settings/barangays/{barangay}', [SettingsController::class, 'updateBarangay'])->name('barangays.update');
    Route::delete('/settings/barangays/{barangay}', [SettingsController::class, 'destroyBarangay'])->name('barangays.destroy');
    Route::view('/logs', 'logs.index')->name('logs');

    // Access points and switches (SNMP monitoring). The type comes from the URL.
    foreach (['ap' => ['access-points', 'aps'], 'switch' => ['switches', 'switches']] as $type => [$path, $name]) {
        Route::get("/{$path}", [NetworkDeviceController::class, 'index'])->defaults('type', $type)->name("{$name}.index");
        Route::get("/{$path}/create", [NetworkDeviceController::class, 'create'])->defaults('type', $type)->name("{$name}.create");
        Route::post("/{$path}", [NetworkDeviceController::class, 'store'])->defaults('type', $type)->name("{$name}.store");
    }
    Route::post('/devices/test-snmp', [NetworkDeviceController::class, 'testSnmp'])
        ->middleware('throttle:30,1')->name('devices.test-snmp');
    Route::post('/devices/bulk', [NetworkDeviceController::class, 'bulk'])->name('devices.bulk');
    Route::get('/devices/{device}/edit', [NetworkDeviceController::class, 'edit'])->name('devices.edit');
    Route::put('/devices/{device}', [NetworkDeviceController::class, 'update'])->name('devices.update');
    Route::post('/devices/{device}/check', [NetworkDeviceController::class, 'check'])->name('devices.check');
    Route::delete('/devices/{device}', [NetworkDeviceController::class, 'destroy'])->name('devices.destroy');

    Route::post('/routers/test-connection', [RouterController::class, 'testConnection'])
        ->middleware('throttle:20,1')
        ->name('routers.test-connection');

    Route::post('/routers/detect', [RouterController::class, 'detect'])
        ->middleware('throttle:20,1')
        ->name('routers.detect');

    Route::resource('routers', RouterController::class)
        ->only(['index', 'create', 'store', 'show', 'destroy']);

    Route::get('/splash', [SplashPageController::class, 'edit'])->name('splash.edit');
    Route::put('/splash', [SplashPageController::class, 'update'])->name('splash.update');
    // PUT too: the editor form spoofs PUT, and Preview reuses that form.
    Route::match(['post', 'put'], '/splash/preview', [SplashPageController::class, 'preview'])->name('splash.preview');
    Route::post('/splash/reset-template', [SplashPageController::class, 'resetTemplate'])->name('splash.reset');
    Route::post('/splash/media', [PortalMediaController::class, 'store'])->name('splash.media.store');
    Route::get('/splash/media/{media}', [PortalMediaController::class, 'show'])->name('splash.media.show');
    Route::delete('/splash/media/{media}', [PortalMediaController::class, 'destroy'])->name('splash.media.destroy');

    Route::post('/routers/{router}/provision', [RouterController::class, 'provision'])
        ->name('routers.provision');
});