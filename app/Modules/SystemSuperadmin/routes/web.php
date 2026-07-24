<?php

use App\Modules\SystemSuperadmin\Http\Controllers\BusinessProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'system_superadmin'])
    ->prefix('system-superadmin/business-profiles')
    ->name('system-superadmin.business-profiles.')
    ->group(function () {
        Route::get('/', [BusinessProfileController::class, 'index'])->name('index');
        Route::post('/drafts', [BusinessProfileController::class, 'store'])->name('drafts.store');
        Route::put('/drafts/{draft}', [BusinessProfileController::class, 'update'])->name('drafts.update');
        Route::post('/drafts/{draft}/apply', [BusinessProfileController::class, 'apply'])->name('drafts.apply');
        Route::delete('/drafts/{draft}', [BusinessProfileController::class, 'destroy'])->name('drafts.destroy');
        Route::post('/presets', [BusinessProfileController::class, 'storePreset'])->name('presets.store');
        Route::post('/presets/{preset}/draft', [BusinessProfileController::class, 'presetToDraft'])->name('presets.draft');
        Route::delete('/presets/{preset}', [BusinessProfileController::class, 'destroyPreset'])->name('presets.destroy');
        Route::post('/versions/{version}/restore', [BusinessProfileController::class, 'restore'])->name('versions.restore');
        Route::put('/sandbox/{session}', [BusinessProfileController::class, 'updateSandbox'])->name('sandbox.update');
        Route::post('/sandbox/{session}/reset', [BusinessProfileController::class, 'resetSandbox'])->name('sandbox.reset');
        Route::post('/sandbox-full/enter', [BusinessProfileController::class, 'enterFullSandbox'])->name('sandbox-full.enter');
        Route::post('/sandbox-full/leave', [BusinessProfileController::class, 'leaveFullSandbox'])->name('sandbox-full.leave');
        Route::delete('/sandbox-full/discard', [BusinessProfileController::class, 'discardFullSandbox'])->name('sandbox-full.discard');
    });
