<?php

use App\Modules\Purchases\Http\Controllers\PurchaseController;
use App\Modules\Purchases\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchases\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('purchases')
    ->name('purchases.')
    ->group(function () {
        Route::middleware(['business_feature:purchases', 'permission:purchases.view'])->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])->name('index');
            Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        });

        Route::middleware(['business_feature:purchases', 'permission:purchases.manage'])->group(function () {
            Route::get('/create', [PurchaseController::class, 'create'])->name('create');
            Route::post('/', [PurchaseController::class, 'store'])->name('store');
            Route::get('/orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
            Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
            Route::patch('/orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
            Route::patch('/orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
            Route::get('/orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->name('orders.receive');
            Route::post('/orders/{order}/receipts', [PurchaseOrderController::class, 'storeReceipt'])->name('orders.receipts.store');
            Route::post('/orders/{order}/convert', [PurchaseOrderController::class, 'convert'])->name('orders.convert');
        });

        Route::middleware(['business_feature:suppliers', 'permission:purchases.view'])->group(function () {
            Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('/suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->name('suppliers.statement');
        });

        Route::middleware(['business_feature:suppliers', 'permission:purchases.manage'])->group(function () {
            Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
            Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        });

        Route::get('/{purchase}', [PurchaseController::class, 'show'])
            ->middleware(['business_feature:purchases', 'permission:purchases.view'])
            ->name('show');
    });
