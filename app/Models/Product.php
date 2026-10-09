<?php

namespace App\Models;

use App\Services\Vat\VatCalculator;
use App\Services\Vat\VatLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'display_name',
        'sku',
        'price',
        'vat_rate',
        'stock',
        'description',
        'category_id',
        'specifications',
        'brand',
        'slug',
        'is_active',
        'is_published',
        'package_contents',
    ];

    protected $attributes = [
        'vat_rate' => 20.00,
    ];

    /**
     * Цена в БД — без НДС; vat_rate = null — «Без НДС».
     * Расчёт — VatCalculator; здесь — справочно за одну единицу.
     */
    public function vatLine(int $quantity = 1): VatLine
    {
        return app(VatCalculator::class)->line($this->price, $quantity, $this->vat_rate);
    }

    public function getVatAmountAttribute(): float
    {
        return $this->vatLine()->vat;
    }

    public function getPriceWithVatAttribute(): float
    {
        return $this->vatLine()->gross;
    }

    public function getVatRateLabelAttribute(): string
    {
        return VatCalculator::rateLabel($this->vat_rate);
    }

    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class);
    }

    /**
     * Галерея в порядке показа (BR-052).
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Галерея карточки: основное фото первым, остальные — в порядке из админки (BR-051, BR-052).
     *
     * @return \Illuminate\Support\Collection<int, ProductImage>
     */
    public function gallery()
    {
        return $this->images->sortByDesc('is_main')->values();
    }

    /**
     * Основное фото (BR-051) — для списков товаров, корзины, заказов.
     */
    public function mainImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_main', true);
    }

    public function draft()
    {
        return $this->hasOne(\App\Models\ProductDraft::class);
    }
}