<?php

use App\Modules\Expenses\Http\Controllers\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('expenses')
    ->name('expenses.')
    ->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])
            ->middleware('permission:expenses.view')
            ->name('index');

        Route::post('/', [ExpenseController::class, 'store'])
            ->middleware('permission:expenses.manage')
            ->name('store');

        Route::patch('/{expense}/void', [ExpenseController::class, 'void'])
            ->middleware('permission:expenses.manage')
            ->name('void');

        Route::post('/categories', [ExpenseCategoryController::class, 'store'])
            ->middleware('permission:expenses.manage')
            ->name('categories.store');

        Route::put('/categories/{category}', [ExpenseCategoryController::class, 'update'])
            ->middleware('permission:expenses.manage')
            ->name('categories.update');

        Route::delete('/categories/{category}', [ExpenseCategoryController::class, 'destroy'])
            ->middleware('permission:expenses.manage')
            ->name('categories.destroy');
    });
