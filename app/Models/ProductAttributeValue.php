<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Значение характеристики у товара. Один ключ на товар (BR-059), порядок — sort_order (BR-061).
 */
class ProductAttributeValue extends Model
{
    protected $fillable = ['product_id', 'attribute_id', 'value', 'value_number', 'sort_order'];

    protected $casts = [
        'value_number' => 'float',
        'sort_order'   => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute()
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /**
     * «16 мм», «Хром» — значение с единицей для карточки.
     */
    public function displayValue(): string
    {
        $unit = $this->attribute?->unit;

        return $unit ? "{$this->value} {$unit}" : $this->value;
    }
}
