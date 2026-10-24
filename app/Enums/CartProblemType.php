<?php

namespace App\Enums;

/**
 * Причина, по которой позицию корзины нельзя оформить как есть (BR-101, BR-125).
 * code() — код ошибки по SRS (для логов и сверки, пользователю не показывается),
 * title() — текст ошибки по SRS.
 */
enum CartProblemType: string
{
    case Missing = 'missing';                      // товар удалён
    case Inactive = 'inactive';                    // скрыт (is_active = false)
    case Unpublished = 'unpublished';              // снят с публикации
    case NoPrice = 'no_price';                     // цена не задана
    case OutOfStock = 'out_of_stock';              // остаток 0
    case InsufficientStock = 'insufficient_stock'; // в корзине больше, чем на складе
    case BelowMinQuantity = 'below_min_quantity';  // меньше минимального количества
    case StepMismatch = 'step_mismatch';           // не кратно шагу заказа
    case PriceChanged = 'price_changed';           // цена отличается от price_at_add

    public function code(): string
    {
        return match ($this) {
            self::Missing => 'ERR-013',
            self::OutOfStock, self::InsufficientStock => 'ERR-014',
            self::BelowMinQuantity => 'ERR-015',
            self::StepMismatch => 'ERR-016',
            self::PriceChanged => 'ERR-017',
            self::Inactive, self::Unpublished, self::NoPrice => 'ERR-018',
        };
    }

    public function title(): string
    {
        return match ($this->code()) {
            'ERR-013' => 'Товар отсутствует в каталоге',
            'ERR-014' => 'Недостаточный остаток товара',
            'ERR-015' => 'Количество меньше минимально допустимого',
            'ERR-016' => 'Количество не соответствует шагу заказа',
            'ERR-017' => 'Цена товара изменилась',
            'ERR-018' => 'Товар больше недоступен для заказа',
        };
    }

    /**
     * Нарушена кратность заказа (min_qty, qty_step): текст ошибки — готовое предложение
     * «Количество должно быть не меньше N и кратно M» (CartProblem::$detail).
     */
    public function isQuantityRule(): bool
    {
        return $this === self::BelowMinQuantity || $this === self::StepMismatch;
    }

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
