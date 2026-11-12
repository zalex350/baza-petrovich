<?php

namespace App\Services\Exchange;

use App\Models\ExchangeJob;

/**
 * Обработчик принятого файла одного типа (номенклатура, цены и остатки, статусы).
 * Читает файл $path, применяет строки, ошибки строк пишет через $result->error(),
 * счётчики — в $result. Исключение — задание failed (данные сайта откатываются транзакцией
 * обработчика). Обработчики — CatalogHandler, PricesStockHandler; заказы (этап 5) — пока StubHandler.
 */
interface ExchangeHandler
{
    public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void;
}
