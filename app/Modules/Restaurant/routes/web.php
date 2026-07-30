<?php

use App\Modules\Restaurant\Http\Controllers\RestaurantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('restaurant')
    ->name('restaurant.')
    ->group(function () {
        Route::get('/', [RestaurantController::class, 'index'])
            ->middleware(['business_feature:restaurant_tables,kitchen_orders,recipes', 'permission:restaurant.view'])
            ->name('index');

        Route::post('/tables', [RestaurantController::class, 'storeTable'])
            ->middleware(['business_feature:restaurant_tables', 'permission:restaurant.manage'])
            ->name('tables.store');

        Route::patch('/tables/{table}', [RestaurantController::class, 'updateTable'])
            ->middleware(['business_feature:restaurant_tables', 'permission:restaurant.manage'])
            ->name('tables.update');

        Route::post('/recipes', [RestaurantController::class, 'storeRecipe'])
            ->middleware(['business_feature:recipes', 'permission:restaurant.manage'])
            ->name('recipes.store');

        Route::post('/orders', [RestaurantController::class, 'storeOrder'])
            ->middleware(['business_feature:kitchen_orders', 'permission:restaurant.manage'])
            ->name('orders.store');

        Route::patch('/orders/{order}/status', [RestaurantController::class, 'updateOrderStatus'])
            ->middleware(['business_feature:kitchen_orders', 'permission:restaurant.manage'])
            ->name('orders.status');
    });
