<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Настройки сайта из админки (ключ — значение): реквизиты продавца и тексты подвала.
 * Читаются из кэша одним запросом; сохранение сбрасывает кэш.
 */
class Setting extends Model
{
    /** Поля формы «Настройки»: ключ → подпись */
    public const FIELDS = [
        'seller_name' => 'Наименование продавца',
        'seller_unp' => 'УНП',
        'seller_address' => 'Юридический адрес',
        'seller_registry' => 'Регистрация в Торговом реестре',
        'footer_about' => 'Подвал: о компании (кратко)',
        'footer_delivery' => 'Подвал: доставка (кратко)',
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

    /** Значение; пусто — заглушка [ЗАПОЛНИТЬ] */
    public static function get(string $key): string
    {
        $value = trim((string) (static::values()[$key] ?? ''));

        return $value !== '' ? $value : self::PLACEHOLDER;
    }

    /**
     * @param  array<string, ?string>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
