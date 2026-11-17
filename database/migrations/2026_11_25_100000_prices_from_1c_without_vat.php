<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Решение заказчика: 1С передаёт цены без НДС (ставка товара по умолчанию — 20 %).
 * Настройка «Цены из 1С с НДС» (exchange_prices_with_vat) выключается явно — и там, где её успели включить.
 */
return new class extends Migration
{
    private const KEY = 'exchange_prices_with_vat';

    public function up(): void
    {
        $now = now();
        DB::table('settings')->updateOrInsert(['key' => self::KEY], ['value' => '0', 'created_at' => $now, 'updated_at' => $now]);
        Setting::flush();
    }

    public function down(): void
    {
        // Прежнее поведение: нет строки — цены с НДС
        DB::table('settings')->where('key', self::KEY)->delete();
        Setting::flush();
    }
};
