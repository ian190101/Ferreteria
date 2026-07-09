<?php

use App\Modules\Inventory\Http\Controllers\Adjustments\InventoryAdjustmentController;
use App\Modules\Inventory\Http\Controllers\Movements\InventoryMovementController;
use App\Modules\Inventory\Http\Controllers\ProductCoilController;
use App\Modules\Inventory\Http\Controllers\ProductCatalogController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\Reservations\InventoryReservationController;
use App\Modules\Inventory\Http\Controllers\StockOverviewController;
use App\Modules\Inventory\Http\Controllers\ThicknessController;
use App\Modules\Inventory\Http\Controllers\Transfers\InventoryTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        Route::middleware('permission:inventory.products.view')->group(function () {
            Route::get('/stock', StockOverviewController::class)->name('stock.index');
            Route::get('/products', [ProductController::class, 'index'])->name('products.index');
            Route::get('/thicknesses', [ThicknessController::class, 'index'])->name('thicknesses.index');
        });

        Route::middleware('permission:inventory.products.manage')->group(function () {
            Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::get('/products/catalogs', [ProductCatalogController::class, 'index'])->name('products.catalogs.index');
            Route::post('/products/catalogs/units', [ProductCatalogController::class, 'storeUnit'])->name('products.catalogs.units.store');
            Route::put('/products/catalogs/units/{unit}', [ProductCatalogController::class, 'updateUnit'])->name('products.catalogs.units.update');
            Route::post('/products/catalogs/thicknesses', [ProductCatalogController::class, 'storeThickness'])->name('products.catalogs.thicknesses.store');
            Route::put('/products/catalogs/thicknesses/{thickness}', [ProductCatalogController::class, 'updateThickness'])->name('products.catalogs.thicknesses.update');
            Route::post('/products/catalogs/categories', [ProductCatalogController::class, 'storeCategory'])->name('products.catalogs.categories.store');
            Route::put('/products/catalogs/categories/{category}', [ProductCatalogController::class, 'updateCategory'])->name('products.catalogs.categories.update');

            Route::get('/thicknesses/create', [ThicknessController::class, 'create'])->name('thicknesses.create');
            Route::post('/thicknesses', [ThicknessController::class, 'store'])->name('thicknesses.store');
            Route::get('/thicknesses/{thickness}/edit', [ThicknessController::class, 'edit'])->name('thicknesses.edit');
            Route::put('/thicknesses/{thickness}', [ThicknessController::class, 'update'])->name('thicknesses.update');
            Route::delete('/thicknesses/{thickness}', [ThicknessController::class, 'destroy'])->name('thicknesses.destroy');
        });

        Route::middleware('permission:inventory.coils.manage')->group(function () {
            Route::get('/coils', [ProductCoilController::class, 'index'])->name('coils.index');
            Route::get('/coils/create', [ProductCoilController::class, 'create'])->name('coils.create');
            Route::post('/coils', [ProductCoilController::class, 'store'])->name('coils.store');
        });

        Route::get('/adjustments', [InventoryAdjustmentController::class, 'index'])
            ->middleware('permission:inventory.adjustments.view')
            ->name('adjustments.index');

        Route::post('/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->middleware('permission:inventory.adjustments.manage')
            ->name('adjustments.store');

        Route::get('/movements', [InventoryMovementController::class, 'index'])
            ->middleware('permission:inventory.movements.view')
            ->name('movements.index');

        Route::get('/transfers', [InventoryTransferController::class, 'index'])
            ->middleware('permission:inventory.transfers.view')
            ->name('transfers.index');

        Route::post('/transfers', [InventoryTransferController::class, 'store'])
            ->middleware('permission:inventory.transfers.manage')
            ->name('transfers.store');

        Route::get('/reservations', [InventoryReservationController::class, 'index'])
            ->middleware('permission:inventory.reservations.view')
            ->name('reservations.index');

        Route::post('/reservations', [InventoryReservationController::class, 'store'])
            ->middleware('permission:inventory.reservations.manage')
            ->name('reservations.store');

        Route::patch('/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])
            ->middleware('permission:inventory.reservations.manage')
            ->name('reservations.release');
    });
