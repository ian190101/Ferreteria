<?php

use App\Modules\Production\Http\Controllers\ProductionOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:production'])
    ->prefix('production')
    ->name('production.')
    ->group(function () {
        Route::get('/', [ProductionOrderController::class, 'index'])
            ->middleware('permission:production.view')
            ->name('index');

        Route::post('/', [ProductionOrderController::class, 'store'])
            ->middleware('permission:production.manage')
            ->name('store');
    });
