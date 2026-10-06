<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * НДС в заказах (B2B).
 *
 * - products.vat_rate становится nullable: null — «Без НДС» (не то же, что 0%).
 * - order_items: снимок сумм позиции — без НДС, НДС, с НДС (цена без НДС и ставка уже есть).
 * - orders: итоги без НДС, НДС, с НДС — суммы позиций. orders.total не трогается:
 *   это прежняя сумма без НДС, новые экраны её не показывают.
 *
 * Существующие заказы заполняются по той же формуле, что VatCalculator (здесь — своя
 * копия, чтобы миграция не зависела от будущих изменений сервиса).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('amount_without_vat', 12, 2)->default(0)->after('vat_rate');
            $table->decimal('vat_amount', 12, 2)->default(0)->after('amount_without_vat');
            $table->decimal('amount_with_vat', 12, 2)->default(0)->after('vat_amount');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_without_vat', 12, 2)->default(0)->after('total');
            $table->decimal('total_vat', 12, 2)->default(0)->after('total_without_vat');
            $table->decimal('total_with_vat', 12, 2)->default(0)->after('total_vat');
        });

        $this->backfill();

        // После заполнения снимка: в SQLite change() пересоздаёт таблицу products,
        // и внешние ключи с nullOnDelete обнуляют order_items.product_id
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->default(20.00)->change();
        });
    }

    private function backfill(): void
    {
        // Позиции без ставки (до снимка ставки) — ставка товара, а если товара нет — 20%,
        // действовавшие по умолчанию. «Без НДС» тогда ещё не существовало.
        DB::table('order_items')->whereNull('vat_rate')->eachById(function ($item) {
            $rate = $item->product_id
                ? DB::table('products')->where('id', $item->product_id)->value('vat_rate')
                : null;

            DB::table('order_items')->where('id', $item->id)->update(['vat_rate' => $rate ?? 20.00]);
        });

        $totals = [];

        DB::table('order_items')->eachById(function ($item) use (&$totals) {
            $net = (int) round((float) $item->price * 100) * (int) $item->quantity;
            $vat = $item->vat_rate === null ? 0 : (int) round($net * (float) $item->vat_rate / 100);

            DB::table('order_items')->where('id', $item->id)->update([
                'amount_without_vat' => $net / 100,
                'vat_amount'         => $vat / 100,
                'amount_with_vat'    => ($net + $vat) / 100,
            ]);

            $totals[$item->order_id][0] = ($totals[$item->order_id][0] ?? 0) + $net;
            $totals[$item->order_id][1] = ($totals[$item->order_id][1] ?? 0) + $vat;
        });

        // Итог — сумма позиций; заказ без позиций остаётся с нулями
        foreach ($totals as $orderId => [$net, $vat]) {
            DB::table('orders')->where('id', $orderId)->update([
                'total_without_vat' => $net / 100,
                'total_vat'         => $vat / 100,
                'total_with_vat'    => ($net + $vat) / 100,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['total_without_vat', 'total_vat', 'total_with_vat']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['amount_without_vat', 'vat_amount', 'amount_with_vat']);
        });

        // «Без НДС» в старой схеме не выразить — ближайшее значение 0%
        DB::table('products')->whereNull('vat_rate')->update(['vat_rate' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(20.00)->nullable(false)->change();
        });
    }
};
