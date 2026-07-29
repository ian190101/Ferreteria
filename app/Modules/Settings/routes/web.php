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
        Route::post('/backups/{backup}/verify', [SystemController::class, 'verifyBackup'])->name('backups.verify');
        Route::post('/backups/{backup}/restore', [SystemController::class, 'restoreBackup'])
            ->middleware('permission:production.rollback.manage')
            ->name('backups.restore');
        Route::put('/production', [SystemController::class, 'updateProductionSettings'])->name('production.update');
        Route::put('/fine-permissions', [SystemController::class, 'updateFinePermissions'])->name('fine-permissions.update');
        Route::post('/alerts/test', [SystemController::class, 'testAlert'])->name('alerts.test');
        Route::post('/imports', [SystemController::class, 'import'])->name('imports.store');
        Route::post('/setup-wizard', [SystemController::class, 'runSetupWizard'])
            ->middleware('permission:setup.wizard.manage')
            ->name('setup-wizard.run');
        Route::post('/licenses', [SystemController::class, 'storeLicense'])
            ->middleware('system_superadmin')
            ->name('licenses.store');
        Route::post('/updates/prepare', [SystemController::class, 'prepareUpdate'])
            ->middleware('system_superadmin')
            ->name('updates.prepare');
        Route::post('/updates/migrate', [SystemController::class, 'applyMigrations'])
            ->middleware('system_superadmin')
            ->name('updates.migrate');
    });
