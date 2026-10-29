<?php

namespace App\Enums;

/**
 * Тип документа товара (SRS §4.8). Порядок кейсов — порядок в списке выбора.
 */
enum ProductDocumentType: string
{
    case Manual = 'manual';
    case Passport = 'passport';
    case Certificate = 'certificate';
    case Declaration = 'declaration';
    case Catalog = 'catalog';
    case Wiring = 'wiring';
    case Guide = 'guide';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Инструкция',
            self::Passport => 'Паспорт',
            self::Certificate => 'Сертификат',
            self::Declaration => 'Декларация',
            self::Catalog => 'Каталог производителя',
            self::Wiring => 'Схема подключения',
            self::Guide => 'Руководство',
            self::Other => 'Другой',
        };
    }

    /** Значок Heroicons (outline) в карточке товара */
    public function icon(): string
    {
        return match ($this) {
            self::Manual, self::Guide => 'book-open',
            self::Passport => 'identification',
            self::Certificate, self::Declaration => 'check-badge',
            self::Catalog => 'rectangle-stack',
            self::Wiring => 'wrench-screwdriver',
            self::Other => 'document-text',
        };
    }
}
