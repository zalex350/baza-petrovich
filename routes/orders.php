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

    // Клиент не отменяет заказ сам — только запрашивает отмену
    Route::post(
        '/order/{id}/cancel-request',
        [OrderController::class, 'requestCancel']
    );

    Route::post(
        '/order/{id}/reorder',
        [OrderController::class, 'reorder']
    );

});