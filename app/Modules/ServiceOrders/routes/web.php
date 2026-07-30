<?php

use App\Modules\ServiceOrders\Http\Controllers\ServiceOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('service-orders')
    ->name('service-orders.')
    ->group(function () {
        Route::get('/', [ServiceOrderController::class, 'index'])
            ->middleware(['business_feature:services,service_orders', 'permission:service-orders.view'])
            ->name('index');

        Route::post('/', [ServiceOrderController::class, 'store'])
            ->middleware(['business_feature:service_orders', 'permission:service-orders.manage'])
            ->name('store');

        Route::patch('/{order}/status', [ServiceOrderController::class, 'updateStatus'])
            ->middleware(['business_feature:service_orders', 'permission:service-orders.manage'])
            ->name('status');
    });
