<?php

namespace App\Services;

use App\Enums\CartProblemType;
use App\Enums\CartState;
use App\Models\CartItem;
use App\Services\Vat\VatCalculator;
use App\Services\Vat\VatTotal;
use Illuminate\Support\Collection;

/**
 * Результат проверки корзины: все проблемы всех позиций сразу (BR-101).
 */
class CartValidationResult
{
    /**
     * @param  Collection<int, CartItem>  $items
     * @param  array<int, list<CartProblem>>  $problems  по id позиции корзины
     */
    public function __construct(
        private readonly Collection $items,
        private readonly array $problems,
    ) {
    }

    public function state(): CartState
    {
        if ($this->items->isEmpty()) {
            return CartState::Empty;
        }

        $all = $this->all();

        if ($all->isEmpty()) {
            return CartState::Ready;
        }

        return $all->contains(fn (CartProblem $p) => $p->type->needsCorrection())
            ? CartState::NeedsCorrection
            : CartState::NeedsUpdate;
    }

    public function isReady(): bool
    {
        return $this->state() === CartState::Ready;
    }

    /**
     * @return Collection<int, CartProblem>
     */
    public function all(): Collection
    {
        return collect($this->problems)->flatten(1);
    }

    /**
     * @return list<CartProblem>
     */
    public function for(CartItem $item): array
    {
        return $this->problems[$item->id] ?? [];
    }

    /**
     * Перечень всех проблем строками «Название: причина».
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->all()->map->fullMessage()->values()->all();
    }

    /**
     * Товар позиции нельзя заказать вовсе — её можно только удалить.
     */
    public function isUnavailable(CartItem $item): bool
    {
        foreach ($this->for($item) as $problem) {
            if ($problem->type->makesUnavailable()) {
                return true;
            }
        }

        return false;
    }

    public function priceChange(CartItem $item): ?CartProblem
    {
        foreach ($this->for($item) as $problem) {
            if ($problem->type === CartProblemType::PriceChanged) {
                return $problem;
            }
        }

        return null;
    }

    public function hasPriceChanges(): bool
    {
        return $this->all()->contains(fn (CartProblem $p) => $p->type === CartProblemType::PriceChanged);
    }

    /**
     * Сумма корзины по ценам на момент добавления; недоступные товары не входят.
     */
    public function total(): float
    {
        return round(
            $this->items->reject(fn (CartItem $item) => $this->isUnavailable($item))->sum('subtotal'),
            2
        );
    }

    /**
     * Итог корзины с НДС — сумма позиций; недоступные товары не входят.
     */
    public function totals(): VatTotal
    {
        return app(VatCalculator::class)->total(
            $this->items->reject(fn (CartItem $item) => $this->isUnavailable($item))->map->vatLine()
        );
    }
}
