<?php

use App\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cart Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get(
        '/order',
        [CartController::class, 'index']
    );

    Route::post(
        '/order/add/{id}',
        [CartController::class, 'add']
    );

    Route::post(
        '/order/update/{id}',
        [CartController::class, 'update']
    );

    Route::post(
        '/order/delete/{id}',
        [CartController::class, 'delete']
    );

    // Галочки «в заказ», очистка, «Быстро добавить» (страница корзины)
    Route::post('/order/select/{id}', [CartController::class, 'select']);
    Route::post('/order/select-all', [CartController::class, 'selectAll']);
    Route::post('/order/clear', [CartController::class, 'clear']);
    Route::post('/order/quick-add', [CartController::class, 'quickAdd']);

    // Отдельной страницы оформления нет — оформление в корзине
    Route::get('/order/checkout', fn () => redirect('/order'));

    Route::post(
        '/order/accept-prices',
        [CartController::class, 'acceptPrices']
    );

    Route::post(
        '/order/checkout',
        [CartController::class, 'checkout']
    );

});
