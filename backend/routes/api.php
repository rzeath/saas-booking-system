<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingServiceStaffController;
use App\Http\Controllers\Api\V1\BusinessSettingController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\EventTypeController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServicePackageController;
use App\Http\Controllers\Api\V1\ServiceRateController;
use App\Http\Controllers\Api\V1\StaffController;
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

    Route::prefix('v1')->name('v1.')->group(function (): void {
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
        Route::get('/services/{service}/package-mappings', [ServicePackageController::class, 'index'])
            ->name('services.package-mappings.index');
        Route::put('/services/{service}/package-mappings', [ServicePackageController::class, 'update'])
            ->name('services.package-mappings.update');
        Route::apiResource('packages', PackageController::class)->only([
            'index', 'store', 'show', 'update',
        ]);

        Route::get('/rates', [ServiceRateController::class, 'all'])
            ->name('rates.index');
        Route::get('/services/{service}/packages/{package}/rates', [ServiceRateController::class, 'index'])
            ->name('services.packages.rates.index');
        Route::post('/services/{service}/packages/{package}/rates', [ServiceRateController::class, 'store'])
            ->name('services.packages.rates.store');
        Route::get('/services/{service}/packages/{package}/rates/{rate}', [ServiceRateController::class, 'show'])
            ->name('services.packages.rates.show');
        Route::put('/services/{service}/packages/{package}/rates/{rate}', [ServiceRateController::class, 'update'])
            ->name('services.packages.rates.update');

        Route::apiResource('staff', StaffController::class)->only([
            'index', 'store', 'show', 'update',
        ]);
        Route::get('/booking-services/{bookingService}/staff-availability', [BookingServiceStaffController::class, 'availability'])
            ->name('booking-services.staff-availability');
        Route::get('/booking-services/{bookingService}/staff-assignments', [BookingServiceStaffController::class, 'index'])
            ->name('booking-services.staff-assignments.index');
        Route::put('/booking-services/{bookingService}/staff-assignments', [BookingServiceStaffController::class, 'update'])
            ->name('booking-services.staff-assignments.update');

        Route::post('/bookings/availability', [BookingController::class, 'availability'])
            ->name('bookings.availability');
        Route::post('/bookings/staff-availability', [BookingServiceStaffController::class, 'candidateAvailability'])
            ->name('bookings.staff-availability');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])
            ->name('bookings.cancel');
        Route::apiResource('bookings', BookingController::class)->only([
            'index', 'store', 'show', 'update',
        ]);
    });
});
