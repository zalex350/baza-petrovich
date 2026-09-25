<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminOrderController;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'admin'])->group(function () {

    Route::get(
        '/admin/orders',
        [AdminOrderController::class, 'index']
    );

    Route::get(
        '/admin/order/{id}',
        [AdminOrderController::class, 'show']
    );

    Route::post(
        '/admin/order/{id}',
        [AdminOrderController::class, 'update']
    );

    Route::get(
        '/admin/products',
        [AdminProductController::class, 'index']
    );

    Route::get(
        '/admin/product/create',
        [AdminProductController::class, 'create']
    );

    Route::post(
        '/admin/product',
        [AdminProductController::class, 'store']
    );

    Route::get(
        '/admin/product/{id}',
        [AdminProductController::class, 'show']
    );

    Route::post(
        '/admin/product/{id}',
        [AdminProductController::class, 'update']
    );

    Route::post(
        '/admin/product/{id}/toggle-visibility',
        [AdminProductController::class, 'toggleVisibility']
    );

    Route::post(
        '/admin/product/{id}/delete',
        [AdminProductController::class, 'destroy']
    );
});