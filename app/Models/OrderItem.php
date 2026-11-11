<?php

namespace App\Models;

use App\Services\Vat\VatCalculator;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_sku',
        'product_article',
        'client_code',
        'product_guid',
        'unit',
        'quantity',
        'price',
        'vat_rate',
        'amount_without_vat',
        'vat_amount',
        'amount_with_vat',
    ];

    /**
     * Снимок на момент оформления: price — цена без НДС, vat_rate (null — «Без НДС»),
     * суммы посчитаны VatCalculator и дальше не пересчитываются.
     */
    protected $casts = [
        'amount_without_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount_with_vat' => 'decimal:2',
    ];

    /**
     * Артикул позиции для покупателя: снимок артикула производителя, без него — снимок кода сайта.
     */
    public function displayArticle(): string
    {
        return trim((string) $this->product_article) !== '' ? $this->product_article : (string) $this->product_sku;
    }

    public function getVatRateLabelAttribute(): string
    {
        return VatCalculator::rateLabel($this->vat_rate);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
