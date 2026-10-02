<?php

namespace App\Enums;

/**
 * Состояния корзины (SRS §7.17).
 */
enum CartState: string
{
    case Empty = 'empty';
    case NeedsCorrection = 'needs_correction';
    case NeedsUpdate = 'needs_update';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Empty           => 'Пустая',
            self::NeedsCorrection => 'Требует корректировки',
            self::NeedsUpdate     => 'Требует обновления',
            self::Ready           => 'Готова к оформлению',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Empty           => 'Добавьте товары из каталога.',
            self::NeedsCorrection => 'Удалите недоступные товары или уменьшите количество — после этого заказ можно будет оформить.',
            self::NeedsUpdate     => 'Цены изменились с момента добавления. Примите новые цены, чтобы оформить заказ.',
            self::Ready           => 'Все товары доступны, цены актуальны.',
        };
    }
}
