<?php

namespace App\Services\Exchange;

use App\Models\ExchangeJobError;
use App\Support\ColumnLength;

/**
 * Итог обработки файла: счётчики строк, ошибки по строкам (exchange_job_errors) и итог
 * сверх счётчиков — exchange_jobs.report: предупреждения (строка применена), созданные
 * товары с кодами сайта, сводка и товары без пары (номенклатура, раздел 4).
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

    /** @var list<array{line: ?int, guid: ?string, sku: ?string, message: string}> строка применена */
    public array $warnings = [];

    /** @var list<array{line: int, guid: string, sku: string}> номенклатура: созданные товары и их коды */
    public array $created = [];

    /** @var array<string, mixed> номенклатура: сводка (сопоставлено по guid / sku / ключу, поля …) */
    public array $summary = [];

    /** @var list<array{guid: string, sku: ?string}> mode=full: товары с GUID, которых нет в файле */
    public array $missing = [];

    /** @var list<array{guid: ?string, sku: ?string}> пробный прогон: товары сайта без пары в файле */
    public array $unmatched = [];

    public function error(?int $line, ?string $guid, ?string $sku, string $message): void
    {
        // Значения из файла — любой длины: обрезка до колонок exchange_job_errors
        $this->errors[] = ColumnLength::fit(
            ['line' => $line, 'guid' => $guid, 'sku' => $sku, 'message' => $message],
            ExchangeJobError::LENGTHS,
        );
    }

    public function warning(?int $line, ?string $guid, ?string $sku, string $message): void
    {
        $this->warnings[] = ColumnLength::fit(
            ['line' => $line, 'guid' => $guid, 'sku' => $sku, 'message' => $message],
            ExchangeJobError::LENGTHS,
        );
    }

    /**
     * exchange_jobs.report; null — нечего сохранять (цены и остатки).
     *
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        $report = array_filter([
            'summary' => $this->summary,
            'created' => $this->created,
            'warnings' => $this->warnings,
            'missing' => $this->missing,
            'unmatched' => $this->unmatched,
        ], fn ($part) => $part !== []);

        return $report === [] ? null : $report;
    }
}
