<?php

namespace App\Enums;

/**
 * Роли пользователей (SRS §4). «Гость» — неавторизованный пользователь,
 * в БД не хранится.
 */
enum UserRole: string
{
    case Client = 'client';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Клиент',
            self::Admin  => 'Администратор',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
