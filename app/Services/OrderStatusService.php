<?php

namespace App\Services;

use App\Enums\OrderHistoryEvent;
use App\Enums\OrderStatus;
use App\Events\CancelRequested;
use App\Exceptions\CancelRequestException;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Смена статуса заказа: только по допустимым переходам, с записью в историю
 * (SRS §10.7.5) и возвратом остатка при отмене. Клиент сам не отменяет —
 * он создаёт запрос на отмену, администратор подтверждает или отклоняет его.
 */
class OrderStatusService
{
    /**
     * @throws InvalidStatusTransition
     */
    public function change(Order $order, OrderStatus $to, ?User $by = null, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $to, $by, $comment) {
            // Блокировка заказа: два одновременных изменения не пройдут оба от одного исходного статуса
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStatusTransition($from, $to);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->returnStock($order);
                // Заказ отменён — открытый запрос на отмену исполнен
                $this->closeCancelRequest($order);
            }

            $order->status = $to;
            $order->save();

            $this->record($order, $from, $to, $by, $comment);

            return $order;
        });
    }

    /**
     * Запрос клиента на отмену: статус не меняется, запрос ждёт решения администратора.
     *
     * @throws CancelRequestException
     */
    public function requestCancel(Order $order, User $by, string $reason): Order
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new CancelRequestException(CancelRequestException::NOT_ALLOWED);
            }

            if ($order->hasOpenCancelRequest()) {
                throw new CancelRequestException(CancelRequestException::ALREADY_OPEN);
            }

            $order->cancel_requested_at = now();
            $order->cancel_requested_by = $by->id;
            $order->cancel_reason = trim($reason);
            $order->save();

            $this->recordEvent($order, OrderHistoryEvent::CancelRequested, $by, $order->cancel_reason);

            // Письмо администраторам — слушатель после коммита (ShouldDispatchAfterCommit)
            CancelRequested::dispatch($order);

            return $order;
        });
    }

    /**
     * Администратор подтверждает запрос: заказ отменяется, остаток возвращается (один раз).
     *
     * @throws CancelRequestException
     */
    public function approveCancel(Order $order, User $by, ?string $comment = null): Order
    {
        return DB::transaction(function () use ($order, $by, $comment) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->hasOpenCancelRequest()) {
                throw new CancelRequestException(CancelRequestException::NO_REQUEST);
            }

            if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new CancelRequestException(CancelRequestException::TOO_LATE);
            }

            $comment = trim((string) $comment);

            return $this->change(
                $order,
                OrderStatus::Cancelled,
                $by,
                'Отмена по запросу клиента' . ($comment !== '' ? ": {$comment}" : '')
            );
        });
    }

    /**
     * Администратор отклоняет запрос: запрос снимается, статус не меняется.
     *
     * @throws CancelRequestException
     */
    public function rejectCancel(Order $order, User $by, string $comment): Order
    {
        return DB::transaction(function () use ($order, $by, $comment) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->hasOpenCancelRequest()) {
                throw new CancelRequestException(CancelRequestException::NO_REQUEST);
            }

            $this->closeCancelRequest($order);
            $order->save();

            $this->recordEvent($order, OrderHistoryEvent::CancelRejected, $by, $comment);

            return $order;
        });
    }

    /**
     * Первая запись истории: заказ оформлен.
     */
    public function recordPlaced(Order $order, User $by): void
    {
        $this->record($order, null, $order->status, $by, null);
    }

    private function closeCancelRequest(Order $order): void
    {
        $order->cancel_requested_at = null;
        $order->cancel_requested_by = null;
        $order->cancel_reason = null;
    }

    /**
     * Событие без смены статуса: в записи текущий статус «из» и «в».
     */
    private function recordEvent(Order $order, OrderHistoryEvent $event, User $by, ?string $comment): void
    {
        $comment = trim((string) $comment);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => $order->status,
            'to_status'   => $order->status,
            'event'       => $event,
            'user_id'     => $by->id,
            'comment'     => $comment !== '' ? $comment : null,
        ]);
    }

    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $by, ?string $comment): void
    {
        $comment = trim((string) $comment);

        OrderStatusHistory::create([
            'order_id'    => $order->id,
            'from_status' => $from,
            'to_status'   => $to,
            'user_id'     => $by?->id,
            'comment'     => $comment !== '' ? $comment : null,
        ]);
    }

    /**
     * Возвращает на склад списанное при оформлении. stock_returned_at делает возврат
     * идемпотентным: повторный вызов для того же заказа ничего не меняет.
     */
    private function returnStock(Order $order): void
    {
        if ($order->stock_returned_at !== null) {
            return;
        }

        $quantities = $order->items()
            ->whereNotNull('product_id')
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map->sum('quantity');

        // withTrashed: остаток возвращается и удалённому (soft delete) товару — его могут восстановить.
        // Физически удалённому товару (product_id = null) возвращать некуда.
        $products = Product::withTrashed()
            ->whereIn('id', $quantities->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($products as $product) {
            $product->stock += $quantities[$product->id];
            $product->save();
        }

        $order->stock_returned_at = now();
    }
}
