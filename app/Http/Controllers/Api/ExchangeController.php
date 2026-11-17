<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeApiException;
use App\Exceptions\OrderExchangeException;
use App\Http\Controllers\Controller;
use App\Models\ExchangeJob;
use App\Models\Order;
use App\Services\Exchange\ExchangeJobs;
use App\Services\Exchange\ExchangeUpload;
use App\Services\Exchange\OrdersFeed;
use App\Services\Exchange\SkuMap;
use App\Services\OrderExchange;
use App\Support\ExchangeTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    /** Поток 3: подтверждённые менеджером заказы для записи в 1С (раздел 6) */
    public function ordersNew(Request $request, OrdersFeed $feed): JsonResponse
    {
        $orders = $feed->pending($request->integer('limit', 50));

        return response()->json([
            'orders' => $orders->map(fn (Order $order) => $feed->payload($order))->values(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Поток 4: 1С записала заказ */
    public function orderConfirm(Request $request, string $number, OrderExchange $exchange): JsonResponse
    {
        $data = $request->validate([
            'guid_1c' => ['required', 'string', 'max:64'],
            'number_1c' => ['nullable', 'string', 'max:64'],
            'registered_at' => ['nullable', 'string', 'max:40'],
        ]);

        $registeredAt = ExchangeTime::parse($data['registered_at'] ?? null) ?? now();
        $order = $this->exchangeOrder($number);

        try {
            $order = $exchange->confirm($order, $data['guid_1c'], $data['number_1c'] ?? null, $registeredAt);
        } catch (OrderExchangeException $e) {
            throw new ExchangeApiException(409, $e->error, $e->getMessage());
        }

        return response()->json(['status' => 'ok', 'number' => $order->number, 'order_status' => $order->status->value], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Поток 4: 1С не смогла записать заказ */
    public function orderReject(Request $request, string $number, OrderExchange $exchange): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $exchange->reject($this->exchangeOrder($number), $data['reason']);
        } catch (OrderExchangeException $e) {
            throw new ExchangeApiException(409, $e->error, $e->getMessage());
        }

        return response()->json(['status' => 'ok', 'number' => $number], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function exchangeOrder(string $number): Order
    {
        return Order::where('number', $number)->first()
            ?? throw new ExchangeApiException(404, 'not_found', "Заказ {$number} не найден");
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

    /**
     * Карта «guid;sku» товаров с GUID (раздел 4, «Карта кодов»); Accept-Encoding: gzip — сжатый ответ.
     */
    public function skuMap(Request $request, SkuMap $map): Response
    {
        $body = $map->csv();
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sku-map.csv"',
            'Vary' => 'Accept-Encoding',
        ];

        if (self::acceptsGzip((string) $request->headers->get('Accept-Encoding'))) {
            $body = gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }

        return response($body, 200, $headers);
    }

    /** gzip есть в Accept-Encoding и не запрещён (q=0) */
    private static function acceptsGzip(string $header): bool
    {
        foreach (explode(',', strtolower($header)) as $part) {
            $params = array_map('trim', explode(';', $part));

            if (in_array($params[0], ['gzip', 'x-gzip'], true)) {
                foreach (array_slice($params, 1) as $param) {
                    if (preg_match('/^q=0(\.0{0,3})?$/', $param)) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
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
