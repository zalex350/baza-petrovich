<?php

namespace App\Models;

use App\Services\Vat\VatCalculator;
use App\Services\Vat\VatLine;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'product_name',
        'quantity',
        'price_at_add',
    ];

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * withTrashed: удалённый (soft delete) товар остаётся доступен для показа
     * названия. После физического удаления product_id = null и товара нет вовсе —
     * доступность позиции определяет CartValidator.
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Название для показа: актуальное, пока товар существует, иначе — запомненное при добавлении.
     */
    public function title(): string
    {
        if ($this->product) {
            return $this->product->display_name ?: $this->product->name;
        }

        return $this->product_name ?? 'Товар';
    }

    /**
     * @deprecated Сумма без НДС — vatLine()->net; для показа — vatLine().
     */
    public function getSubtotalAttribute(): float
    {
        return round((float) $this->price_at_add * $this->quantity, 2);
    }

    /**
     * Позиция с НДС: цена на момент добавления, ставка — текущая ставка товара
     * (в заказ она попадёт снимком при оформлении).
     */
    public function vatLine(): VatLine
    {
        return app(VatCalculator::class)->line($this->price_at_add, (int) $this->quantity, $this->product?->vat_rate);
    }
}
