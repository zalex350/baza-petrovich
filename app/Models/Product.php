<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'sku',
        'price',
        'vat_rate',
        'stock',
        'description',
        'category_id',
        'image',
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

    public function getVatAmountAttribute(): float
    {
        return round((float) $this->price * (float) $this->vat_rate / 100, 2);
    }

    public function getPriceWithVatAttribute(): float
    {
        return round((float) $this->price + $this->vat_amount, 2);
    }

    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class);
    }

    public function images()
    {
        return $this->hasMany(\App\Models\ProductImage::class);
    }
}