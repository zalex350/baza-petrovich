<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Код товара у организации-клиента (её собственный артикул нашего товара).
 * Уникально: (organization_id, product_id) — один код на товар; (organization_id, code) —
 * код не повторяется у организации. Правка — в админке (AdminClientCodeController), docs/MODULES.md §11.
 * code_key — ключ поиска (key()): заполняется при сохранении; при массовой вставке — вручную.
 */
class ProductClientCode extends Model
{
    protected $fillable = [
        'product_id',
        'organization_id',
        'code',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $code) => $code->code_key = self::key((string) $code->code));
    }

    /** Ключ сравнения кодов: без регистра (и кириллица) и пробелов по краям */
    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
