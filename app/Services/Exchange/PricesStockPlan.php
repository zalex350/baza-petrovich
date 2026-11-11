<?php

namespace App\Services\Exchange;

/**
 * План загрузки цен и остатков (PricesStockHandler::plan): что будет записано, без записи.
 * Для предпросмотра ручной загрузки и для самой записи.
 */
class PricesStockPlan
{
    /** @var array<int, array<string, mixed>> id товара => колонки products для записи */
    public array $changes = [];

    /**
     * Товары, у которых меняется цена, остаток 1С или «под заказ»: id => [было, стало].
     *
     * @var array<int, array{before: array{price: ?string, stock_1c: int, on_order: bool}, after: array{price: ?string, stock_1c: int, on_order: bool}}>
     */
    public array $updated = [];

    /** Строк, применённых без изменения значений (обновится только время обмена) */
    public int $unchanged = 0;

    /** @var list<int> mode=full: товары с GUID, которым обнулится остаток */
    public array $zeroed = [];

    public function __construct(public ExchangeResult $result) {}
}
