<?php

namespace App\Services\CatalogImport;

use Illuminate\Support\Facades\Schema;

/**
 * Ограничения длины колонок по схеме БД (на MySQL — information_schema через Schema::getColumns):
 * varchar(N) / char(N) — символов; text / mediumtext / longtext — байтов.
 * Проверка плана импорта до записи: нарушение — ошибка в отчёте, боевой прогон не стартует.
 */
class ColumnLimits
{
    private const TEXT_BYTES = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'longtext' => 4294967295];

    /** @var array<string, array{0: string, 1: int}> «таблица.колонка» → [chars|bytes, предел] */
    private array $limits;

    /**
     * @param  array<string, array{0: string, 1: int}>|null  $limits  для тестов; null — из схемы
     */
    public function __construct(?array $limits = null)
    {
        $this->limits = $limits ?? $this->fromSchema(['products', 'product_attribute_values', 'attributes', 'categories']);
    }

    private function fromSchema(array $tables): array
    {
        $out = [];

        foreach ($tables as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $type = strtolower((string) ($column['type'] ?? ''));

                if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $m)) {
                    $out["{$table}.{$column['name']}"] = ['chars', (int) $m[1]];
                } elseif (isset(self::TEXT_BYTES[$type])) {
                    $out["{$table}.{$column['name']}"] = ['bytes', self::TEXT_BYTES[$type]];
                }
            }
        }

        return $out;
    }

    /**
     * Длина значения сверх предела колонки: [предел, единица, длина] или null (в пределах / предел неизвестен).
     *
     * @return array{0: int, 1: string, 2: int}|null
     */
    public function exceeds(string $column, ?string $value): ?array
    {
        if ($value === null || ! isset($this->limits[$column])) {
            return null;
        }

        [$unit, $limit] = $this->limits[$column];
        $length = $unit === 'bytes' ? strlen($value) : mb_strlen($value);

        return $length > $limit ? [$limit, $unit === 'bytes' ? 'байт' : 'символов', $length] : null;
    }
}
