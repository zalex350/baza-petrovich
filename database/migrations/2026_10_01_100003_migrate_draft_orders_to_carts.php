<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Перенос данных при разделении корзины и заказа:
 *  - draft-заказы становятся корзинами (carts / cart_items) и удаляются из orders;
 *  - оформленные заказы получают number, placed_at и снимок товара в позициях.
 *
 * Работает через query builder, а не через модели: глобальные scope'ы (SoftDeletes)
 * не применяются, поэтому снимок берётся и с удалённых товаров.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->moveDraftsToCarts();
            $this->fillNumbersAndPlacedAt();
            $this->fillItemSnapshots();
        });
    }

    /**
     * Обратимо: корзины возвращаются в orders как draft-заказы.
     * number, placed_at и снимки исчезают вместе с колонками в down() предыдущей
     * миграции. Невосстановимо только одно: если у пользователя было несколько
     * draft-заказов, up() слил их в одну корзину — назад вернётся один draft.
     */
    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('carts')->orderBy('id')->get() as $cart) {
                $items = DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('id')->get();

                if ($items->isEmpty()) {
                    continue;
                }

                $orderId = DB::table('orders')->insertGetId([
                    'user_id'    => $cart->user_id,
                    'status'     => 'draft',
                    'total'      => 0,
                    'created_at' => $cart->created_at,
                    'updated_at' => $cart->updated_at,
                ]);

                foreach ($items as $item) {
                    DB::table('order_items')->insert([
                        'order_id'   => $orderId,
                        'product_id' => $item->product_id,
                        'quantity'   => $item->quantity,
                        'price'      => $item->price_at_add,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                }
            }

            DB::table('cart_items')->delete();
            DB::table('carts')->delete();
        });
    }

    private function moveDraftsToCarts(): void
    {
        $drafts = DB::table('orders')->where('status', 'draft')->orderBy('id')->get();

        if ($drafts->isEmpty()) {
            return;
        }

        $existingUsers = DB::table('users')
            ->whereIn('id', $drafts->pluck('user_id')->filter()->unique())
            ->pluck('id')
            ->flip();

        $cartIds = DB::table('carts')->pluck('id', 'user_id')->all();

        foreach ($drafts as $draft) {
            // Черновик без пользователя (или удалённого пользователя) никому не виден — не переносим.
            if (! $draft->user_id || ! $existingUsers->has($draft->user_id)) {
                continue;
            }

            $cartId = $cartIds[$draft->user_id] ??= DB::table('carts')->insertGetId([
                'user_id'    => $draft->user_id,
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ]);

            $items = DB::table('order_items')->where('order_id', $draft->id)->orderBy('id')->get();

            foreach ($items as $item) {
                $existing = DB::table('cart_items')
                    ->where('cart_id', $cartId)
                    ->where('product_id', $item->product_id)
                    ->first();

                // Дубли (несколько черновиков или повтор товара в одном) сливаются
                // в одну позицию: количества складываются, цена — из более поздней записи.
                if ($existing) {
                    DB::table('cart_items')->where('id', $existing->id)->update([
                        'quantity'     => $existing->quantity + $item->quantity,
                        'price_at_add' => $item->price,
                        'updated_at'   => $item->updated_at,
                    ]);

                    continue;
                }

                DB::table('cart_items')->insert([
                    'cart_id'      => $cartId,
                    'product_id'   => $item->product_id,
                    'quantity'     => $item->quantity,
                    'price_at_add' => $item->price,
                    'created_at'   => $item->created_at,
                    'updated_at'   => $item->updated_at,
                ]);
            }
        }

        DB::table('order_items')->whereIn('order_id', $drafts->pluck('id'))->delete();
        DB::table('orders')->whereIn('id', $drafts->pluck('id'))->delete();
    }

    /**
     * Нумерация SM-ГГГГ-000001 сквозная в пределах года, в порядке id.
     * Дата оформления раньше не хранилась — берём created_at.
     */
    private function fillNumbersAndPlacedAt(): void
    {
        $lastByYear = [];

        foreach (DB::table('orders')->whereNotNull('number')->pluck('number') as $number) {
            if (preg_match('/^SM-(\d{4})-(\d+)$/', $number, $m)) {
                $lastByYear[$m[1]] = max($lastByYear[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        $orders = DB::table('orders')->whereNull('number')->orderBy('id')->get();

        foreach ($orders as $order) {
            $placedAt = Carbon::parse($order->placed_at ?? $order->created_at ?? $order->updated_at ?? now());
            $year = $placedAt->format('Y');

            $lastByYear[$year] = ($lastByYear[$year] ?? 0) + 1;

            DB::table('orders')->where('id', $order->id)->update([
                'number'    => sprintf('SM-%s-%06d', $year, $lastByYear[$year]),
                'placed_at' => $placedAt,
            ]);
        }
    }

    private function fillItemSnapshots(): void
    {
        DB::table('order_items')
            ->whereNull('product_name')
            ->orderBy('id')
            ->chunkById(500, function ($items) {
                $products = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->unique())
                    ->get(['id', 'name', 'display_name', 'sku', 'vat_rate'])
                    ->keyBy('id');

                foreach ($items as $item) {
                    $product = $products->get($item->product_id);

                    if (! $product) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->update([
                        'product_name' => $product->display_name ?: $product->name,
                        'product_sku'  => $product->sku,
                        'vat_rate'     => $product->vat_rate,
                    ]);
                }
            });
    }
};
