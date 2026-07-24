<?php

use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Customers\Http\Controllers\CustomerTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:customers'])
    ->prefix('customers')
    ->name('customers.')
    ->group(function () {
        Route::get('/', [CustomerController::class, 'index'])
            ->middleware('permission:customers.view')
            ->name('index');

        Route::get('/create', [CustomerController::class, 'create'])
            ->middleware('permission:customers.manage')
            ->name('create');

        Route::post('/', [CustomerController::class, 'store'])
            ->middleware('permission:customers.manage')
            ->name('store');

        Route::get('/{customer}/statement', [CustomerController::class, 'statement'])
            ->middleware('permission:customers.view')
            ->name('statement');

        Route::post('/{customer}/interactions', [CustomerController::class, 'storeInteraction'])
            ->middleware('permission:customers.manage')
            ->name('interactions.store');

        Route::patch('/{customer}/interactions/{interaction}/complete', [CustomerController::class, 'completeInteraction'])
            ->middleware('permission:customers.manage')
            ->name('interactions.complete');

        Route::get('/{customer}/edit', [CustomerController::class, 'edit'])
            ->middleware('permission:customers.manage')
            ->name('edit');

        Route::put('/{customer}', [CustomerController::class, 'update'])
            ->middleware('permission:customers.manage')
            ->name('update');

        Route::delete('/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('permission:customers.manage')
            ->name('destroy');

        Route::post('/types', [CustomerTypeController::class, 'store'])
            ->middleware('permission:customers.manage')
            ->name('types.store');

        Route::put('/types/{type}', [CustomerTypeController::class, 'update'])
            ->middleware('permission:customers.manage')
            ->name('types.update');

        Route::delete('/types/{type}', [CustomerTypeController::class, 'destroy'])
            ->middleware('permission:customers.manage')
            ->name('types.destroy');
    });
