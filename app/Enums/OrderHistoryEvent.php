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

    public function color(): string
    {
        return match ($this) {
            self::CancelRequested => 'bg-orange-100 text-orange-700',
            self::CancelRejected  => 'bg-slate-100 text-slate-700',
        };
    }
}
