<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поля обмена заказов с 1С (docs/1C_EXCHANGE_SPEC.md, разделы 6–7).
 * orders: exchange_state (pending / sent / rejected), exported_at (первая выдача в /orders/new),
 * guid_1c, number_1c, registered_at, reject_reason.
 * order_items: снимок product_guid и unit на момент оформления.
 * order_status_history: source (site / 1c) и changed_at_1c (время смены статуса в 1С).
 *
 * Существующие заказы: «Новый», «Подтверждён» и «Отменён» (отмена возможна только до передачи) —
 * pending, остальные — sent (без registered_at: регистрацию в 1С вели вручную).
 * unit позиций — единица товара, у позиций без товара — «шт».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('exchange_state', 20)->default('pending')->after('status')->index();
            $table->timestamp('exported_at')->nullable()->after('exchange_state');
            $table->string('guid_1c')->nullable()->unique()->after('exported_at');
            $table->string('number_1c', 50)->nullable()->after('guid_1c');
            $table->timestamp('registered_at')->nullable()->after('number_1c');
            $table->text('reject_reason')->nullable()->after('registered_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_guid')->nullable()->after('product_sku');
            $table->string('unit', 20)->nullable()->after('quantity');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->string('source', 10)->default('site')->after('event');
            $table->timestamp('changed_at_1c')->nullable()->after('source');
        });

        DB::table('orders')
            ->whereNotIn('status', ['new', 'confirmed', 'cancelled'])
            ->update(['exchange_state' => 'sent']);

        DB::table('order_items')->whereNull('unit')->update([
            'unit' => DB::raw("COALESCE((SELECT products.unit FROM products WHERE products.id = order_items.product_id), 'шт')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropColumn(['source', 'changed_at_1c']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_guid', 'unit']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['exchange_state']);
            $table->dropUnique(['guid_1c']);
            $table->dropColumn(['exchange_state', 'exported_at', 'guid_1c', 'number_1c', 'registered_at', 'reject_reason']);
        });
    }
};
