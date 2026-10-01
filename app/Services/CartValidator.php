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
            ! $product || $product->trashed() => [CartProblemType::Missing, 'Товар больше недоступен'],
            ! $product->is_active             => [CartProblemType::Inactive, 'Товар снят с продажи'],
            ! $product->is_published          => [CartProblemType::Unpublished, 'Товар недоступен для заказа'],
            $product->price === null          => [CartProblemType::NoPrice, 'У товара не указана цена'],
            default                           => null,
        };

        // Недоступный товар: остаток и цена уже не имеют значения
        if ($unavailable) {
            return [new CartProblem($unavailable[0], $unavailable[1], $name)];
        }

        $problems = [];
        $stock = (int) $product->stock;

        if ($stock < 1) {
            $problems[] = new CartProblem(CartProblemType::OutOfStock, 'Нет в наличии', $name, available: 0);
        } elseif ($quantity > $stock) {
            $problems[] = new CartProblem(
                CartProblemType::InsufficientStock,
                "Недостаточно на складе: доступно {$stock}, в корзине {$quantity}",
                $name,
                available: $stock,
            );
        }

        if ($priceAtAdd !== null && $this->money($priceAtAdd) !== $this->money($product->price)) {
            $problems[] = new CartProblem(
                CartProblemType::PriceChanged,
                'Цена изменилась: было ' . $this->money($priceAtAdd) . ' BYN, стало ' . $this->money($product->price) . ' BYN',
                $name,
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
