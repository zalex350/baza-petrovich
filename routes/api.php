<?php

use App\Http\Controllers\Api\ExchangeController;
use App\Http\Middleware\AuthenticateExchange;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API обмена с 1С v1 — префикс /api/1c/v1 (bootstrap/app.php, apiPrefix)
|--------------------------------------------------------------------------
| docs/1C_EXCHANGE_SPEC.md. Доступ: белый список IP + Bearer-токен (AuthenticateExchange),
| частота — лимитер exchange-1c (AppServiceProvider), ошибки — JSON {"error", "message"}.
| Заказы (потоки 3–5) — этапы 4–5.
*/

Route::middleware([AuthenticateExchange::class, 'throttle:exchange-1c'])->group(function () {
    Route::get('/ping', [ExchangeController::class, 'ping']);
    Route::post('/catalog', [ExchangeController::class, 'catalog']);
    Route::post('/prices-stock', [ExchangeController::class, 'pricesStock']);
    Route::get('/jobs/{id}', [ExchangeController::class, 'job']);
});
