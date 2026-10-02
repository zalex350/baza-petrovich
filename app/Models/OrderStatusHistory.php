<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Запись истории статусов заказа (SRS §10.7.5). Только добавляется.
 */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'from_status' => OrderStatus::class,
        'to_status'   => OrderStatus::class,
        'created_at'  => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
