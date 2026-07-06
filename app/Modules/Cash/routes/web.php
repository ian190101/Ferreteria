<?php

use App\Modules\Cash\Http\Controllers\CashRegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('cash')
    ->name('cash.')
    ->group(function () {
        Route::get('/', [CashRegisterController::class, 'index'])
            ->middleware('permission:cash.view')
            ->name('index');

        Route::post('/open', [CashRegisterController::class, 'open'])
            ->middleware('permission:cash.manage')
            ->name('open');

        Route::put('/{cashSession}/close', [CashRegisterController::class, 'close'])
            ->middleware('permission:cash.manage')
            ->name('close');
    });
