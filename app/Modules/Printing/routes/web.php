<?php

use App\Modules\Printing\Http\Controllers\PrintingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_capability:uses_printer_profiles'])
    ->prefix('printing')
    ->name('printing.')
    ->group(function () {
        Route::get('/', [PrintingController::class, 'index'])
            ->middleware('permission:printing.view')
            ->name('index');

        Route::post('/templates', [PrintingController::class, 'storeTemplate'])
            ->middleware('permission:printing.manage')
            ->name('templates.store');

        Route::post('/printers', [PrintingController::class, 'storePrinter'])
            ->middleware('permission:printing.manage')
            ->name('printers.store');

        Route::post('/rules', [PrintingController::class, 'storeRule'])
            ->middleware('permission:printing.manage')
            ->name('rules.store');

        Route::post('/jobs', [PrintingController::class, 'queueJob'])
            ->middleware('permission:printing.jobs.manage')
            ->name('jobs.store');

        Route::patch('/jobs/{job}/printed', [PrintingController::class, 'markPrinted'])
            ->middleware('permission:printing.jobs.manage')
            ->name('jobs.printed');
    });
