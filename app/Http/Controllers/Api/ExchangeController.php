<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeApiException;
use App\Http\Controllers\Controller;
use App\Models\ExchangeJob;
use App\Services\Exchange\ExchangeJobs;
use App\Services\Exchange\ExchangeUpload;
use App\Support\ExchangeTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API обмена с 1С v1 (docs/1C_EXCHANGE_SPEC.md, разделы 2–5, 8): приём файлов в очередь
 * и результат заданий. Доступ — AuthenticateExchange, частота — throttle:exchange-1c.
 */
class ExchangeController extends Controller
{
    public function __construct(
        private readonly ExchangeUpload $upload,
        private readonly ExchangeJobs $jobs,
    ) {}

    /** Проверка связи и токена */
    public function ping(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'server_time' => ExchangeTime::format(now()),
            'token' => $request->attributes->get('exchange_token')->name,
            'ip' => $request->ip(),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Поток 1: номенклатура (CSV); dry_run=1 — пробный прогон без записи (раздел 4, правило 10) */
    public function catalog(Request $request): JsonResponse
    {
        return $this->accept($request, ExchangeJobType::Catalog, ['text/csv'], $request->boolean('dry_run'));
    }

    /** Поток 2: цены и остатки (CSV) */
    public function pricesStock(Request $request): JsonResponse
    {
        return $this->accept($request, ExchangeJobType::PricesStock, ['text/csv']);
    }

    public function job(string $id): JsonResponse
    {
        $job = ExchangeJob::find($id);

        if (! $job) {
            throw new ExchangeApiException(404, 'not_found', "Задание {$id} не найдено");
        }

        return response()->json($job->toApi(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<string>  $contentTypes
     */
    private function accept(Request $request, ExchangeJobType $type, array $contentTypes, bool $dryRun = false): JsonResponse
    {
        $mode = $request->query('mode', 'delta');

        if (! in_array($mode, ['full', 'delta'], true)) {
            throw ExchangeApiException::badRequest('bad_mode', 'mode — full или delta');
        }

        $exchangeId = $request->headers->get('X-Exchange-Id');

        if ($exchangeId !== null && ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $exchangeId)) {
            throw ExchangeApiException::badRequest('bad_exchange_id', 'X-Exchange-Id — до 64 символов: латиница, цифры, дефис (GUID)');
        }

        // Повтор пакета — прежнее задание, тело не читается и не обрабатывается заново
        $job = $this->jobs->existing($exchangeId, $type)
            ?? $this->jobs->accept(
                $this->upload->receive($request, $type, $contentTypes),
                $type,
                $mode,
                $exchangeId,
                $request->attributes->get('exchange_token'),
                $request->ip(),
                dryRun: $dryRun,
            );

        return response()->json(['job_id' => $job->id, 'status' => $job->status->value], 202);
    }
}
