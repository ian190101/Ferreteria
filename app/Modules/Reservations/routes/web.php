<?php

use App\Modules\Reservations\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('reservations')
    ->name('reservations.')
    ->group(function () {
        Route::get('/', [ReservationController::class, 'index'])
            ->middleware(['business_capability:uses_reservations,uses_reservable_resources,uses_rentals', 'permission:reservations.view'])
            ->name('index');

        Route::post('/', [ReservationController::class, 'store'])
            ->middleware(['business_capability:uses_reservations,uses_reservable_resources,uses_rentals', 'permission:reservations.manage'])
            ->name('store');

        Route::patch('/{reservation}/status', [ReservationController::class, 'updateStatus'])
            ->middleware(['business_capability:uses_reservations,uses_reservable_resources,uses_rentals', 'permission:reservations.manage'])
            ->name('status');

        Route::patch('/{reservation}/schedule', [ReservationController::class, 'reschedule'])
            ->middleware(['business_capability:uses_reservations,uses_reservable_resources,uses_rentals', 'permission:reservations.manage'])
            ->name('schedule');
    });
