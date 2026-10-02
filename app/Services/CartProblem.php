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
    ) {
    }

    public function title(): string
    {
        return $this->type->title();
    }

    /**
     * Одной строкой, без названия товара: «Заголовок: уточнение».
     */
    public function text(): string
    {
        return $this->detail ? $this->title() . ': ' . $this->detail : $this->title();
    }

    /**
     * Строка для общего перечня: «Название: Заголовок (уточнение)».
     */
    public function fullMessage(): string
    {
        return $this->productName . ': ' . $this->title() . ($this->detail ? " ({$this->detail})" : '');
    }
}
