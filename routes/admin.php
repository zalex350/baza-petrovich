<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminOrganizationController;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:admin'])->group(function () {

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

    Route::post(
        '/admin/order/{id}/status',
        [AdminOrderController::class, 'changeStatus']
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
        '/admin/product/{id}/preview',
        [AdminProductController::class, 'preview']
    );

    Route::post(
        '/admin/product/{id}/draft',
        [AdminProductController::class, 'saveDraft']
    );

    Route::post(
        '/admin/product/{id}/publish',
        [AdminProductController::class, 'publish']
    );

    Route::post(
        '/admin/product/{id}/draft/delete',
        [AdminProductController::class, 'deleteDraft']
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

    Route::get(
        '/admin/organizations',
        [AdminOrganizationController::class, 'index']
    );

    Route::get(
        '/admin/organization/create',
        [AdminOrganizationController::class, 'create']
    );

    Route::post(
        '/admin/organization',
        [AdminOrganizationController::class, 'store']
    );

    Route::get(
        '/admin/organization/{id}',
        [AdminOrganizationController::class, 'show']
    );

    Route::get(
        '/admin/organization/{id}/edit',
        [AdminOrganizationController::class, 'edit']
    );

    Route::post(
        '/admin/organization/{id}',
        [AdminOrganizationController::class, 'update']
    );

    Route::post(
        '/admin/organization/{id}/delete',
        [AdminOrganizationController::class, 'destroy']
    );

    Route::get(
        '/admin/users',
        [AdminUserController::class, 'index']
    );

    Route::get(
        '/admin/user/create',
        [AdminUserController::class, 'create']
    );

    Route::post(
        '/admin/user',
        [AdminUserController::class, 'store']
    );

    Route::get(
        '/admin/user/{id}',
        [AdminUserController::class, 'show']
    );

    Route::get(
        '/admin/user/{id}/edit',
        [AdminUserController::class, 'edit']
    );

    Route::post(
        '/admin/user/{id}',
        [AdminUserController::class, 'update']
    );

    Route::post(
        '/admin/user/{id}/block',
        [AdminUserController::class, 'block']
    );

    Route::post(
        '/admin/user/{id}/unblock',
        [AdminUserController::class, 'unblock']
    );

    Route::post(
        '/admin/user/{id}/reset-password',
        [AdminUserController::class, 'resetPassword']
    );
});