<?php

use App\Modules\Billing\Http\Controllers\BillingDashboardController;
use App\Modules\Billing\Http\Controllers\SiatCodeController;
use App\Modules\Billing\Http\Controllers\SiatEventController;
use App\Modules\Billing\Http\Controllers\SiatInvoiceController;
use App\Modules\Billing\Http\Controllers\SiatProductMappingController;
use App\Modules\Billing\Http\Controllers\SiatSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:billing'])
    ->prefix('billing')
    ->name('billing.')
    ->group(function () {
        Route::get('/', BillingDashboardController::class)
            ->middleware('permission:billing.view')
            ->name('index');

        Route::middleware('permission:billing.manage')->group(function () {
            Route::get('/settings', [SiatSettingController::class, 'index'])->name('settings.index');
            Route::post('/settings', [SiatSettingController::class, 'store'])->name('settings.store');
            Route::post('/codes/cuis', [SiatCodeController::class, 'cuis'])->name('codes.cuis');
            Route::post('/codes/cufd', [SiatCodeController::class, 'cufd'])->name('codes.cufd');
            Route::post('/catalogs/sync', [SiatCodeController::class, 'syncCatalogs'])->name('catalogs.sync');
            Route::get('/products', [SiatProductMappingController::class, 'index'])->name('products.index');
            Route::post('/products/{product}', [SiatProductMappingController::class, 'store'])->name('products.store');
            Route::post('/sales/{sale}/issue', [SiatInvoiceController::class, 'issue'])->name('sales.issue');
            Route::patch('/invoices/{invoice}/void', [SiatInvoiceController::class, 'void'])->name('invoices.void');
            Route::get('/events', [SiatEventController::class, 'index'])->name('events.index');
            Route::post('/events', [SiatEventController::class, 'store'])->name('events.store');
            Route::post('/events/{event}/package', [SiatEventController::class, 'package'])->name('events.package');
        });

        Route::get('/invoices/{invoice}', [SiatInvoiceController::class, 'show'])
            ->middleware('permission:billing.view')
            ->name('invoices.show');
    });
