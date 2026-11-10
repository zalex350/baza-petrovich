<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DeliveryAddressController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Profile Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    // Удаления своей учётной записи нет: B2B-учётки блокирует администратор

    // Смена пароля (SRS §8.13): форма; сохраняет PUT /password (Auth\PasswordController)
    Route::get('/account/password', [AccountController::class, 'password']);

});

/*
|--------------------------------------------------------------------------
| Адреса доставки организации (UC-032) — только для клиента
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:client'])->group(function () {

    // Организация и её пользователи — только просмотр (SRS §8.5, UC-031)
    Route::get('/account/organization', [AccountController::class, 'organization']);
    Route::get('/account/users', [AccountController::class, 'users']);

    // Выгрузка ассортимента в .xlsx — только у организации с кодами товаров (docs/MODULES.md §11)
    Route::get('/account/assortment', [AccountController::class, 'assortment']);
    Route::get('/account/assortment/download', [AccountController::class, 'assortmentDownload']);

    Route::get('/addresses', [DeliveryAddressController::class, 'index']);

    Route::get('/addresses/create', [DeliveryAddressController::class, 'create']);

    Route::post('/addresses', [DeliveryAddressController::class, 'store']);

    Route::get('/addresses/{id}/edit', [DeliveryAddressController::class, 'edit']);

    Route::post('/addresses/{id}', [DeliveryAddressController::class, 'update']);

    Route::post('/addresses/{id}/default', [DeliveryAddressController::class, 'makeDefault']);

    Route::post('/addresses/{id}/delete', [DeliveryAddressController::class, 'destroy']);

});
