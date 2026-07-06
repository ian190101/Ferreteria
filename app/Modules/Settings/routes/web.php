<?php

use App\Modules\Settings\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:settings.manage'])
    ->prefix('settings/system')
    ->name('settings.system.')
    ->group(function () {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::put('/', [SystemController::class, 'update'])->name('update');
        Route::post('/backups', [SystemController::class, 'backup'])->name('backups.store');
        Route::get('/backups/{backup}/download', [SystemController::class, 'downloadBackup'])->name('backups.download');
    });
