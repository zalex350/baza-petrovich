<?php

namespace App\Enums;

/**
 * Состояние обмена заказа с 1С (docs/1C_EXCHANGE_SPEC.md, раздел 6) — отдельно от статуса,
 * который видит покупатель. pending — ждёт передачи (резервирует остаток на сайте),
 * sent — 1С подтвердила регистрацию (guid_1c, number_1c, registered_at), rejected — 1С отказала
 * (reject_reason), заказ — на разбор менеджеру.
 */
enum OrderExchangeState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает передачи в 1С',
            self::Sent => 'Передан в 1С',
            self::Rejected => 'Отклонён 1С',
        };
    }
}
