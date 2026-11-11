<?php

namespace App\Services;

use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Exceptions\OrderExchangeException;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Подтверждение и отказ 1С по заказу (docs/1C_EXCHANGE_SPEC.md, раздел 6, поток 4).
 * Будущий API v1 вызывает эти методы; здесь — только модель и резерв.
 */
class OrderExchange
{
    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly StockReserve $reserve,
    ) {}

    /**
     * 1С записала заказ: номер и GUID 1С, состояние «передан», статус «Передан в 1С».
     * Повтор с тем же GUID ничего не меняет; другой GUID — guid_conflict; отменённый
     * на сайте заказ — order_cancelled. Резерв держится, пока не придёт остаток 1С,
     * снятый не раньше registered_at (StockReserve).
     *
     * @throws OrderExchangeException
     */
    public function confirm(Order $order, string $guid, ?string $number, Carbon $registeredAt): Order
    {
        return DB::transaction(function () use ($order, $guid, $number, $registeredAt) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->guid_1c !== null) {
                if ($order->guid_1c === $guid) {
                    return $order;
                }

                throw new OrderExchangeException(OrderExchangeException::GUID_CONFLICT);
            }

            if ($order->status === OrderStatus::Cancelled) {
                throw new OrderExchangeException(OrderExchangeException::ORDER_CANCELLED);
            }

            $order->forceFill([
                'guid_1c' => $guid,
                'number_1c' => $number,
                'registered_at' => $registeredAt,
                'exchange_state' => OrderExchangeState::Sent,
                'reject_reason' => null,
            ])->save();

            // Заказ, переведённый в «Передан в 1С» вручную до обмена, статус не меняет
            if ($order->status->canTransitionFrom1c(OrderStatus::SentTo1c)) {
                $order = $this->statuses->change($order, OrderStatus::SentTo1c, source: OrderStatusService::SOURCE_1C, changedAt1c: $registeredAt);
            }

            $this->reserve->recalculateOrder($order->id);

            return $order;
        });
    }

    /**
     * 1С не смогла записать заказ: состояние «отклонён» с причиной — на разбор менеджеру.
     * Статус для покупателя не меняется; отклонённый заказ не резервирует остаток.
     *
     * @throws OrderExchangeException
     */
    public function reject(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->guid_1c !== null) {
                throw new OrderExchangeException(OrderExchangeException::ALREADY_REGISTERED);
            }

            if ($order->status === OrderStatus::Cancelled) {
                throw new OrderExchangeException(OrderExchangeException::ORDER_CANCELLED);
            }

            $order->forceFill([
                'exchange_state' => OrderExchangeState::Rejected,
                'reject_reason' => trim($reason),
            ])->save();

            $this->reserve->recalculateOrder($order->id);

            return $order;
        });
    }
}
