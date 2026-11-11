<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Настройки обмена с 1С из админки («Обмен с 1С → Настройки»), хранятся в settings.
 * Пусто — значение по умолчанию (docs/1C_EXCHANGE_SPEC.md, разделы 4, 5, 8).
 */
class ExchangeSettings
{
    /** ключ → [подпись, по умолчанию, минимум, максимум] */
    public const FIELDS = [
        'exchange_full_missing_percent' => ['Предохранитель mode=full: нет в файле больше, % товаров с GUID', 10, 1, 100],
        'exchange_error_rate_percent' => ['Письмо, если ошибок в задании больше, %', 5, 0, 100],
        'exchange_stale_prices_hours' => ['Письмо, если цены и остатки не приходили дольше, ч', 2, 1, 168],
        'exchange_pending_orders_minutes' => ['Письмо, если заказ ждёт передачи в 1С дольше, мин', 30, 5, 1440],
        'exchange_alert_interval_minutes' => ['Повторять письмо одного вида не чаще, чем раз в, мин', 60, 5, 1440],
    ];

    /** Письма о заказах, ждущих передачи, — включаются после подключения выгрузки заказов (этап 5) */
    public const PENDING_ORDERS_ALERT = 'exchange_alert_pending_orders';

    public static function int(string $key): int
    {
        $value = Setting::value($key);

        return $value !== null && is_numeric($value) ? (int) $value : self::FIELDS[$key][1];
    }

    public static function fullMissingShare(): float
    {
        return self::int('exchange_full_missing_percent') / 100;
    }

    public static function errorRateShare(): float
    {
        return self::int('exchange_error_rate_percent') / 100;
    }

    public static function pendingOrdersAlert(): bool
    {
        return Setting::value(self::PENDING_ORDERS_ALERT) === '1';
    }
}
