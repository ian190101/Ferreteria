<?php

use App\Modules\Exports\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:settings.manage'])
    ->prefix('exports')
    ->name('exports.')
    ->group(function () {
        Route::get('/', [ExportController::class, 'index'])->name('index');
        Route::post('/download', [ExportController::class, 'download'])->name('download');
    });
