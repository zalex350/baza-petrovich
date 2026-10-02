<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Перевод строковых статусов в значения App\Enums\OrderStatus:
 *   new → new, processing → in_progress, shipped → shipped, deleted → cancelled.
 * Остатки товаров миграция не трогает: у бывших deleted остаток уже был возвращён
 * при удалении клиентом — им только ставится отметка stock_returned_at, чтобы
 * повторная отмена не вернула его второй раз.
 * Каждому заказу добавляется первая запись истории с его текущим статусом:
 * без автора, с комментарием «Перенос статуса при обновлении системы».
 */
return new class extends Migration
{
    private const MAP = [
        'processing' => 'in_progress',
        'deleted'    => 'cancelled',
    ];

    private const BACKFILL_COMMENT = 'Перенос статуса при обновлении системы';

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('orders')
                ->where('status', 'deleted')
                ->whereNull('stock_returned_at')
                ->update(['stock_returned_at' => DB::raw('updated_at')]);

            foreach (self::MAP as $old => $new) {
                DB::table('orders')->where('status', $old)->update(['status' => $new]);
            }

            $withHistory = DB::table('order_status_history')->select('order_id');

            DB::table('orders')
                ->whereNotIn('id', $withHistory)
                ->orderBy('id')
                ->get(['id', 'status', 'user_id', 'placed_at', 'updated_at'])
                ->each(function ($order) {
                    $isNew = $order->status === 'new';

                    DB::table('order_status_history')->insert([
                        'order_id'    => $order->id,
                        'from_status' => null,
                        'to_status'   => $order->status,
                        // Запись создана миграцией, а не человеком: автора нет
                        'user_id'     => null,
                        'comment'     => self::BACKFILL_COMMENT,
                        'created_at'  => $isNew ? $order->placed_at : ($order->updated_at ?? $order->placed_at),
                    ]);
                });
        });
    }

    /**
     * Обратимо с потерей детализации: статусов «Подтверждён», «Передан в 1С»,
     * «Выполнен» в старой схеме не было, они сворачиваются в ближайшие старые
     * (confirmed → new, sent_to_1c и in_progress → processing, completed → shipped).
     * Историю down() не трогает: она исчезает целиком вместе с таблицей
     * в down() предыдущей миграции.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $back = [
                'confirmed'   => 'new',
                'sent_to_1c'  => 'processing',
                'in_progress' => 'processing',
                'completed'   => 'shipped',
                'cancelled'   => 'deleted',
            ];

            foreach ($back as $new => $old) {
                DB::table('orders')->where('status', $new)->update(['status' => $old]);
            }
        });
    }
};
