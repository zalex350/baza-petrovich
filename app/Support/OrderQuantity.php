<?php

namespace App\Support;

use App\Models\Product;

/**
 * Правило количества в заказе — одно для витрины, корзины, повтора заказа и оформления:
 * количество кратно qty_step и не меньше min_qty. Первое допустимое — ceil(min_qty / qty_step) × qty_step
 * (мин. 2, кратность 3 → 3, 6, 9, …). Та же формула — в x-qty (Alpine).
 */
final class OrderQuantity
{
    public readonly int $min;

    public readonly int $step;

    /** Первое допустимое количество (эффективный минимум) */
    public readonly int $first;

    public function __construct(?int $min, ?int $step)
    {
        $this->min = max(1, (int) $min);
        $this->step = max(1, (int) $step);
        $this->first = (int) ceil($this->min / $this->step) * $this->step;
    }

    public static function for(?Product $product): self
    {
        return new self($product?->min_qty, $product?->qty_step);
    }

    public function allows(int $quantity): bool
    {
        return $quantity >= $this->first && $quantity % $this->step === 0;
    }

    /** Ближайшее допустимое вверх */
    public function roundUp(int $quantity): int
    {
        return $quantity <= $this->first ? $this->first : (int) ceil($quantity / $this->step) * $this->step;
    }

    /** Ближайшее допустимое вниз; меньше первого допустимого — null */
    public function roundDown(int $quantity): ?int
    {
        $down = intdiv(max(0, $quantity), $this->step) * $this->step;

        return $down >= $this->first ? $down : null;
    }

    /** min_qty не кратен шагу — на витрине минимум округляется вверх */
    public function minIsRounded(): bool
    {
        return $this->first !== $this->min;
    }

    /** «мин. 6, кратно 3» / «кратно 3» / «мин. 2» — правило для подсказки округления */
    public function rule(): string
    {
        $parts = [];

        if ($this->first > $this->step) {
            $parts[] = 'мин. '.$this->first;
        }
        if ($this->step > 1) {
            $parts[] = 'кратно '.$this->step;
        }

        return $parts ? implode(', ', $parts) : 'мин. '.$this->first;
    }

    /** «Округлено до 6 — кратно 3» */
    public function roundedHint(int $to): string
    {
        return 'Округлено до '.$to.' — '.$this->rule();
    }

    /** «Количество должно быть не меньше 3 и кратно 3»; при шаге 1 — без «кратно 1» */
    public function message(): string
    {
        return "Количество должно быть не меньше {$this->first}".($this->step > 1 ? " и кратно {$this->step}" : '');
    }

    /** «Мин. заказ 3 шт. · кратность 3 шт.»; «по одной» — null */
    public function label(): ?string
    {
        return $this->first > 1 || $this->step > 1
            ? "Мин. заказ {$this->first} шт. · кратность {$this->step} шт."
            : null;
    }
}
