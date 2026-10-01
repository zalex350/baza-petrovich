<?php

namespace App\Services;

use App\Enums\CartProblemType;
use Illuminate\Support\Str;

/**
 * Одна проблема одной позиции корзины.
 */
class CartProblem
{
    public function __construct(
        public readonly CartProblemType $type,
        public readonly string $message,
        public readonly string $productName,
        public readonly ?float $oldPrice = null,
        public readonly ?float $newPrice = null,
        public readonly ?int $available = null,
    ) {
    }

    /**
     * Текст для общего перечня: «Название: причина».
     */
    public function fullMessage(): string
    {
        return $this->productName . ': ' . Str::lcfirst($this->message);
    }
}
