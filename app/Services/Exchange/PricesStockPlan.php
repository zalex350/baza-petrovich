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
     * Цены по типам для записи в product_prices: id товара => [код типа => цена без НДС или null
     * (пусто в файле — цены этого типа нет)]. Типа нет в файле — не трогается.
     *
     * @var array<int, array<string, ?string>>
     */
    public array $prices = [];

    /** @var list<string> коды новых типов цен (колонки price_<код>): создаются скрытыми */
    public array $newTypes = [];

    /** @var list<string> коды типов цен, которые есть в файле (в порядке справочника, новые — в конце) */
    public array $priceCodes = [];

    /**
     * Товары, у которых меняется цена какого-либо типа, остаток 1С или «под заказ»: id => [было, стало].
     *
     * @var array<int, array{before: array{prices: array<string, ?string>, stock_1c: int, on_order: bool}, after: array{prices: array<string, ?string>, stock_1c: int, on_order: bool}}>
     */
    public array $updated = [];

    /** Строк, применённых без изменения значений (обновится только время обмена) */
    public int $unchanged = 0;

    /** @var list<int> mode=full: товары с GUID, которым обнулится остаток */
    public array $zeroed = [];

    public function __construct(public ExchangeResult $result) {}
}
