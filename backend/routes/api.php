<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BusinessSettingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceRateController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
]));

Route::middleware('guest')->prefix('auth')->group(function (): void {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration')
        ->name('auth.register');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', CurrentUserController::class)->name('auth.me');
    Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('auth.logout');

    Route::get('/business-settings', [BusinessSettingController::class, 'show'])
        ->name('business-settings.show');
    Route::put('/business-settings', [BusinessSettingController::class, 'update'])
        ->name('business-settings.update');

    Route::apiResource('customers', CustomerController::class)->only([
        'index', 'store', 'show', 'update',
    ]);
    Route::apiResource('event-types', EventTypeController::class)->only([
        'index', 'store', 'show', 'update',
    ]);
    Route::apiResource('services', ServiceController::class)->only([
        'index', 'store', 'show', 'update',
    ]);
    Route::get('/services/{service}/packages', [PackageController::class, 'index'])
        ->name('services.packages.index');
    Route::post('/services/{service}/packages', [PackageController::class, 'store'])
        ->name('services.packages.store');
    Route::apiResource('packages', PackageController::class)->only(['show', 'update']);
    Route::apiResource('service-rates', ServiceRateController::class)->only([
        'index', 'store', 'show', 'update',
    ]);
    Route::apiResource('staff', StaffController::class)->only([
        'index', 'store', 'show', 'update',
    ]);
});
