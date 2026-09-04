<?php

use Illuminate\Support\Facades\Route;
use App\Models\Order;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrdersController;

/*
|--------------------------------------------------------------------------
| Orders Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get(
        '/orders',
        [OrdersController::class, 'index']
    );

    Route::get(
        '/order/{id}',
        [OrdersController::class, 'show']
    );
    Route::post(
        '/order/{id}/delete',
        [OrderController::class, 'delete']
    );

});