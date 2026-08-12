<?php

use App\Modules\SystemSuperadmin\Http\Controllers\BusinessProfileController;
use App\Modules\SystemSuperadmin\Http\Controllers\BusinessTransversalController;
use App\Modules\SystemSuperadmin\Http\Controllers\CustomerQrOrderOperationalController;
use App\Modules\SystemSuperadmin\Http\Controllers\CustomerQrOrderingController;
use Illuminate\Support\Facades\Route;

Route::prefix('pedidos-qr')
    ->name('customer-qr-ordering.')
    ->group(function () {
        Route::get('/{token}', [CustomerQrOrderingController::class, 'show'])->name('show');
        Route::post('/{token}', [CustomerQrOrderingController::class, 'store'])->middleware('throttle:customer-qr-ordering')->name('store');
        Route::get('/{token}/qr.svg', [CustomerQrOrderingController::class, 'svg'])->middleware('throttle:60,1')->name('svg');
    });

Route::middleware(['auth', 'verified', 'business_feature:customer_qr_ordering'])
    ->prefix('customer-qr-orders')
    ->name('customer-qr-orders.')
    ->group(function () {
        Route::get('/', [CustomerQrOrderOperationalController::class, 'index'])
            ->middleware('permission:customer-qr-orders.view')
            ->name('index');
        Route::post('/{order}/accept', [CustomerQrOrderOperationalController::class, 'accept'])
            ->middleware('permission:customer-qr-orders.accept')
            ->name('accept');
        Route::post('/{order}/reject', [CustomerQrOrderOperationalController::class, 'reject'])
            ->middleware('permission:customer-qr-orders.reject')
            ->name('reject');
        Route::patch('/{order}/status', [CustomerQrOrderOperationalController::class, 'status'])
            ->middleware('permission:customer-qr-orders.accept')
            ->name('status');
        Route::post('/{order}/convert', [CustomerQrOrderOperationalController::class, 'convert'])
            ->middleware('permission:customer-qr-orders.convert')
            ->name('convert');
    });

Route::middleware(['auth', 'verified', 'system_superadmin'])
    ->prefix('system-superadmin/business-profiles')
    ->name('system-superadmin.business-profiles.')
    ->group(function () {
        Route::get('/', [BusinessProfileController::class, 'index'])->name('index');
        Route::post('/drafts', [BusinessProfileController::class, 'store'])->name('drafts.store');
        Route::put('/drafts/{draft}', [BusinessProfileController::class, 'update'])->name('drafts.update');
        Route::post('/drafts/{draft}/use-preview', [BusinessProfileController::class, 'useDraftPreview'])->name('drafts.use-preview');
        Route::post('/drafts/{draft}/apply', [BusinessProfileController::class, 'apply'])->name('drafts.apply');
        Route::delete('/drafts/{draft}', [BusinessProfileController::class, 'destroy'])->name('drafts.destroy');
        Route::delete('/draft-preview', [BusinessProfileController::class, 'stopDraftPreview'])->name('draft-preview.stop');
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

Route::middleware(['auth', 'verified', 'system_superadmin'])
    ->prefix('system-superadmin/transversal-config')
    ->name('system-superadmin.transversal-config.')
    ->group(function () {
        Route::get('/', [BusinessTransversalController::class, 'index'])->name('index');
        Route::post('/approval-requests/{approvalRequest}/approve', [BusinessTransversalController::class, 'approveRequest'])->name('approval-requests.approve');
        Route::post('/approval-requests/{approvalRequest}/reject', [BusinessTransversalController::class, 'rejectRequest'])->name('approval-requests.reject');
        Route::post('/{section}', [BusinessTransversalController::class, 'store'])->name('store');
        Route::put('/{section}/{record}', [BusinessTransversalController::class, 'update'])->name('update');
        Route::delete('/{section}/{record}', [BusinessTransversalController::class, 'destroy'])->name('destroy');
    });
