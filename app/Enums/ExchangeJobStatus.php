<?php

namespace App\Enums;

/**
 * Статус задания (docs/1C_EXCHANGE_SPEC.md, раздел 8): queued → processing → done | failed.
 * done — файл обработан, ошибки отдельных строк возможны; failed — не обработан целиком,
 * данные сайта не изменились.
 */
enum ExchangeJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'В очереди',
            self::Processing => 'Обрабатывается',
            self::Done => 'Готово',
            self::Failed => 'Ошибка',
        };
    }

    /** Тон x-badge */
    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'neutral',
            self::Processing => 'info',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }
}
