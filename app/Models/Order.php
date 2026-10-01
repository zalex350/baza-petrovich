<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        'checkout_token',
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
     * Вызывать только внутри транзакции, создающей заказ: upsert атомарно увеличивает
     * счётчик года и держит его строку до коммита, поэтому параллельные оформления
     * получают разные номера, а при откате номер не теряется.
     */
    public static function nextNumber(Carbon $placedAt): string
    {
        $year = (int) $placedAt->format('Y');

        DB::table('order_sequences')->upsert(
            [['year' => $year, 'last_number' => 1]],
            ['year'],
            ['last_number' => DB::raw('order_sequences.last_number + 1')]
        );

        $next = (int) DB::table('order_sequences')->where('year', $year)->value('last_number');

        return sprintf('SM-%d-%06d', $year, $next);
    }

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    const STATUS_DELETED = 'deleted';
}
