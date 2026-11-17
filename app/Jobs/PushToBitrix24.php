<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Order;
use App\Services\Integrations\Bitrix24;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Заказ или заявка → лид Битрикс24 (через очередь, с повторами). ID лида — в crm_id: уже переданный
 * повторно не отправляется.
 */
class PushToBitrix24 implements ShouldQueue
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

    public function handle(Bitrix24 $bitrix): void
    {
        $subject = $this->subject->fresh();

        if (! $bitrix->enabled() || ! $subject || $subject->crm_id) {
            return;
        }

        $id = $subject instanceof Order ? $bitrix->pushOrder($subject) : $bitrix->pushLead($subject);

        $subject->forceFill(['crm_id' => $id])->save();
    }
}
