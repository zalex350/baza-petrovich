<?php

namespace App\Enums;

/**
 * Записи истории заказа без смены статуса.
 */
enum OrderHistoryEvent: string
{
    case CancelRequested = 'cancel_requested';
    case CancelRejected = 'cancel_rejected';

    public function label(): string
    {
        return match ($this) {
            self::CancelRequested => 'Запрошена отмена',
            self::CancelRejected  => 'Запрос на отмену отклонён',
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
        };
    }
}
