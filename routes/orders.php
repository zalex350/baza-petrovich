<?php

use Illuminate\Support\Facades\Route;
use App\Models\Order;
use App\Http\Controllers\OrderController;

/*
|--------------------------------------------------------------------------
| Orders Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get(
        '/orders',
        [OrderController::class, 'index']
    );

    Route::get(
        '/order/{id}',
        [OrderController::class, 'show']
    );

    // Страница успешного оформления
    Route::get(
        '/order/{id}/placed',
        [OrderController::class, 'placed']
    );

    Route::post(
        '/order/{id}/cancel',
        [OrderController::class, 'cancel']
    );

    Route::post(
        '/order/{id}/reorder',
        [OrderController::class, 'reorder']
    );

});