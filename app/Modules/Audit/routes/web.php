<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:audit.view'])
    ->prefix('audit')
    ->name('audit.')
    ->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('index');
    });
