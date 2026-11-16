<?php

namespace App\Jobs;

use App\Models\ImageImport;
use App\Services\Images\BulkImport\BulkImages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Массовая загрузка фото в очереди (BulkImages): одна порция ~BATCH_SECONDS, затем задание ставит
 * себя снова — продолжение с места остановки (ImageImport::processed). Короткие порции не упираются
 * в retry_after очереди и таймаут воркера. Сбой — загрузка failed с причиной, повторов нет.
 */
class ProcessImageImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Порция + одно долгое скачивание по ссылке */
    public int $timeout = 120;

    public function __construct(public int $importId)
    {
        $this->afterCommit();
    }

    public function handle(BulkImages $bulk): void
    {
        $import = ImageImport::find($this->importId);

        if ($import && ! $bulk->runBatch($import)) {
            self::dispatch($import->id);
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($import = ImageImport::find($this->importId)) {
            app(BulkImages::class)->fail($import, 'Сбой обработки: '.($e?->getMessage() ?? 'задание прервано'));
        }
    }
}
