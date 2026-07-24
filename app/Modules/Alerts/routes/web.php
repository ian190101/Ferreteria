<?php

use App\Modules\Alerts\Http\Controllers\AlertController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:alerts', 'permission:alerts.view'])
    ->prefix('alerts')
    ->name('alerts.')
    ->group(function () {
        Route::get('/', AlertController::class)->name('index');
    });
