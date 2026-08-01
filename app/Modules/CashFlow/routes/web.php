<?php

use App\Modules\CashFlow\Http\Controllers\CashFlowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:cash_flow', 'permission:cash-flow.view'])
    ->get('/cash-flow', CashFlowController::class)
    ->name('cash-flow.index');
