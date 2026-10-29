<?php

namespace App\Enums;

/**
 * Откуда счёт на оплату: загружен администратором или пришёл из 1С (обмен — позже).
 */
enum InvoiceSource: string
{
    case Manual = 'manual';
    case Erp = 'erp';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'загружен вручную',
            self::Erp => 'из 1С',
        };
    }
}
