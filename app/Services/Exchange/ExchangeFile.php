<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Чтение файла обмена (openspout): CSV («;», UTF-8) или .xlsx (первый лист), колонки — по
 * заголовку в любом порядке (имена без учёта регистра), лишние колонки игнорируются.
 * Общий для потоков 1 и 2 (CatalogHandler, PricesStockHandler).
 */
class ExchangeFile
{
    /**
     * Строки файла: номер строки => [колонка => значение]; пустые строки пропускаются,
     * но номер строки — как в файле (считая заголовок).
     *
     * @return \Generator<int, array<string, string>>
     *
     * @throws ExchangeJobFailedException нет обязательных колонок
     */
    public static function rows(string $path, ExchangeJobType $type): \Generator
    {
        $xlsx = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx';

        if ($xlsx) {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        } else {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = ';';
            $options->ENCODING = 'UTF-8';
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new CsvReader($options);
        }

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(fn (Cell $cell) => self::cellText($cell), $row->getCells());

                    if ($columns === null) {
                        $columns = array_map(fn ($name) => strtolower(trim($name, " \t\"\u{FEFF}")), $cells);
                        $missing = array_diff($type->requiredColumns(), $columns);

                        if ($missing !== []) {
                            throw new ExchangeJobFailedException('Нет обязательных колонок в первой строке: '.implode(', ', $missing)
                                .(count($columns) === 1 && str_contains($columns[0], ',') ? ' (разделитель CSV — «;»)' : ''));
                        }

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $values = [];
                    foreach ($columns as $i => $name) {
                        $values[$name] = $cells[$i] ?? '';
                    }

                    // .xlsx: дата без формата даты — серийный номер Excel (46336.77) → минское время
                    if ($xlsx && isset($values['updated_at'])) {
                        $values['updated_at'] = self::excelSerial($values['updated_at']) ?? $values['updated_at'];
                    }

                    yield $line => $values;
                }

                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Серийный номер даты Excel (дни с 30.12.1899, дробная часть — время) → ISO без смещения,
     * то есть минское время (ExchangeTime). Не число или вне 1954–2119 гг. — null.
     */
    private static function excelSerial(string $value): ?string
    {
        if (! preg_match('/^\d{5}(\.\d+)?$/', $value) || (float) $value < 20000 || (float) $value > 80000) {
            return null;
        }

        $seconds = (int) round((float) $value * 86400);

        return Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC')->addSeconds($seconds)->format('Y-m-d\TH:i:s');
    }

    /**
     * Ячейка → текст: числа Excel без «.0», дата Excel (без пояса) — ISO без смещения, то есть по Минску.
     */
    private static function cellText(Cell $cell): string
    {
        $value = $cell instanceof Cell\FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return trim(match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => number_format($value, 0, '.', ''),
            is_float($value) => (string) round($value, 6),
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i:s'),
            $value instanceof DateInterval => '',
            default => (string) $value,
        });
    }
}
