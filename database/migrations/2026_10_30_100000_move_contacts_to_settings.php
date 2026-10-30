<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Контакты переезжают из config/shop.php (SHOP_PHONE, SHOP_EMAIL, SHOP_WORKING_HOURS) в «Настройки»:
 * текущие значения — в settings (contact_phones с подписью «Отдел продаж», contact_email, contact_hours).
 * Уже заполненное в админке не трогается, реквизиты и тексты подвала (seller_*, footer_*) остаются
 * под прежними ключами. Заглушка-номер по умолчанию (+375 (29) 000-00-00) не переносится.
 * Повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    private const PLACEHOLDER_PHONE = '+375 (29) 000-00-00';

    public function up(): void
    {
        $phone = trim((string) config('shop.phone'));

        $values = [
            'contact_phones' => $phone !== '' && $phone !== self::PLACEHOLDER_PHONE
                ? json_encode([['label' => 'Отдел продаж', 'number' => $phone]], JSON_UNESCAPED_UNICODE)
                : null,
            'contact_email' => trim((string) config('shop.email')) ?: null,
            'contact_hours' => trim((string) config('shop.working_hours')) ?: null,
        ];

        $now = now();

        foreach ($values as $key => $value) {
            $current = DB::table('settings')->where('key', $key)->value('value');

            if ($value === null || trim((string) $current) !== '') {
                continue;
            }

            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }

        Setting::flush();
    }

    public function down(): void
    {
        // Ничего не удаляется: к этому времени контакты могли изменить в админке, а прежний код
        // берёт их из config/shop.php и строк в settings не замечает
    }
};
