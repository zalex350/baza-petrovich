<?php

namespace App\Enums;

/**
 * Статус заявки «Стать партнёром»: Новая → В работе → Подключён / Отказ (меняется свободно).
 */
enum PartnerRequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Connected = 'connected';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::InProgress => 'В работе',
            self::Connected => 'Подключён',
            self::Rejected => 'Отказ',
        };
    }

    /** Тон x-badge */
    public function tone(): string
    {
        return match ($this) {
            self::New => 'info',
            self::InProgress => 'warning',
            self::Connected => 'success',
            self::Rejected => 'neutral',
        };
    }
}
