<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price_at_add',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * withTrashed: удалённый товар остаётся в корзине помеченным,
     * а не превращается в null (см. isAvailable()).
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function isAvailable(): bool
    {
        return $this->product !== null && ! $this->product->trashed();
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->price_at_add * $this->quantity, 2);
    }
}
