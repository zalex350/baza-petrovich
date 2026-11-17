<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Цена товара по типу цены (без НДС). Нет строки — у товара нет цены этого типа.
 * Строка типа по умолчанию дублирует products.price (PriceTypes::syncDefault).
 */
class ProductPrice extends Model
{
    protected $fillable = ['product_id', 'price_type_id', 'price'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function type()
    {
        return $this->belongsTo(PriceType::class, 'price_type_id');
    }
}
