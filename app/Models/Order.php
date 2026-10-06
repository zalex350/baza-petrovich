<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\Vat\VatTotal;
use Illuminate\Database\Eloquent\Builder;
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
        'total_without_vat',
        'total_vat',
        'total_with_vat',
        'is_paid',
        'organization_id',
        'delivery_title',
        'delivery_address',
        'delivery_contact_name',
        'delivery_contact_phone',
        'comment',
        'placed_at',
        'checkout_token',
        'stock_returned_at',
    ];

    /**
     * Итоги НДС — суммы позиций (снимок при оформлении). total — прежняя сумма
     * без НДС, оставлена для совместимости; на экранах — total_*.
     */
    protected $casts = [
        'status' => OrderStatus::class,
        'total_without_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_with_vat' => 'decimal:2',
        'placed_at' => 'datetime',
        'stock_returned_at' => 'datetime',
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
     * Итоги НДС из снимка заказа.
     */
    public function vatTotals(): VatTotal
    {
        return new VatTotal((float) $this->total_without_vat, (float) $this->total_vat, (float) $this->total_with_vat);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * Заказы, которые видит пользователь в кабинете (BR-113, BR-114):
     * все заказы его организации; без организации — только свои.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->organization_id
            ? $query->where('organization_id', $user->organization_id)
            : $query->where('user_id', $user->id);
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
}
