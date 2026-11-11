<?php

namespace App\Jobs;

use App\Models\ExchangeJob;
use App\Services\Exchange\ExchangeJobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Обработка задания обмена в очереди (воркер — сервис queue). Повторов нет: сбой —
 * задание failed с причиной, 1С отправляет файл заново с новым X-Exchange-Id.
 */
class ProcessExchangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Большой файл номенклатуры — до 10 минут */
    public int $timeout = 600;

    public function __construct(public string $jobId)
    {
        $this->afterCommit();
    }

    public function handle(ExchangeJobs $jobs): void
    {
        $job = ExchangeJob::find($this->jobId);

        if ($job) {
            $jobs->process($job);
        }
    }
}
