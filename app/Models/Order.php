<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Order extends Model
{
    protected $fillable = [
        'number',
        'user_id',
        'status',
        'total',
        'is_paid',
        'organization_id',
        'placed_at',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(\App\Models\OrderItem::class, 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Следующий номер заказа вида SM-ГГГГ-000001 (BR-132), сквозной в пределах года.
     * Вызывать только внутри транзакции, создающей заказ: lockForUpdate сериализует
     * параллельные оформления, unique-индекс на number — страховка.
     */
    public static function nextNumber(Carbon $placedAt): string
    {
        $prefix = 'SM-' . $placedAt->format('Y') . '-';

        $last = static::where('number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    const STATUS_DELETED = 'deleted';
}
