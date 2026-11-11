<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products.stock_1c — остаток 1С как есть; products.stock — доступный: stock_1c − резерв сайта
 * (App\Services\StockReserve, docs/1C_EXCHANGE_SPEC.md, раздел 5).
 *
 * Обмена ещё нет: текущий stock уже уменьшен оформлением неотменённых заказов,
 * которые ждут передачи (pending). Чтобы доступный остаток не уменьшился второй раз,
 * stock_1c = stock + их количество; у остальных товаров stock_1c = stock.
 * Заказы в sent без registered_at (до обмена) не резервируют — их списание остаётся в stock_1c.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock_1c')->default(0)->after('stock');
        });

        DB::table('products')->update(['stock_1c' => DB::raw('stock')]);

        DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.exchange_state', 'pending')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS qty')
            ->get()
            ->each(fn ($row) => DB::table('products')->where('id', $row->product_id)
                ->update(['stock_1c' => DB::raw('stock + '.(int) $row->qty)]));
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_1c');
        });
    }
};
