<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Корзина пользователя: одна на пользователя (unique user_id), хранится в БД
 * и не резервирует остаток (BR-099).
 */
class Cart extends Model
{
    protected $fillable = [
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }
}
