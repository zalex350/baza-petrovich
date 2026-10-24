<?php

namespace App\Services;

use App\Enums\CartProblemType;

/**
 * Одна проблема одной позиции корзины: заголовок — текст ошибки по SRS
 * (из типа), detail — уточнение (остаток, было/стало).
 */
class CartProblem
{
    public function __construct(
        public readonly CartProblemType $type,
        public readonly string $productName,
        public readonly ?string $detail = null,
        public readonly ?float $oldPrice = null,
        public readonly ?float $newPrice = null,
        public readonly ?int $available = null,
    ) {}

    public function title(): string
    {
        return $this->type->title();
    }

    /**
     * Одной строкой, без названия товара: «Заголовок: уточнение».
     */
    public function text(): string
    {
        // Кратность: уточнение — уже полное предложение, заголовок его только повторил бы
        if ($this->type->isQuantityRule()) {
            return $this->detail;
        }

        return $this->detail ? $this->title().': '.$this->detail : $this->title();
    }

    /**
     * Строка для общего перечня: «Название: Заголовок (уточнение)».
     */
    public function fullMessage(): string
    {
        if ($this->type->isQuantityRule()) {
            return $this->productName.': '.$this->detail;
        }

        return $this->productName.': '.$this->title().($this->detail ? " ({$this->detail})" : '');
    }
}
