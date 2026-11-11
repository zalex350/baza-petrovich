<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeApiException;
use App\Exceptions\ExchangeJobFailedException;
use App\Jobs\ProcessExchangeJob;
use App\Models\ExchangeJob;
use App\Models\ExchangeJobError;
use App\Models\ExchangeToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Задания обмена (docs/1C_EXCHANGE_SPEC.md, раздел 8): приём файла в архив и очередь,
 * идемпотентность по X-Exchange-Id, обработка обработчиком типа, оповещения по итогу.
 */
class ExchangeJobs
{
    /** Обработчики по типу; нет в списке — StubHandler (этапы 4–5 заменят) */
    private const HANDLERS = [
        'prices-stock' => PricesStockHandler::class,
    ];

    public function __construct(private readonly ExchangeAlerts $alerts) {}

    /**
     * Задание с этим X-Exchange-Id уже есть: то же (повтор пакета) или 409 при другом типе.
     *
     * @throws ExchangeApiException
     */
    public function existing(?string $exchangeId, ExchangeJobType $type): ?ExchangeJob
    {
        if ($exchangeId === null) {
            return null;
        }

        $job = ExchangeJob::where('exchange_id', $exchangeId)->first();

        if ($job && $job->type !== $type) {
            throw new ExchangeApiException(409, 'exchange_id_conflict',
                "X-Exchange-Id {$exchangeId} уже использован для задания другого типа ({$job->type->value})");
        }

        return $job;
    }

    /**
     * Принятый файл ($upload из ExchangeUpload или ручной загрузки) — в архив и в очередь;
     * $user — ручная загрузка из админки: source = manual, обработка сразу, без очереди.
     *
     * @param  array{path: string, size: int, content_type: string, gzip: bool, extension: string}  $upload
     */
    public function accept(
        array $upload,
        ExchangeJobType $type,
        ?string $mode,
        ?string $exchangeId,
        ?ExchangeToken $token,
        ?string $ip,
        ?User $user = null,
    ): ExchangeJob {
        $manual = $user !== null;

        $job = new ExchangeJob([
            'type' => $type,
            'direction' => $type->direction(),
            'source' => $manual ? ExchangeJob::SOURCE_MANUAL : ExchangeJob::SOURCE_API,
            'user_id' => $user?->id,
            'mode' => $mode,
            'status' => ExchangeJobStatus::Queued,
            'exchange_id' => $exchangeId,
            'token_id' => $token?->id,
            'ip' => $ip,
            'file_size' => $upload['size'],
            'content_type' => $upload['content_type'],
            'gzip' => $upload['gzip'],
            'received_at' => now(),
        ]);
        $job->id = $job->newUniqueId();
        $job->file_path = $job->received_at->format('Y/m').'/'.$job->id.'.'.$upload['extension'];

        $disk = Storage::disk('exchange');
        $stream = fopen($upload['path'], 'rb');
        $disk->writeStream($job->file_path, $stream);
        fclose($stream);
        @unlink($upload['path']);

        try {
            $job->save();
        } catch (UniqueConstraintViolationException $e) {
            // Тот же X-Exchange-Id пришёл параллельно — отдаём задание, сохранённое первым
            $disk->delete($job->file_path);

            return $this->existing($exchangeId, $type) ?? throw $e;
        }

        // Ручная загрузка — сразу, администратор ждёт результат; от 1С — в очереди
        if ($manual) {
            $this->process($job);

            return $job->fresh();
        }

        ProcessExchangeJob::dispatch($job->id);

        return $job;
    }

    /**
     * Обработка в очереди: processing → done | failed, ошибки строк, оповещения.
     */
    public function process(ExchangeJob $job): void
    {
        if ($job->status !== ExchangeJobStatus::Queued) {
            return;
        }

        $job->update(['status' => ExchangeJobStatus::Processing, 'started_at' => now()]);

        $result = new ExchangeResult;

        try {
            if (! $job->hasFile()) {
                throw new \RuntimeException('Файл задания не найден в архиве');
            }

            $handler = app(self::HANDLERS[$job->type->value] ?? StubHandler::class);
            $handler->handle($job, Storage::disk('exchange')->path($job->file_path), $result);

            DB::transaction(function () use ($job, $result) {
                foreach (array_chunk($result->errors, 500) as $chunk) {
                    ExchangeJobError::insert(array_map(fn ($e) => $e + ['exchange_job_id' => $job->id], $chunk));
                }

                $job->update([
                    'status' => ExchangeJobStatus::Done,
                    'rows_total' => $result->total,
                    'rows_ok' => $result->ok,
                    'rows_skipped' => $result->skipped,
                    'rows_error' => count($result->errors),
                    'message' => $result->message,
                    'finished_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // Отказ обработчика от файла (предохранитель) — ожидаемый, в лог не пишется
            if (! $e instanceof ExchangeJobFailedException) {
                report($e);
            }

            $job->update([
                'status' => ExchangeJobStatus::Failed,
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
        }

        $this->alerts->afterJob($job->fresh());
    }
}
