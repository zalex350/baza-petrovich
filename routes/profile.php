<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeliveryAddressController;
use App\Http\Controllers\ProfileController;

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

});

/*
|--------------------------------------------------------------------------
| Адреса доставки организации (UC-032) — только для клиента
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:client'])->group(function () {

    Route::get('/addresses', [DeliveryAddressController::class, 'index']);

    Route::get('/addresses/create', [DeliveryAddressController::class, 'create']);

    Route::post('/addresses', [DeliveryAddressController::class, 'store']);

    Route::get('/addresses/{id}/edit', [DeliveryAddressController::class, 'edit']);

    Route::post('/addresses/{id}', [DeliveryAddressController::class, 'update']);

    Route::post('/addresses/{id}/default', [DeliveryAddressController::class, 'makeDefault']);

    Route::post('/addresses/{id}/delete', [DeliveryAddressController::class, 'destroy']);

});
