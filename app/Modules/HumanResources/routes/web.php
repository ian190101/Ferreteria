<?php

use App\Modules\HumanResources\Http\Controllers\SalaryPaymentController;
use App\Modules\HumanResources\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('human-resources')
    ->name('human-resources.')
    ->group(function () {
        Route::middleware(['business_feature:workers', 'permission:workers.view'])->group(function () {
            Route::get('/workers', [WorkerController::class, 'index'])->name('workers.index');
        });

        Route::middleware(['business_feature:workers', 'permission:workers.manage'])->group(function () {
            Route::post('/workers', [WorkerController::class, 'store'])->name('workers.store');
            Route::put('/workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
            Route::delete('/workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
        });

        Route::middleware(['business_feature:payroll', 'permission:payroll.view'])->group(function () {
            Route::get('/payroll', [SalaryPaymentController::class, 'index'])->name('payroll.index');
        });

        Route::middleware(['business_feature:payroll', 'permission:payroll.manage'])->group(function () {
            Route::post('/payroll', [SalaryPaymentController::class, 'store'])->name('payroll.store');
            Route::patch('/payroll/{payment}/void', [SalaryPaymentController::class, 'void'])->name('payroll.void');
        });
    });
