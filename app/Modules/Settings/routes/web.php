<?php

use App\Modules\Settings\Http\Controllers\SystemController;
use App\Modules\Settings\Http\Controllers\SystemInfoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:settings.manage'])
    ->get('settings/info', [SystemInfoController::class, 'index'])
    ->name('settings.info.index');

Route::middleware(['auth', 'verified', 'permission:settings.manage'])
    ->prefix('settings/system')
    ->name('settings.system.')
    ->group(function () {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::post('/backups', [SystemController::class, 'backup'])->name('backups.store');
        Route::get('/backups/{backup}/download', [SystemController::class, 'downloadBackup'])->name('backups.download');
    });
