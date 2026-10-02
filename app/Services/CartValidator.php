<?php

namespace App\Services;

use App\Enums\CartProblemType;
use App\Models\Cart;
use App\Models\Product;

/**
 * Проверка корзины перед показом и перед оформлением (BR-101, BR-123…BR-125):
 * существование, активность, публикация, остаток и изменение цены каждой позиции.
 * Возвращает все проблемы сразу, а не первую найденную.
 */
class CartValidator
{
    /**
     * Товар берётся из $item->product: при оформлении туда подставлены строки,
     * прочитанные под lockForUpdate.
     */
    public function validate(?Cart $cart): CartValidationResult
    {
        $items = $cart ? $cart->items : collect();
        $problems = [];

        foreach ($items as $item) {
            $found = $this->check($item->product, $item->quantity, $item->price_at_add, $item->title());

            if ($found) {
                $problems[$item->id] = $found;
            }
        }

        return new CartValidationResult($items, $problems);
    }

    /**
     * Проблемы одного товара в заданном количестве.
     * $priceAtAdd = null — цену не сравниваем (товар только добавляется в корзину).
     *
     * @return list<CartProblem>
     */
    public function check(?Product $product, int $quantity, $priceAtAdd = null, ?string $name = null): array
    {
        $name ??= $product ? ($product->display_name ?: $product->name) : 'Товар';

        $unavailable = match (true) {
            ! $product || $product->trashed() => CartProblemType::Missing,
            ! $product->is_active             => CartProblemType::Inactive,
            ! $product->is_published          => CartProblemType::Unpublished,
            $product->price === null          => CartProblemType::NoPrice,
            default                           => null,
        };

        // Недоступный товар: остаток и цена уже не имеют значения
        if ($unavailable) {
            return [new CartProblem($unavailable, $name)];
        }

        $problems = [];
        $stock = (int) $product->stock;

        if ($stock < 1) {
            $problems[] = new CartProblem(CartProblemType::OutOfStock, $name, 'нет в наличии', available: 0);
        } elseif ($quantity > $stock) {
            $problems[] = new CartProblem(
                CartProblemType::InsufficientStock,
                $name,
                "доступно {$stock}, в корзине {$quantity}",
                available: $stock,
            );
        }

        if ($priceAtAdd !== null && $this->money($priceAtAdd) !== $this->money($product->price)) {
            $problems[] = new CartProblem(
                CartProblemType::PriceChanged,
                $name,
                'было ' . $this->money($priceAtAdd) . ' BYN, стало ' . $this->money($product->price) . ' BYN',
                oldPrice: (float) $priceAtAdd,
                newPrice: (float) $product->price,
            );
        }

        return $problems;
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
