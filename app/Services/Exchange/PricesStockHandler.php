<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use App\Models\ExchangeJob;
use App\Services\StockReserve;
use App\Support\BulkUpdate;
use App\Support\ExchangeSettings;
use App\Support\ExchangeTime;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Поток 2 — цены и остатки 1С → сайт (docs/1C_EXCHANGE_SPEC.md, раздел 5); тот же формат —
 * ручная загрузка из админки (CSV или .xlsx, первый лист).
 *
 * Файл (колонки по заголовку в любом порядке) читает openspout. plan() — без записи:
 *  1. разбор и проверка всех строк: товар — по guid, иначе по sku (код сайта: точное
 *     совпадение без учёта регистра и пробелов по краям, только у товара без другого GUID;
 *     по артикулу — если включено SHOP_1C_MATCH_BY_ARTICLE); цена (точка,
 *     до 2 знаков, ≥ 0, пусто — «Цена уточняется»), остаток (целое ≥ 0), stock_status,
 *     updated_at (ExchangeTime::parse, любое смещение; дата .xlsx без пояса — по Минску).
 *     Строка старше уже применённого (отдельно для цены — price_synced_at и остатка —
 *     stock_synced_at) — rows_skipped;
 *  2. mode=full: товары с GUID, которых нет в файле, — stock_1c = 0; предохранитель:
 *     не хватает больше доли из настроек (10 %) — ExchangeJobFailedException.
 * handle() — план и запись пачками по 500 товаров в транзакции: BulkUpdate (stock_1c, price,
 * on_order, *_synced_at) и пересчёт доступного остатка StockReserve::recalculate.
 * stock_status: on_order — «под заказ», in_stock / none — нет; колонки нет или пусто —
 * признак не меняется.
 */
class PricesStockHandler implements ExchangeHandler
{
    private const BATCH = 500;

    private const STATUSES = ['in_stock' => false, 'on_order' => true, 'none' => false];

    /** @var array<int, string> id товара => его guid (в нижнем регистре) */
    private array $guids = [];

    /** @var array<string, list<int>> артикул => товары без GUID (SHOP_1C_MATCH_BY_ARTICLE) */
    private array $byArticle = [];

    public function __construct(private readonly StockReserve $reserve) {}

    public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void
    {
        $plan = $this->plan($path, $job->mode, $result);

        foreach (array_chunk($plan->changes, self::BATCH, true) as $chunk) {
            DB::transaction(function () use ($chunk) {
                BulkUpdate::run('products', $chunk);
                $this->reserve->recalculate(array_keys($chunk));
            });
        }
    }

    /**
     * План без записи. Формат — по расширению файла: .xlsx — Excel, иначе CSV («;», UTF-8).
     *
     * @throws ExchangeJobFailedException нет обязательных колонок; предохранитель mode=full
     */
    public function plan(string $path, ?string $mode, ?ExchangeResult $result = null): PricesStockPlan
    {
        $plan = new PricesStockPlan($result ?? new ExchangeResult);
        $result = $plan->result;

        [$byGuid, $bySku, $state] = $this->products();

        $present = [];
        $snapshot = null;

        foreach ($this->rows($path) as $line => $row) {
            $result->total++;

            $guid = trim((string) ($row['guid'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? ''));
            $errors = [];

            $id = $this->find($guid, $sku, $byGuid, $bySku, $errors);

            if ($id !== null) {
                $present[$id] = true;
            }

            $price = $this->price((string) ($row['price'] ?? ''), $errors);
            $stock = $this->stock((string) ($row['stock'] ?? ''), $errors);
            $status = strtolower(trim((string) ($row['stock_status'] ?? '')));

            if ($status !== '' && ! array_key_exists($status, self::STATUSES)) {
                $errors[] = "Признак наличия «{$status}»: ожидается in_stock, on_order или none";
            }

            $updatedAt = ExchangeTime::parse($row['updated_at'] ?? null)?->startOfSecond();

            if (! $updatedAt) {
                $errors[] = 'Дата «'.($row['updated_at'] ?? '').'»: ожидается ISO 8601, например 2026-11-10T18:30:00+03:00';
            }

            if ($errors !== []) {
                $result->error($line, $guid ?: null, $sku ?: null, implode('; ', $errors));

                continue;
            }

            $snapshot = $snapshot === null || $updatedAt->gt($snapshot) ? $updatedAt : $snapshot;

            // Старше уже применённого — отдельно для цены и остатка (повтор старого файла)
            $current = &$state[$id];
            $applyPrice = $current['price_synced_at'] === null || $updatedAt->gte($current['price_synced_at']);
            $applyStock = $current['stock_synced_at'] === null || $updatedAt->gte($current['stock_synced_at']);

            if (! $applyPrice && ! $applyStock) {
                $result->skipped++;
                unset($current);

                continue;
            }

            $before = $current['values'];

            if ($applyPrice) {
                $plan->changes[$id]['price'] = $price;
                $plan->changes[$id]['price_synced_at'] = $updatedAt->format('Y-m-d H:i:s');
                $current['price_synced_at'] = $updatedAt;
                $current['values']['price'] = $price;
            }

            if ($applyStock) {
                $plan->changes[$id]['stock_1c'] = $stock;
                $plan->changes[$id]['stock_synced_at'] = $updatedAt->format('Y-m-d H:i:s');
                $current['stock_synced_at'] = $updatedAt;
                $current['values']['stock_1c'] = $stock;

                if ($status !== '') {
                    $plan->changes[$id]['on_order'] = (int) self::STATUSES[$status];
                    $current['values']['on_order'] = self::STATUSES[$status];
                }
            }

            if ($current['values'] === $before) {
                $plan->unchanged++;
            } else {
                $plan->updated[$id] = ['before' => $plan->updated[$id]['before'] ?? $before, 'after' => $current['values']];
            }

            unset($current);
            $result->ok++;
        }

        if ($mode === 'full') {
            $this->zeroMissing($state, $present, $snapshot ?? now()->startOfSecond(), $plan);
        }

        return $plan;
    }

    /**
     * Строки файла: номер строки => [колонка => значение]; пустые строки пропускаются,
     * но номер строки — как в файле (считая заголовок).
     *
     * @return \Generator<int, array<string, string>>
     *
     * @throws ExchangeJobFailedException нет обязательных колонок
     */
    private function rows(string $path): \Generator
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
                        $missing = array_diff(ExchangeJobType::PricesStock->requiredColumns(), $columns);

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

    /**
     * Товары сайта (и удалённые мягко — могут вернуть): по guid, по sku (и по артикулу, если
     * включено shop.exchange_1c.match_by_article), текущие значения и отметки обмена.
     *
     * @return array{0: array<string, int>, 1: array<string, list<int>>, 2: array<int, array<string, mixed>>}
     */
    private function products(): array
    {
        $byGuid = [];
        $bySku = [];
        $state = [];
        $this->byArticle = [];
        $byArticle = (bool) config('shop.exchange_1c.match_by_article', false);

        DB::table('products')->orderBy('id')
            ->select(['id', 'guid', 'sku', 'article', 'price', 'stock_1c', 'on_order', 'price_synced_at', 'stock_synced_at', 'deleted_at'])
            ->lazy(2000)
            ->each(function ($p) use (&$byGuid, &$bySku, &$state, $byArticle) {
                if ($p->guid !== null && $p->guid !== '') {
                    $byGuid[strtolower($p->guid)] = $p->id;
                }

                if (($key = self::key($p->sku)) !== '') {
                    $bySku[$key][] = $p->id;
                }

                // Артикул — только у товаров без GUID: товар с GUID 1С находит по GUID
                if ($byArticle && ($p->guid === null || $p->guid === '') && ($key = self::key($p->article)) !== '') {
                    $this->byArticle[$key][] = $p->id;
                }

                $state[$p->id] = [
                    'guid' => $p->guid,
                    'deleted' => $p->deleted_at !== null,
                    'price_synced_at' => $p->price_synced_at ? Carbon::parse($p->price_synced_at) : null,
                    'stock_synced_at' => $p->stock_synced_at ? Carbon::parse($p->stock_synced_at) : null,
                    'values' => [
                        'price' => $p->price === null ? null : number_format((float) $p->price, 2, '.', ''),
                        'stock_1c' => (int) $p->stock_1c,
                        'on_order' => (bool) $p->on_order,
                    ],
                ];
            });

        $this->guids = array_flip($byGuid);

        return [$byGuid, $bySku, $state];
    }

    /**
     * Товар строки: guid → точное совпадение sku (код сайта; без учёта регистра и пробелов
     * по краям, внутренние пробелы значимы) среди товаров без другого GUID → артикул
     * (только если включено SHOP_1C_MATCH_BY_ARTICLE, только товары без GUID).
     * Несколько совпадений — ошибка строки.
     *
     * @param  list<string>  $errors
     */
    private function find(string $guid, string $sku, array $byGuid, array $bySku, array &$errors): ?int
    {
        if ($guid === '' && $sku === '') {
            $errors[] = 'Нет GUID и SKU';

            return null;
        }

        if ($guid !== '' && isset($byGuid[strtolower($guid)])) {
            return $byGuid[strtolower($guid)];
        }

        $key = self::key($sku);
        $ids = $key !== '' ? array_values(array_unique($bySku[$key] ?? [])) : [];
        // Товар с другим GUID — это другой товар 1С, а не пара по SKU
        $free = array_values(array_filter($ids, fn ($id) => ! isset($this->guids[$id])));

        if (count($free) === 1) {
            return $free[0];
        }

        if (count($free) > 1) {
            $errors[] = "Товар с таким GUID не найден, SKU «{$sku}» есть у нескольких товаров сайта";

            return null;
        }

        if ($ids === [] && $key !== '' && isset($this->byArticle[$key])) {
            $byArticle = array_values(array_unique($this->byArticle[$key]));

            if (count($byArticle) === 1) {
                return $byArticle[0];
            }

            $errors[] = "Товар с таким GUID не найден, артикул «{$sku}» есть у нескольких товаров сайта";

            return null;
        }

        $errors[] = match (true) {
            $ids !== [] => "Товар с таким GUID не найден, SKU «{$sku}» — у товара с другим GUID",
            $guid === '' => "Товар с SKU «{$sku}» не найден",
            default => 'Товар с таким GUID не найден',
        };

        return null;
    }

    /** Ключ сравнения SKU / артикула: без учёта регистра и пробелов по краям */
    private static function key(?string $value): string
    {
        // Пробелы по краям, в том числе неразрывные; trim() побайтный — портил бы кириллицу
        return mb_strtoupper((string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $value));
    }

    /**
     * Цена с 2 знаками («832.3» → «832.30») или null — «Цена уточняется».
     *
     * @param  list<string>  $errors
     */
    private function price(string $value, array &$errors): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $errors[] = match (true) {
            str_contains($value, ',') => "Цена «{$value}»: ожидается точка",
            str_starts_with($value, '-') => "Цена «{$value}»: отрицательная",
            default => "Цена «{$value}»: ожидается число до 2 знаков после точки, без пробелов",
        };

        return null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function stock(string $value, array &$errors): int
    {
        if (preg_match('/^\d{1,9}$/', $value)) {
            return (int) $value;
        }

        $errors[] = match (true) {
            $value === '' => 'Нет остатка',
            str_starts_with($value, '-') => "Остаток «{$value}»: отрицательный",
            default => "Остаток «{$value}»: ожидается целое число ≥ 0",
        };

        return 0;
    }

    /**
     * mode=full: остаток 0 у товаров с GUID, которых нет в файле. Предохранитель — до записи.
     *
     * @param  array<int, true>  $present
     */
    private function zeroMissing(array $state, array $present, Carbon $snapshot, PricesStockPlan $plan): void
    {
        $known = array_keys(array_filter($state, fn ($p) => $p['guid'] !== null && $p['guid'] !== '' && ! $p['deleted']));
        $missing = array_values(array_diff($known, array_keys($present)));

        if ($known === [] || $missing === []) {
            return;
        }

        $share = count($missing) / count($known);
        $limit = ExchangeSettings::fullMissingShare();

        if ($share > $limit) {
            throw new ExchangeJobFailedException(sprintf(
                'mode=full: в файле нет %d из %d товаров с GUID (%s %% при пороге %s %%) — файл похож на неполный, данные сайта не изменены',
                count($missing), count($known), round($share * 100, 1), round($limit * 100)
            ));
        }

        foreach ($missing as $id) {
            if ($state[$id]['stock_synced_at'] === null || $snapshot->gte($state[$id]['stock_synced_at'])) {
                $plan->changes[$id] = ['stock_1c' => 0, 'stock_synced_at' => $snapshot->format('Y-m-d H:i:s')];
                $plan->zeroed[] = $id;
            }
        }

        $plan->result->message = 'mode=full: остаток 0 у '.count($plan->zeroed).' товаров с GUID, которых нет в файле';
    }
}
