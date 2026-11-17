<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Корзина: клиента — одна на пользователя (unique user_id); гостя в рознице — по токену из cookie
 * (token, user_id пуст; App\Services\CurrentCart). Хранится в БД и не резервирует остаток (BR-099).
 */
class Cart extends Model
{
    protected $fillable = [
        'user_id',
        'token',
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
