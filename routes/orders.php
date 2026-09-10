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
    Route::post(
        '/order/{id}/delete',
        [OrderController::class, 'delete']
    );

});