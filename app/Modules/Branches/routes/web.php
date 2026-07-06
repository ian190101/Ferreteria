<?php

use App\Modules\Branches\Http\Controllers\BranchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('branches')
    ->name('branches.')
    ->group(function () {
        Route::get('/', [BranchController::class, 'index'])
            ->middleware('permission:branches.view')
            ->name('index');

        Route::middleware('permission:branches.manage')->group(function () {
            Route::get('/create', [BranchController::class, 'create'])->name('create');
            Route::post('/', [BranchController::class, 'store'])->name('store');
            Route::get('/{branch}/edit', [BranchController::class, 'edit'])->name('edit');
            Route::put('/{branch}', [BranchController::class, 'update'])->name('update');
            Route::delete('/{branch}', [BranchController::class, 'destroy'])->name('destroy');
        });
    });
