<?php

namespace App\Services;

use App\Enums\OrderExchangeState;
use App\Enums\OrderHistoryEvent;
use App\Enums\OrderStatus;
use App\Events\CancelRequested;
use App\Exceptions\CancelRequestException;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Смена статуса заказа: только по допустимым переходам, с записью в историю
 * (SRS §10.7.5) и пересчётом резерва сайта при отмене (StockReserve).
 * Источник смены — сайт или 1С (source = 1c: переходы OrderStatus::canTransitionFrom1c,
 * в истории — пометка «1С» и время смены в 1С). Клиент сам не отменяет —
 * он создаёт запрос на отмену, администратор подтверждает или отклоняет его.
 */
class OrderStatusService
{
    public const SOURCE_SITE = 'site';

    public const SOURCE_1C = '1c';

    public function __construct(private readonly StockReserve $reserve) {}

    /**
     * @throws InvalidStatusTransition
     */
    public function change(
        Order $order,
        OrderStatus $to,
        ?User $by = null,
        ?string $comment = null,
        string $source = self::SOURCE_SITE,
        ?Carbon $changedAt1c = null,
    ): Order {
        return DB::transaction(function () use ($order, $to, $by, $comment, $source, $changedAt1c) {
            // Блокировка заказа: два одновременных изменения не пройдут оба от одного исходного статуса
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $from = $order->status;

            $allowed = $source === self::SOURCE_1C ? $from->canTransitionFrom1c($to) : $from->canTransitionTo($to);

            if (! $allowed) {
                throw new InvalidStatusTransition($from, $to);
            }

            if ($to === OrderStatus::Cancelled) {
                // Заказ отменён — открытый запрос на отмену исполнен
                $this->closeCancelRequest($order);
            }

            // «Передан в 1С» вручную (обмена ещё нет): заказ зарегистрирован в 1С сейчас —
            // резерв держится, пока не придёт остаток 1С новее (StockReserve)
            if ($to === OrderStatus::SentTo1c && $source === self::SOURCE_SITE && $order->exchange_state !== OrderExchangeState::Sent) {
                $order->exchange_state = OrderExchangeState::Sent;
                $order->registered_at ??= now();
            }

            $order->status = $to;
            $order->save();

            // Отменённый заказ не резервирует остаток
            if ($to === OrderStatus::Cancelled) {
                $this->reserve->recalculateOrder($order->id);
            }

            $this->record($order, $from, $to, $by, $comment, $source, $changedAt1c);

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
                'Отмена по запросу клиента'.($comment !== '' ? ": {$comment}" : '')
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

    /**
     * Снимает открытый запрос на отмену: при любой отмене заказа (в том числе по запросу)
     * и при отказе. Сохраняет вызывающий. Сам запрос остаётся в order_status_history.
     */
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
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'event' => $event,
            'user_id' => $by->id,
            'comment' => $comment !== '' ? $comment : null,
        ]);
    }

    /**
     * Запись истории о смене статуса. $from = null — оформление заказа (recordPlaced);
     * $by = null — смена без пользователя; пустой комментарий хранится как null.
     */
    private function record(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $by,
        ?string $comment,
        string $source = self::SOURCE_SITE,
        ?Carbon $changedAt1c = null,
    ): void {
        $comment = trim((string) $comment);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $by?->id,
            'comment' => $comment !== '' ? $comment : null,
            'source' => $source,
            'changed_at_1c' => $changedAt1c,
        ]);
    }
}
