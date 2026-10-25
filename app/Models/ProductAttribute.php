<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Характеристика из справочника (SRS §4.9): «Цвет», «Ширина, мм». Название уникально
 * без учёта регистра и лишних пробелов (name_key) — по нему же ищется при вводе.
 * type: text — значение строкой; number — ещё и value_number для диапазонных фильтров.
 */
class ProductAttribute extends Model
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const TYPES = [self::TEXT => 'Текст', self::NUMBER => 'Число'];

    protected $table = 'attributes';

    protected $fillable = ['name', 'unit', 'type', 'is_filterable', 'is_logistics', 'is_admin_only'];

    /**
     * is_logistics — «Упаковка и логистика» (вес, упаковка, габариты, страна): в карточке — свёрнутым блоком;
     * is_admin_only — только в админке (код ТН ВЭД).
     */
    protected $casts = [
        'is_filterable' => 'boolean',
        'is_logistics' => 'boolean',
        'is_admin_only' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attribute) {
            $attribute->name = self::cleanName($attribute->name);
            $attribute->name_key = self::key($attribute->name);
            $attribute->type ??= self::TEXT;
        });
    }

    public function values()
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id');
    }

    public function isNumber(): bool
    {
        return $this->type === self::NUMBER;
    }

    /**
     * Название для показа: без лишних пробелов и двоеточия в конце.
     */
    public static function cleanName(?string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));

        return rtrim($name, " :\u{00A0}");
    }

    /**
     * Ключ уникальности: «Цвет», «цвет », «ЦВЕТ:» — один ключ.
     */
    public static function key(?string $name): string
    {
        return mb_strtolower(str_replace('ё', 'е', self::cleanName($name)));
    }

    /**
     * Найти по названию или создать (новая — текстовая, без единицы).
     */
    public static function findOrCreateByName(string $name): self
    {
        return self::firstOrCreate(['name_key' => self::key($name)], ['name' => $name]);
    }

    /**
     * Число из значения: «1,5» → 1.5; не число — null.
     */
    public static function parseNumber(?string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }
}
