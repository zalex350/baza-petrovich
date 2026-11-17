<?php

namespace App\Listeners;

use App\Events\LeadCreated;
use App\Events\OrderPlaced;
use App\Jobs\PushToBitrix24;
use App\Jobs\SendToTelegram;
use App\Models\Lead;
use App\Models\Order;
use App\Services\Integrations\Bitrix24;
use App\Services\Integrations\Telegram;
use Throwable;

/**
 * Новый заказ и заявка с сайта → Telegram и Битрикс24 (задачи в очереди). 1С забирает заказы сама
 * (GET /api/1c/v1/orders/new). Сбой постановки в очередь пишется в лог и не ломает оформление.
 */
class SyncIntegrations
{
    public function __construct(
        private readonly Telegram $telegram,
        private readonly Bitrix24 $bitrix,
    ) {}

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->dispatch($event->order);
    }

    public function handleLeadCreated(LeadCreated $event): void
    {
        $this->dispatch($event->lead);
    }

    private function dispatch(Order|Lead $subject): void
    {
        try {
            if ($this->telegram->enabled()) {
                SendToTelegram::dispatch($subject);
            }

            if ($this->bitrix->enabled()) {
                PushToBitrix24::dispatch($subject);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
