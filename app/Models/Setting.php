<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Настройки сайта из админки (ключ — значение): контакты, реквизиты продавца, тексты подвала.
 * Читаются из кэша одним запросом; сохранение сбрасывает кэш.
 * Телефоны — список с подписями (JSON в contact_phones), остальное — текст.
 */
class Setting extends Model
{
    /** Текстовые поля формы «Настройки»: ключ → подпись */
    public const FIELDS = [
        'contact_email' => 'Email',
        'contact_hours' => 'Режим работы',
        'contact_pickup_address' => 'Адрес склада / самовывоза',
        'seller_name' => 'Краткое наименование',
        'seller_full_name' => 'Полное наименование',
        'seller_unp' => 'УНП',
        'seller_address' => 'Юридический адрес',
        'seller_postal_address' => 'Почтовый адрес',
        'seller_registration' => 'Государственная регистрация',
        'seller_registry' => 'Регистрация в Торговом реестре',
        'seller_iban' => 'Расчётный счёт (IBAN)',
        'seller_bank' => 'Банк',
        'seller_bic' => 'BIC',
        'seller_director' => 'Руководитель',
        'footer_about' => 'Подвал: о компании (кратко)',
        'footer_delivery' => 'Подвал: доставка и оплата (кратко)',
    ];

    /** Телефоны: [{label, number}, …] */
    public const PHONES = 'contact_phones';

    public const MAX_PHONES = 10;

    /** Реквизиты — блок «Реквизиты» на странице «Контакты», по порядку */
    public const REQUISITES = [
        'seller_full_name', 'seller_name', 'seller_unp', 'seller_address', 'seller_postal_address',
        'seller_registration', 'seller_registry', 'seller_iban', 'seller_bank', 'seller_bic', 'seller_director',
    ];

    /** Реквизиты в подвале (кратко): ключ → подпись в подвале */
    public const FOOTER_REQUISITES = [
        'seller_name' => 'Продавец',
        'seller_unp' => 'УНП',
        'seller_address' => 'Юридический адрес',
        'seller_registry' => 'Регистрация в Торговом реестре',
    ];

    /** Пока не заполнено в админке */
    public const PLACEHOLDER = '[ЗАПОЛНИТЬ]';

    private const CACHE_KEY = 'settings.all';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * @return array<string, ?string>
     */
    public static function values(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    /** Значение; пусто — заглушка [ЗАПОЛНИТЬ] (реквизиты, тексты подвала) */
    public static function get(string $key): string
    {
        return static::value($key) ?? self::PLACEHOLDER;
    }

    /** Значение; пусто — null (контакты: незаполненное на сайте не показывается) */
    public static function value(string $key): ?string
    {
        $value = trim((string) (static::values()[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Телефоны с подписями; tel — номер для ссылки tel: (только + и цифры).
     *
     * @return list<array{label: string, number: string, tel: string}>
     */
    public static function phones(): array
    {
        $rows = json_decode((string) (static::values()[self::PHONES] ?? ''), true);

        return collect(is_array($rows) ? $rows : [])
            ->map(fn ($row) => [
                'label' => trim((string) ($row['label'] ?? '')),
                'number' => trim((string) ($row['number'] ?? '')),
            ])
            ->filter(fn ($row) => $row['number'] !== '')
            ->map(fn ($row) => $row + ['tel' => preg_replace('/[^+\d]/', '', $row['number'])])
            ->values()->all();
    }

    /**
     * Подпись писем (vendor/notifications/email): продавец, телефоны, email, режим работы, сайт.
     *
     * @return list<string>
     */
    public static function mailSignature(): array
    {
        $phones = array_map(
            fn ($phone) => ($phone['label'] !== '' ? $phone['label'].': ' : 'Тел.: ').$phone['number'],
            static::phones()
        );

        $lines = array_filter([
            static::value('seller_name'),
            ...$phones,
            ($email = static::value('contact_email')) ? 'Email: '.$email : null,
            static::value('contact_hours'),
        ]);

        return $lines ? [...array_values($lines), url('/')] : [];
    }

    /**
     * @param  array<string, ?string>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        static::flush();
    }

    /** Сбросить кэш настроек (после записи в таблицу в обход put — миграции) */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
