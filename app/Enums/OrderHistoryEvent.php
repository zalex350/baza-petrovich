<?php

namespace App\Enums;

/**
 * Записи истории заказа без смены статуса.
 */
enum OrderHistoryEvent: string
{
    case CancelRequested = 'cancel_requested';
    case CancelRejected = 'cancel_rejected';
    // Обмен с 1С: отказ 1С (поток 4) и повторная отправка менеджером
    case ExchangeRejected = 'exchange_rejected';
    case ExchangeResent = 'exchange_resent';

    public function label(): string
    {
        return match ($this) {
            self::CancelRequested => 'Запрошена отмена',
            self::CancelRejected  => 'Запрос на отмену отклонён',
            self::ExchangeRejected => 'Отклонён 1С',
            self::ExchangeResent => 'Отправлен в 1С повторно',
        };
    }

    /**
     * Tailwind-классы бейджа (токены tone.* в tailwind.config.js).
     */
    public function color(): string
    {
        return match ($this) {
            self::CancelRequested => 'bg-tone-attention-bg text-tone-attention-text',
            self::CancelRejected  => 'bg-tone-neutral-bg text-tone-neutral-text',
            self::ExchangeRejected => 'bg-tone-danger-bg text-tone-danger-text',
            self::ExchangeResent => 'bg-tone-info-bg text-tone-info-text',
        };
    }
}
