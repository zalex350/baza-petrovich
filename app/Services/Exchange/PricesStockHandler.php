<?php

namespace App\Services\Exchange;

use App\Exceptions\ExchangeJobFailedException;
use App\Models\ExchangeJob;
use App\Services\StockReserve;
use App\Support\BulkUpdate;
use App\Support\ExchangeSettings;
use App\Support\ExchangeTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\CSV\Options;
use OpenSpout\Reader\CSV\Reader;

/**
 * Поток 2 — цены и остатки 1С → сайт (docs/1C_EXCHANGE_SPEC.md, раздел 5).
 *
 * CSV (разделитель «;», колонки по заголовку в любом порядке) читает openspout. Два прохода:
 *  1. разбор и проверка всех строк в памяти: товар — по guid, иначе по sku (артикул или sku
 *     сайта без учёта регистра и пробелов, только у товара без другого GUID); цена (точка,
 *     до 2 знаков, ≥ 0, пусто — «Цена уточняется»), остаток (целое ≥ 0), stock_status,
 *     updated_at (ExchangeTime::parse, любое смещение). Строка старше уже применённого
 *     (отдельно для цены — price_synced_at и остатка — stock_synced_at) — rows_skipped;
 *  2. mode=full: товары с GUID, которых нет в файле, — stock_1c = 0; предохранитель:
 *     не хватает больше доли из настроек (10 %) — исключение до записи, задание failed;
 *  3. запись пачками по 500 товаров в транзакции: BulkUpdate (stock_1c, price, on_order,
 *     *_synced_at) и пересчёт доступного остатка StockReserve::recalculate.
 * stock_status: on_order — «под заказ», in_stock / none — нет; колонки нет или пусто —
 * признак не меняется.
 */
class PricesStockHandler implements ExchangeHandler
{
    private const BATCH = 500;

    private const STATUSES = ['in_stock' => false, 'on_order' => true, 'none' => false];

    /** @var array<int, string> id товара => его guid (в нижнем регистре) */
    private array $guids = [];

    public function __construct(private readonly StockReserve $reserve) {}

    public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void
    {
        [$byGuid, $bySku, $state] = $this->products();

        $changes = [];
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
            $status = trim((string) ($row['stock_status'] ?? ''));

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

            if ($applyPrice) {
                $changes[$id]['price'] = $price;
                $changes[$id]['price_synced_at'] = $updatedAt->format('Y-m-d H:i:s');
                $current['price_synced_at'] = $updatedAt;
            }

            if ($applyStock) {
                $changes[$id]['stock_1c'] = $stock;
                $changes[$id]['stock_synced_at'] = $updatedAt->format('Y-m-d H:i:s');
                $current['stock_synced_at'] = $updatedAt;

                if ($status !== '') {
                    $changes[$id]['on_order'] = (int) self::STATUSES[$status];
                }
            }

            unset($current);
            $result->ok++;
        }

        if ($job->mode === 'full') {
            $this->zeroMissing($state, $present, $snapshot ?? now()->startOfSecond(), $changes, $result);
        }

        foreach (array_chunk($changes, self::BATCH, true) as $chunk) {
            DB::transaction(function () use ($chunk) {
                BulkUpdate::run('products', $chunk);
                $this->reserve->recalculate(array_keys($chunk));
            });
        }
    }

    /**
     * Строки CSV: номер строки файла => [колонка => значение]; пустые строки пропускаются.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function rows(string $path): \Generator
    {
        $options = new Options;
        $options->FIELD_DELIMITER = ';';
        $options->ENCODING = 'UTF-8';
        // Пустые строки — тоже строки файла: номер строки в ошибке совпадает с номером в файле
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $reader = new Reader($options);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(fn (Cell $cell) => trim((string) $cell->getValue()), $row->getCells());

                    if ($columns === null) {
                        $columns = array_map(fn ($name) => strtolower(trim($name, " \t\"\u{FEFF}")), $cells);

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $values = [];
                    foreach ($columns as $i => $name) {
                        $values[$name] = $cells[$i] ?? '';
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
     * Товары сайта (и удалённые мягко — могут вернуть): по guid, по ключу sku / article,
     * текущие отметки обмена.
     *
     * @return array{0: array<string, int>, 1: array<string, list<int>>, 2: array<int, array{guid: ?string, deleted: bool, price_synced_at: ?Carbon, stock_synced_at: ?Carbon}>}
     */
    private function products(): array
    {
        $byGuid = [];
        $bySku = [];
        $state = [];

        DB::table('products')->orderBy('id')
            ->select(['id', 'guid', 'sku', 'article', 'price_synced_at', 'stock_synced_at', 'deleted_at'])
            ->lazy(2000)
            ->each(function ($p) use (&$byGuid, &$bySku, &$state) {
                if ($p->guid !== null && $p->guid !== '') {
                    $byGuid[strtolower($p->guid)] = $p->id;
                }

                foreach (array_unique(array_filter([self::skuKey($p->sku), self::skuKey($p->article)])) as $key) {
                    $bySku[$key][] = $p->id;
                }

                $state[$p->id] = [
                    'guid' => $p->guid,
                    'deleted' => $p->deleted_at !== null,
                    'price_synced_at' => $p->price_synced_at ? Carbon::parse($p->price_synced_at) : null,
                    'stock_synced_at' => $p->stock_synced_at ? Carbon::parse($p->stock_synced_at) : null,
                ];
            });

        $this->guids = array_flip($byGuid);

        return [$byGuid, $bySku, $state];
    }

    /**
     * @param  list<string>  $errors
     */
    private function find(string $guid, string $sku, array $byGuid, array $bySku, array &$errors): ?int
    {
        if ($guid === '' && $sku === '') {
            $errors[] = 'Нет GUID и артикула';

            return null;
        }

        if ($guid !== '' && isset($byGuid[strtolower($guid)])) {
            return $byGuid[strtolower($guid)];
        }

        $ids = $sku !== '' ? array_values(array_unique($bySku[self::skuKey($sku)] ?? [])) : [];
        // Товар с другим GUID — это другой товар 1С, а не пара по артикулу
        $free = array_values(array_filter($ids, fn ($id) => ! isset($this->guids[$id])));

        if (count($free) === 1) {
            return $free[0];
        }

        $errors[] = match (true) {
            count($free) > 1 => "Товар с таким GUID не найден, артикул «{$sku}» есть у нескольких товаров сайта",
            $ids !== [] => "Товар с таким GUID не найден, артикул «{$sku}» — у товара с другим GUID",
            $guid === '' => "Товар с артикулом «{$sku}» не найден",
            default => 'Товар с таким GUID не найден',
        };

        return null;
    }

    private static function skuKey(?string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\p{Z}]+/u', '', (string) $value));
    }

    /**
     * @param  list<string>  $errors
     */
    private function price(string $value, array &$errors): ?string
    {
        if ($value === '') {
            return null; // «Цена уточняется»
        }

        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
            return $value;
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
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function zeroMissing(array $state, array $present, Carbon $snapshot, array &$changes, ExchangeResult $result): void
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

        $zeroed = 0;

        foreach ($missing as $id) {
            if ($state[$id]['stock_synced_at'] === null || $snapshot->gte($state[$id]['stock_synced_at'])) {
                $changes[$id] = ['stock_1c' => 0, 'stock_synced_at' => $snapshot->format('Y-m-d H:i:s')];
                $zeroed++;
            }
        }

        $result->message = "mode=full: остаток 0 у {$zeroed} товаров с GUID, которых нет в файле";
    }
}
