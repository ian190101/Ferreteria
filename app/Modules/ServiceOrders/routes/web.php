<?php

use App\Modules\ServiceOrders\Http\Controllers\ServiceOrderController;
use App\Modules\ServiceOrders\Http\Controllers\ServiceTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('service-orders')
    ->name('service-orders.')
    ->group(function () {
        Route::get('/', [ServiceOrderController::class, 'index'])
            ->middleware(['business_feature:services,service_orders', 'permission:service-orders.view'])
            ->name('index');

        Route::get('/types', [ServiceTypeController::class, 'index'])
            ->middleware(['business_feature:services', 'permission:service-orders.view'])
            ->name('types.index');

        Route::post('/types', [ServiceTypeController::class, 'store'])
            ->middleware(['business_feature:services', 'permission:service-orders.manage'])
            ->name('types.store');

        Route::put('/types/{serviceType}', [ServiceTypeController::class, 'update'])
            ->middleware(['business_feature:services', 'permission:service-orders.manage'])
            ->name('types.update');

        Route::delete('/types/{serviceType}', [ServiceTypeController::class, 'destroy'])
            ->middleware(['business_feature:services', 'permission:service-orders.manage'])
            ->name('types.destroy');

        Route::post('/', [ServiceOrderController::class, 'store'])
            ->middleware(['business_feature:service_orders', 'permission:service-orders.manage'])
            ->name('store');

        Route::patch('/{order}/status', [ServiceOrderController::class, 'updateStatus'])
            ->middleware(['business_feature:service_orders', 'permission:service-orders.manage'])
            ->name('status');
    });
