<?php

namespace App\Services\Exchange;

/**
 * Итог обработки файла: счётчики строк и ошибки по строкам (exchange_job_errors).
 */
class ExchangeResult
{
    public int $total = 0;

    public int $ok = 0;

    public int $skipped = 0;

    /** Итог для журнала и API (поле message), например «остаток 0 у N товаров» */
    public ?string $message = null;

    /** @var list<array{line: ?int, guid: ?string, sku: ?string, message: string}> */
    public array $errors = [];

    public function error(?int $line, ?string $guid, ?string $sku, string $message): void
    {
        $this->errors[] = ['line' => $line, 'guid' => $guid, 'sku' => $sku, 'message' => $message];
    }
}
