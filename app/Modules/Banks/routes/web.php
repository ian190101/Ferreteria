<?php

use App\Modules\Banks\Http\Controllers\BankController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:banks'])
    ->prefix('banks')
    ->name('banks.')
    ->group(function () {
        Route::get('/', [BankController::class, 'index'])
            ->middleware('permission:banks.view')
            ->name('index');

        Route::middleware('permission:banks.manage')->group(function () {
            Route::post('/accounts', [BankController::class, 'storeAccount'])->name('accounts.store');
            Route::put('/accounts/{account}', [BankController::class, 'updateAccount'])->name('accounts.update');
            Route::post('/transactions', [BankController::class, 'storeTransaction'])->name('transactions.store');
            Route::patch('/transactions/{transaction}/reconcile', [BankController::class, 'reconcile'])->name('transactions.reconcile');
            Route::patch('/transactions/{transaction}/void', [BankController::class, 'void'])->name('transactions.void');
        });
    });
