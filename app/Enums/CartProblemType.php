<?php

namespace App\Enums;

/**
 * Причина, по которой позицию корзины нельзя оформить как есть (BR-101, BR-125).
 */
enum CartProblemType: string
{
    case Missing = 'missing';                      // товар удалён
    case Inactive = 'inactive';                    // скрыт (is_active = false)
    case Unpublished = 'unpublished';              // снят с публикации
    case NoPrice = 'no_price';                     // цена не задана
    case OutOfStock = 'out_of_stock';              // остаток 0
    case InsufficientStock = 'insufficient_stock'; // в корзине больше, чем на складе
    case PriceChanged = 'price_changed';           // цена отличается от price_at_add

    /**
     * Товар нельзя заказать ни в каком количестве — позицию можно только удалить.
     */
    public function makesUnavailable(): bool
    {
        return in_array($this, [self::Missing, self::Inactive, self::Unpublished, self::NoPrice], true);
    }

    /**
     * Изменение цены пользователь принимает кнопкой («Требует обновления»),
     * всё остальное исправляет сам («Требует корректировки»).
     */
    public function needsCorrection(): bool
    {
        return $this !== self::PriceChanged;
    }
}
