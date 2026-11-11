<?php

namespace App\Http;

use App\Exceptions\ExchangeApiException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Ошибка API обмена с 1С в формате раздела 2 спецификации: {"error": "код", "message": "текст"}.
 * Заголовки исключения сохраняются (Retry-After у 429, WWW-Authenticate у 401).
 * 500 — без подробностей (они в логе).
 */
class ExchangeErrorResponse
{
    private const CODES = [
        400 => ['bad_request', 'Некорректный запрос'],
        401 => ['unauthorized', 'Нет действующего токена'],
        403 => ['forbidden', 'Доступ запрещён'],
        404 => ['not_found', 'Адрес не найден'],
        405 => ['method_not_allowed', 'Метод не поддерживается для этого адреса'],
        409 => ['conflict', 'Конфликт'],
        413 => ['payload_too_large', 'Файл слишком большой'],
        429 => ['too_many_requests', 'Слишком много запросов — повторите через Retry-After секунд'],
        503 => ['service_unavailable', 'Сайт временно недоступен — повторите позже'],
    ];

    public static function from(Throwable $e): JsonResponse
    {
        if ($e instanceof ExchangeApiException) {
            return self::json($e->getStatusCode(), $e->error, $e->getMessage(), $e->getHeaders());
        }

        if ($e instanceof ValidationException) {
            return self::json(400, 'bad_request', collect($e->errors())->flatten()->implode(' '));
        }

        if ($e instanceof ThrottleRequestsException) {
            return self::json(429, ...self::CODES[429], headers: $e->getHeaders());
        }

        if ($e instanceof PostTooLargeException) {
            return self::json(413, ...self::CODES[413]);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $message] = self::CODES[$status] ?? ['http_'.$status, 'Ошибка запроса'];

            return self::json($status, $code, $message, $e->getHeaders());
        }

        report($e);

        return self::json(500, 'server_error', 'Сбой сайта — повторите с тем же X-Exchange-Id через 1, 5, 15 минут');
    }

    private static function json(int $status, string $error, string $message, array $headers = []): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status, $headers, JSON_UNESCAPED_UNICODE);
    }
}
