<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders Routes
|--------------------------------------------------------------------------
*/

// Заказ гостя розницы — по секретной ссылке из оформления (orders.public_token)
Route::get('/o/{token}', [OrderController::class, 'guestShow'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('order.guest');

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

    // Счёт на оплату (SRS §8.10, UC-035)
    Route::get(
        '/order/{id}/invoice',
        [OrderController::class, 'invoice']
    );

    Route::post(
        '/order/{id}/reorder',
        [OrderController::class, 'reorder']
    );

});
