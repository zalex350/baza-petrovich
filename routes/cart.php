<?php

use Illuminate\Support\Facades\Route;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Http\Controllers\CartController;

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

    Route::post(
        '/order/checkout',
        [CartController::class, 'checkout']
    );

});