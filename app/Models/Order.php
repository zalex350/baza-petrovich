<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\Vat\VatTotal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $total_without_vat Итог без НДС — сумма позиций (снимок при оформлении)
 * @property string $total_vat Итог НДС — сумма НДС позиций
 * @property string $total_with_vat Итог с НДС
 * @property string $total @deprecated Прежняя сумма без НДС; у старых заказов может не совпадать
 *                         с суммой позиций. Не показывать — использовать total_* / vatTotals().
 *                         Пишется при оформлении только для совместимости.
 */
class Order extends Model
{
    protected $fillable = [
        'number',
        'user_id',
        'status',
        'total', // @deprecated — см. total_without_vat
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
        'cancel_requested_at',
        'cancel_requested_by',
        'cancel_reason',
    ];

    /**
     * Итоги НДС — суммы позиций (снимок при оформлении). total устарел (@deprecated):
     * прежняя сумма без НДС, оставлена для совместимости; на экранах — только total_*.
     */
    protected $casts = [
        'status' => OrderStatus::class,
        'total_without_vat' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_with_vat' => 'decimal:2',
        'placed_at' => 'datetime',
        'stock_returned_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
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

    /**
     * Открытый запрос клиента на отмену — ждёт решения администратора.
     */
    public function hasOpenCancelRequest(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * Запросить отмену может только оформивший заказ, пока заказ «Новый» или
     * «Подтверждён» и открытого запроса нет.
     */
    public function canRequestCancel(User $user): bool
    {
        return $this->user_id === $user->id
            && $this->status->canTransitionTo(OrderStatus::Cancelled)
            && ! $this->hasOpenCancelRequest();
    }

    public function cancelRequester()
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
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
