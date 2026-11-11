<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ошибка API обмена с 1С: ответ {"error": "код", "message": "текст на русском"}
 * (docs/1C_EXCHANGE_SPEC.md, раздел 2). Рендерит bootstrap/app.php для /api/1c/*.
 */
class ExchangeApiException extends HttpException
{
    public function __construct(int $status, public readonly string $error, string $message, array $headers = [])
    {
        parent::__construct($status, $message, null, $headers);
    }

    public static function badRequest(string $error, string $message): self
    {
        return new self(400, $error, $message);
    }

    public static function tooLarge(int $limitMb): self
    {
        return new self(413, 'payload_too_large', "Файл больше {$limitMb} МБ — сожмите (Content-Encoding: gzip) или разбейте");
    }
}
