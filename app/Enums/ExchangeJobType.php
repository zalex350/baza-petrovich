<?php

namespace App\Enums;

/**
 * Тип задания обмена с 1С (docs/1C_EXCHANGE_SPEC.md, раздел 3). Значение — как в ответе
 * GET /jobs/{id} (поле type) и в адресе запроса.
 */
enum ExchangeJobType: string
{
    case Catalog = 'catalog';
    case PricesStock = 'prices-stock';
    case OrdersNew = 'orders-new';
    case OrderConfirm = 'order-confirm';
    case OrderStatuses = 'order-statuses';

    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Номенклатура',
            self::PricesStock => 'Цены и остатки',
            self::OrdersNew => 'Новые заказы',
            self::OrderConfirm => 'Подтверждение заказа',
            self::OrderStatuses => 'Статусы заказов',
        };
    }

    /** import — 1С → сайт, export — сайт → 1С */
    public function direction(): string
    {
        return $this === self::OrdersNew ? 'export' : 'import';
    }

    /**
     * Обязательные колонки CSV (первая строка). Нет — 400 missing_columns, файл не принимается.
     * Номенклатура: `sku` (код товара на сайте) — колонка необязательная, если в 1С нет поля
     * под него (раздел 4); артикул производителя — `article`.
     *
     * @return list<string>
     */
    public function requiredColumns(): array
    {
        return match ($this) {
            self::Catalog => ['guid', 'article', 'name', 'unit', 'vat_rate', 'active'],
            self::PricesStock => ['guid', 'sku', 'stock', 'updated_at'],
            default => [],
        };
    }
}
