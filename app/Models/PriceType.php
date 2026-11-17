<?php

namespace App\Models;

use App\Services\Pricing\PriceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Тип цены (справочник «Типы цен»): код из 1С (колонка price_<код> файла цен), название — только
 * для админки (клиент его не видит), скрытый — не применяется к клиентам (вместо него — цена по
 * умолчанию), по умолчанию — ровно один: его цена зеркалится в products.price.
 * Применение к клиенту — App\Services\Pricing\PriceContext.
 */
class PriceType extends Model
{
    /** Код старой колонки price файла цен (совместимость: price = price_opt) */
    public const LEGACY_CODE = 'opt';

    /** Код в колонке файла: латиница, цифры, «_», «-» */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,29}$/';

    protected $fillable = ['code', 'name', 'is_hidden', 'is_default', 'sort_order'];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // Справочник закэширован в PriceContext на процесс
        static::saved(fn () => app(PriceContext::class)->flush());
        static::deleted(fn () => app(PriceContext::class)->flush());
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Подпись для админки: «Оптовая (opt)» */
    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
