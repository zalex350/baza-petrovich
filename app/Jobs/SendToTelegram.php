<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Order;
use App\Services\Integrations\Telegram;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Заказ или заявка → сообщение в Telegram (через очередь, с повторами). Сбой не влияет на заказ.
 */
class SendToTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order|Lead $subject) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(Telegram $telegram): void
    {
        if (! $telegram->enabled()) {
            return;
        }

        $telegram->send($this->subject instanceof Order ? $telegram->orderText($this->subject) : $telegram->leadText($this->subject));
    }
}
