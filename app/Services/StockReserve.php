<?php

namespace App\Services;

use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Резерв сайта и доступный остаток (docs/1C_EXCHANGE_SPEC.md, раздел 5, правила 4–5).
 *
 * products.stock_1c — остаток 1С как есть; products.stock — доступный покупателю:
 * stock = max(0, stock_1c − резерв). Резерв — количество в неотменённых заказах,
 * которые 1С ещё не учла в остатке:
 *   - exchange_state = pending (ещё не переданы);
 *   - exchange_state = sent, но registered_at позже stock_synced_at товара (или остаток
 *     из 1С ещё не загружался): последний остаток 1С снят до регистрации заказа.
 * Отклонённые 1С (rejected) не резервируют.
 *
 * Пересчёт — recalculate() после оформления, отмены, подтверждения / отказа 1С.
 * Запись stock_1c / stock_synced_at (загрузка остатков) или stock напрямую (админка, сиды)
 * согласует вторую величину в Product::saving через reservedFor().
 */
class StockReserve
{
    /**
     * Резерв одного товара. $stockSyncedAt — время остатка, которое будет у товара
     * (в Product::saving оно ещё не записано в БД).
     */
    public function reservedFor(?int $productId, ?Carbon $stockSyncedAt): int
    {
        if (! $productId) {
            return 0;
        }

        return (int) $this->reservingItems()
            ->where('order_items.product_id', $productId)
            ->where(function ($q) use ($stockSyncedAt) {
                $q->where('orders.exchange_state', OrderExchangeState::Pending->value)
                    ->orWhere(function ($q) use ($stockSyncedAt) {
                        $q->where('orders.exchange_state', OrderExchangeState::Sent->value)
                            ->whereNotNull('orders.registered_at')
                            ->when($stockSyncedAt, fn ($q) => $q->where('orders.registered_at', '>', $stockSyncedAt));
                    });
            })
            ->sum('order_items.quantity');
    }

    /**
     * Резерв товаров по данным БД: [product_id => количество]; товаров без резерва в ответе нет.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, int>
     */
    public function reserved(iterable $productIds): array
    {
        $ids = collect($productIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return $this->reservingItems()
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.product_id', $ids)
            ->where(function ($q) {
                $q->where('orders.exchange_state', OrderExchangeState::Pending->value)
                    ->orWhere(function ($q) {
                        $q->where('orders.exchange_state', OrderExchangeState::Sent->value)
                            ->whereNotNull('orders.registered_at')
                            ->where(fn ($q) => $q->whereNull('products.stock_synced_at')
                                ->orWhereColumn('orders.registered_at', '>', 'products.stock_synced_at'));
                    });
            })
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    public static function available(int $stock1c, int $reserved): int
    {
        return max(0, $stock1c - $reserved);
    }

    /**
     * Пересчитать доступный остаток товаров. Мимо модели: stock_1c не меняется,
     * updated_at не трогается. Вызывать внутри транзакции изменения заказа.
     *
     * @param  iterable<int>  $productIds
     */
    public function recalculate(iterable $productIds): void
    {
        $ids = collect($productIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $reserved = $this->reserved($ids);

        DB::table('products')->whereIn('id', $ids)->orderBy('id')->get(['id', 'stock', 'stock_1c'])
            ->each(function ($product) use ($reserved) {
                $stock = self::available((int) $product->stock_1c, $reserved[$product->id] ?? 0);

                if ($stock !== (int) $product->stock) {
                    DB::table('products')->where('id', $product->id)->update(['stock' => $stock]);
                }
            });
    }

    /**
     * Пересчитать товары заказа.
     */
    public function recalculateOrder(int $orderId): void
    {
        $this->recalculate(DB::table('order_items')->where('order_id', $orderId)->whereNotNull('product_id')->pluck('product_id'));
    }

    /**
     * Позиции неотменённых заказов с товаром.
     */
    private function reservingItems()
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value);
    }

    /**
     * Для модели: согласовать stock и stock_1c перед сохранением товара.
     * Записан stock (админка, сиды, тесты) — это доступный остаток, stock_1c = stock + резерв.
     * Записан stock_1c или stock_synced_at (загрузка из 1С) — stock пересчитывается.
     */
    public function sync(Product $product): void
    {
        $stockDirty = $product->isDirty('stock');
        $sourceDirty = $product->isDirty(['stock_1c', 'stock_synced_at']);

        if (! $product->exists) {
            if ($product->stock_1c === null || ($stockDirty && ! $product->isDirty('stock_1c'))) {
                $product->stock_1c = (int) $product->stock;
            } elseif (! $stockDirty) {
                $product->stock = (int) $product->stock_1c;
            }

            return;
        }

        if (! $stockDirty && ! $sourceDirty) {
            return;
        }

        $reserved = $this->reservedFor($product->id, $product->stock_synced_at);

        if ($stockDirty && ! $product->isDirty('stock_1c')) {
            $product->stock_1c = (int) $product->stock + $reserved;
        } elseif (! $stockDirty) {
            $product->stock = self::available((int) $product->stock_1c, $reserved);
        }
    }
}
