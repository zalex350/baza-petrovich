<?php

namespace App\Services;

use App\Enums\CartProblemType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Повторный заказ (SRS §8.11, UC-036): позиции заказа добавляются в текущую корзину клиента.
 * Каждая позиция проверяется CartValidator — товар опубликован и доступен, есть цена, остаток,
 * кратность (min_qty, qty_step); цена берётся текущая. Количество при необходимости
 * корректируется: вверх до кратности, вниз — до остатка (с учётом того, что уже в корзине).
 * Исходный заказ не меняется (BR-118).
 *
 * Результат: added — сколько позиций добавлено; issues — что не добавлено или изменилось:
 * [name, reason] («снят с продажи», «нет в наличии», «количество скорректировано до X»,
 * «цена изменилась: …»).
 */
class ReorderService
{
    public function __construct(private readonly CartValidator $validator) {}

    /**
     * @return array{added: int, issues: list<array{name: string, reason: string}>}
     */
    public function reorder(Order $order, User $user): array
    {
        $cart = $user->cart()->firstOrCreate();
        $order->loadMissing('items');

        return DB::transaction(function () use ($order, $cart) {
            // Как в CartController::add: параллельные добавления в одну корзину — по очереди
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            $added = 0;
            $issues = [];

            foreach ($order->items as $item) {
                $product = $item->product_id ? Product::withTrashed()->find($item->product_id) : null;
                $name = $item->product_name ?: ($product?->title() ?? 'Товар');

                [$ok, $reason] = $this->addItem($cart, $product, (int) $item->quantity, $item->price, $name);

                $added += $ok ? 1 : 0;

                if ($reason !== null) {
                    $issues[] = ['name' => $name, 'reason' => $reason];
                }
            }

            return ['added' => $added, 'issues' => $issues];
        });
    }

    /**
     * Одна позиция: [добавлена ли, причина / замечание или null].
     *
     * @return array{0: bool, 1: ?string}
     */
    private function addItem(Cart $cart, ?Product $product, int $wanted, $orderPrice, string $name): array
    {
        $unavailable = $this->validator->check($product, max(1, $wanted), null, $name)[0] ?? null;

        if ($unavailable && $unavailable->type->makesUnavailable()) {
            return [false, $unavailable->type === CartProblemType::NoPrice ? 'цена уточняется — заказать нельзя' : 'снят с продажи'];
        }

        $existing = CartItem::where('cart_id', $cart->id)->where('product_id', $product->id)->first();
        $inCart = (int) ($existing?->quantity ?? 0);
        $stock = (int) $product->stock;

        $total = $this->allowedTotal($product, $inCart + $wanted, $stock);
        $add = $total === null ? 0 : $total - $inCart;

        if ($add <= 0) {
            return [false, $inCart > 0 ? "нет в наличии сверх {$inCart} шт. в корзине" : 'нет в наличии'];
        }

        // Итог ещё раз — тем же валидатором, что и корзина
        if ($problem = $this->validator->check($product, $total, null, $name)[0] ?? null) {
            return [false, $problem->text()];
        }

        if ($existing) {
            $existing->quantity = $total;
            $existing->price_at_add = $product->price;
            $existing->selected = true;
            $existing->save();
        } else {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'product_name' => $product->title(),
                'quantity' => $total,
                'price_at_add' => $product->price,
            ]);
        }

        $notes = [];

        if ($add !== $wanted) {
            $limit = $inCart + $wanted > $stock ? " (доступно {$stock}".($inCart ? ", в корзине уже было {$inCart}" : '').')' : '';
            $notes[] = "количество скорректировано до {$add}{$limit}";
        }

        if ($orderPrice !== null && round((float) $orderPrice, 2) !== round((float) $product->price, 2)) {
            $notes[] = 'цена изменилась: было '.money($orderPrice).', стало '.money($product->price);
        }

        return [true, $notes ? implode('; ', $notes) : null];
    }

    /**
     * Ближайшее допустимое количество в корзине: вверх до кратности (min, min + step, …), если
     * это не больше остатка, иначе вниз — до остатка. Нельзя ни одного — null.
     */
    private function allowedTotal(Product $product, int $target, int $stock): ?int
    {
        $min = max(1, (int) $product->min_qty);
        $step = max(1, (int) $product->qty_step);

        $up = $target <= $min ? $min : $min + (int) ceil(($target - $min) / $step) * $step;

        if ($up <= $stock) {
            return $up;
        }

        if ($stock < $min) {
            return null;
        }

        return $min + intdiv($stock - $min, $step) * $step;
    }
}
