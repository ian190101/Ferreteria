<?php

use App\Modules\Payments\Http\Controllers\CreditNotes\CreditNoteController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\PaymentMethodController;
use App\Modules\Payments\Http\Controllers\Promises\PaymentPromiseController;
use App\Modules\Payments\Http\Controllers\PurchasePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('payments')
    ->name('payments.')
    ->group(function () {
        Route::get('/', [PaymentController::class, 'index'])
            ->middleware('permission:payments.view')
            ->name('index');

        Route::post('/', [PaymentController::class, 'store'])
            ->middleware('permission:payments.manage')
            ->name('store');

        Route::patch('/{payment}/void', [PaymentController::class, 'void'])
            ->middleware('permission:payments.manage')
            ->name('void');

        Route::get('/purchase-payments', [PurchasePaymentController::class, 'index'])
            ->middleware('permission:payments.view')
            ->name('purchase-payments.index');
        Route::post('/purchase-payments', [PurchasePaymentController::class, 'store'])
            ->middleware('permission:payments.manage')
            ->name('purchase-payments.store');
        Route::patch('/purchase-payments/{payment}/void', [PurchasePaymentController::class, 'void'])
            ->middleware('permission:payments.manage')
            ->name('purchase-payments.void');

        Route::get('/credit-notes', [CreditNoteController::class, 'index'])
            ->middleware('permission:credit-notes.view')
            ->name('credit-notes.index');
        Route::post('/credit-notes', [CreditNoteController::class, 'store'])
            ->middleware('permission:credit-notes.manage')
            ->name('credit-notes.store');
        Route::patch('/credit-notes/{creditNote}/void', [CreditNoteController::class, 'void'])
            ->middleware('permission:credit-notes.manage')
            ->name('credit-notes.void');

        Route::get('/promises', [PaymentPromiseController::class, 'index'])
            ->middleware('permission:payment-promises.view')
            ->name('promises.index');
        Route::post('/promises', [PaymentPromiseController::class, 'store'])
            ->middleware('permission:payment-promises.manage')
            ->name('promises.store');
        Route::patch('/promises/{promise}/resolve', [PaymentPromiseController::class, 'resolve'])
            ->middleware('permission:payment-promises.manage')
            ->name('promises.resolve');

        Route::post('/methods', [PaymentMethodController::class, 'store'])
            ->middleware('permission:payments.manage')
            ->name('methods.store');

        Route::put('/methods/{method}', [PaymentMethodController::class, 'update'])
            ->middleware('permission:payments.manage')
            ->name('methods.update');

        Route::delete('/methods/{method}', [PaymentMethodController::class, 'destroy'])
            ->middleware('permission:payments.manage')
            ->name('methods.destroy');
    });
