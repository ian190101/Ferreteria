<?php

use App\Modules\Sales\Http\Controllers\DeliveryNoteController;
use App\Modules\Sales\Http\Controllers\ReceiptTemplateController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Sales\Http\Controllers\SaleReturnController;
use App\Modules\Sales\Http\Controllers\SalesSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('sales')
    ->name('sales.')
    ->group(function () {
        Route::get('/', [SaleController::class, 'index'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.view'])
            ->name('index');

        Route::get('/create', [SaleController::class, 'create'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.manage'])
            ->name('create');
        Route::post('/', [SaleController::class, 'store'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.manage'])
            ->name('store');
        Route::patch('/{sale}/void', [SaleController::class, 'void'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.manage'])
            ->name('void');
        Route::post('/{sale}/convert', [SaleController::class, 'convert'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.manage'])
            ->name('convert');

        Route::get('/returns', [SaleReturnController::class, 'index'])
            ->middleware(['business_feature:returns', 'permission:sales.returns.view'])
            ->name('returns.index');
        Route::post('/returns', [SaleReturnController::class, 'store'])
            ->middleware(['business_feature:returns', 'permission:sales.returns.manage'])
            ->name('returns.store');

        Route::get('/deliveries', [DeliveryNoteController::class, 'index'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.view'])
            ->name('deliveries.index');
        Route::post('/deliveries', [DeliveryNoteController::class, 'store'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.manage'])
            ->name('deliveries.store');
        Route::post('/deliveries/drivers', [DeliveryNoteController::class, 'storeDriver'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.manage'])
            ->name('deliveries.drivers.store');
        Route::put('/deliveries/drivers/{driver}', [DeliveryNoteController::class, 'updateDriver'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.manage'])
            ->name('deliveries.drivers.update');
        Route::post('/deliveries/trucks', [DeliveryNoteController::class, 'storeTruck'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.manage'])
            ->name('deliveries.trucks.store');
        Route::put('/deliveries/trucks/{truck}', [DeliveryNoteController::class, 'updateTruck'])
            ->middleware(['business_feature:deliveries', 'permission:sales.deliveries.manage'])
            ->name('deliveries.trucks.update');

        Route::get('/settings/catalogs', [SalesSettingController::class, 'index'])
            ->middleware(['business_feature:sales_notes', 'permission:settings.manage'])
            ->name('settings.index');
        Route::post('/settings/catalogs', [SalesSettingController::class, 'store'])
            ->middleware(['business_feature:sales_notes', 'permission:settings.manage'])
            ->name('settings.store');
        Route::put('/settings/catalogs/{kind}/{setting}', [SalesSettingController::class, 'update'])
            ->whereIn('kind', ['sale_type', 'currency', 'advance_option', 'document_sequence'])
            ->middleware(['business_feature:sales_notes', 'permission:settings.manage'])
            ->name('settings.update');
        Route::put('/settings/catalogs/decimals', [SalesSettingController::class, 'updateDecimals'])
            ->middleware(['business_feature:sales_notes', 'permission:settings.manage'])
            ->name('settings.decimals.update');
        Route::delete('/settings/catalogs/{kind}/{setting}', [SalesSettingController::class, 'destroy'])
            ->whereIn('kind', ['sale_type', 'currency', 'advance_option', 'document_sequence'])
            ->middleware(['business_feature:sales_notes', 'permission:settings.manage'])
            ->name('settings.destroy');

        Route::middleware(['business_feature:sales_notes', 'permission:settings.manage'])->group(function () {
            Route::get('/templates', [ReceiptTemplateController::class, 'index'])->name('templates.index');
            Route::get('/templates/create', [ReceiptTemplateController::class, 'create'])->name('templates.create');
            Route::post('/templates', [ReceiptTemplateController::class, 'store'])->name('templates.store');
            Route::get('/templates/{template}/edit', [ReceiptTemplateController::class, 'edit'])->name('templates.edit');
            Route::put('/templates/{template}', [ReceiptTemplateController::class, 'update'])->name('templates.update');
            Route::delete('/templates/{template}', [ReceiptTemplateController::class, 'destroy'])->name('templates.destroy');
        });

        Route::get('/{sale}', [SaleController::class, 'show'])
            ->middleware(['business_feature:sales_notes', 'permission:sales.view'])
            ->name('show');
    });
