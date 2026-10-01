<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Счётчик номеров заказов по годам. Номер выдаётся атомарным
 * «insert … on duplicate key update last_number + 1» внутри транзакции оформления:
 * параллельные оформления ждут друг друга на одной строке, а не на диапазоне orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        // Продолжаем нумерацию с уже выданных номеров SM-ГГГГ-NNNNNN
        $lastByYear = [];

        foreach (DB::table('orders')->whereNotNull('number')->pluck('number') as $number) {
            if (preg_match('/^SM-(\d{4})-(\d+)$/', $number, $m)) {
                $lastByYear[$m[1]] = max($lastByYear[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        foreach ($lastByYear as $year => $last) {
            DB::table('order_sequences')->insert(['year' => $year, 'last_number' => $last]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sequences');
    }
};
