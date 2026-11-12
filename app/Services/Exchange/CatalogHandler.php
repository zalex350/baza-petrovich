<?php

namespace App\Services\Exchange;

use App\Enums\ExchangeJobType;
use App\Exceptions\ExchangeJobFailedException;
use App\Models\ExchangeJob;
use App\Models\Product;
use App\Services\CatalogMenu;
use App\Support\BulkUpdate;
use App\Support\ExchangeSettings;
use Illuminate\Support\Facades\DB;

/**
 * Поток 1 — номенклатура 1С → сайт (docs/1C_EXCHANGE_SPEC.md, раздел 4).
 *
 * plan() — без записи (он же пробный прогон dry_run=1):
 *  1. разбор строк (ExchangeFile: колонки по заголовку) и проверка: guid, name, article,
 *     active (1/0), vat_rate (число 0–100 или «без НДС»), min_qty / qty_step (целые ≥ 1),
 *     длины колонок products (LENGTHS: длиннее — ошибка строки; name и group_1c — обрезка
 *     с предупреждением);
 *  2. товар: guid (без учёта регистра) → sku, если заполнен (точно, без учёта регистра и
 *     пробелов по краям; только товар без GUID; не найден — ошибка) → «бренд + артикул»
 *     (Product::matchKey, только товары без GUID и не удалённые; несколько — ошибка
 *     «неоднозначное сопоставление»; без бренда — ошибка). Найден не по GUID — товару
 *     присваивается guid;
 *  3. найден — пишутся поля из SHOP_1C_MANAGED_FIELDS (из name, article, brand, unit,
 *     vat_rate, min_qty, qty_step; price и stock — поток 2) и всегда guid, barcode, group_1c;
 *     код сайта (sku) и display_name не меняются. active = 0 — товар скрывается
 *     (is_active = 0, hidden_by_1c = 1); active = 1 показывает только скрытый так же,
 *     скрытый вручную в админке остаётся скрытым;
 *  4. не найден (sku пуст) — новый товар черновиком (is_published = 0) без категории,
 *     код сайта «БРЕНД-артикул», занят — суффикс -2, -3 … и предупреждение; коды — в created;
 *  5. mode=full: товары с GUID, которых нет в файле, не скрываются и не удаляются — только
 *     список (missing) в отчёте задания; предохранитель — нет больше доли из настроек (10 %)
 *     → ExchangeJobFailedException (в пробном прогоне — отметка в сообщении).
 * Пробный прогон (dry_run=1) — тот же план без записи, в отчёте ещё и все товары сайта без пары
 * в файле (unmatched).
 * handle() — план и запись пачками по 500 в транзакции (BulkUpdate, создание через модель).
 */
class CatalogHandler implements ExchangeHandler
{
    private const BATCH = 500;

    /** Поля строки, которые обмен пишет в найденный товар, если они в SHOP_1C_MANAGED_FIELDS */
    private const MANAGEABLE = ['name', 'article', 'brand', 'unit', 'vat_rate', 'min_qty', 'qty_step'];

    /** Служебные поля 1С — пишутся всегда */
    private const ALWAYS = ['barcode', 'group_1c'];

    /** Длины колонок products в символах; длиннее — ошибка строки (кроме TRUNCATE) */
    public const LENGTHS = [
        'guid' => 64, 'sku' => 255, 'article' => 255, 'brand' => 255, 'name' => 255,
        'unit' => 20, 'barcode' => 64, 'group_1c' => 255,
    ];

    /** Длиннее колонки — обрезка с предупреждением: справочные поля, не ключи */
    private const TRUNCATE = ['name', 'group_1c'];

    /** @var array<int, array<string, mixed>> id => текущие значения товара */
    private array $products = [];

    /** @var array<string, int> guid в нижнем регистре => id */
    private array $byGuid = [];

    /** @var array<string, list<int>> SKU (ключ сравнения) => товары, и удалённые мягко */
    private array $bySku = [];

    /** @var array<string, list<int>> match_key => товары без GUID, не удалённые */
    private array $byKey = [];

    /** @var array<string, true> занятые коды сайта (ключ сравнения), с созданными в этом файле */
    private array $takenSku = [];

    public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void
    {
        $plan = $this->plan($path, $job->mode, $result, (bool) $job->dry_run);

        if (! $job->dry_run) {
            $this->write($plan);
        }
    }

    public function plan(string $path, ?string $mode, ?ExchangeResult $result = null, bool $dryRun = false): CatalogPlan
    {
        $plan = new CatalogPlan($result ?? new ExchangeResult);
        $result = $plan->result;

        $this->load();

        $managed = array_values(array_intersect(self::MANAGEABLE, (array) config('shop.exchange_1c.managed_fields', [])));
        $now = now()->format('Y-m-d H:i:s');
        $known = array_keys(array_filter($this->products, fn ($p) => $p['guid'] !== null && ! $p['deleted']));
        $seenGuid = [];
        $seenProduct = [];
        $present = [];

        foreach (ExchangeFile::rows($path, ExchangeJobType::Catalog) as $line => $row) {
            $result->total++;

            $errors = [];
            $warnings = [];
            $guid = self::clean($row['guid'] ?? '');
            $sku = self::clean($row['sku'] ?? '');
            $values = $this->values($row, $guid, $sku, $errors, $warnings);
            $lower = mb_strtolower($guid);

            [$id, $how] = [null, null];

            if ($guid === '') {
                $errors[] = 'Нет GUID';
            } elseif (isset($seenGuid[$lower])) {
                $errors[] = "GUID повторяется в файле (строка {$seenGuid[$lower]})";
                $id = $this->byGuid[$lower] ?? null;
            } else {
                [$id, $how] = $this->find($guid, $sku, $values, $errors, $warnings);

                if ($id !== null && isset($seenProduct[$id])) {
                    $errors[] = 'Товар сайта '.$this->products[$id]['sku']." уже сопоставлен строке {$seenProduct[$id]}";
                }
            }

            if ($guid !== '') {
                $seenGuid[$lower] ??= $line;
            }

            if ($id !== null) {
                // Строка с ошибкой — тоже «товар в файле» (mode=full)
                $present[$id] = true;
                $seenProduct[$id] ??= $line;
            }

            $code = null;

            if ($errors === [] && $how === 'new') {
                $code = $this->newSku($values['brand'], $values['article'], $errors, $warnings);
            }

            if ($errors !== []) {
                $result->error($line, $guid ?: null, $sku ?: null, implode('; ', $errors));

                continue;
            }

            $label = $code ?? ($id !== null ? $this->products[$id]['sku'] : null) ?? ($sku ?: null);

            foreach ($warnings as $warning) {
                $result->warning($line, $guid, $label, $warning);
            }

            if ($how === 'new') {
                $this->planCreate($plan, $line, $guid, $code, $values, $now);
            } else {
                $this->planUpdate($plan, $line, $id, $how, $guid, $values, $managed, $now);
            }

            $result->ok++;
        }

        if ($mode === 'full') {
            $this->missing($known, $present, $plan, $dryRun);
        }

        // Пробный прогон — ещё и все товары сайта без пары в файле (правило 10, первичное сопоставление)
        if ($dryRun) {
            foreach ($this->products as $id => $product) {
                if (! $product['deleted'] && ! isset($present[$id])) {
                    $result->unmatched[] = ['guid' => $product['guid'], 'sku' => $product['sku']];
                }
            }
        }

        $result->summary = $plan->summary();
        $result->message = $this->message($plan, $mode, $dryRun);

        return $plan;
    }

    /**
     * Запись плана: изменения — BulkUpdate, новые товары — через модель (match_key, остатки).
     */
    private function write(CatalogPlan $plan): void
    {
        foreach (array_chunk($plan->updates, self::BATCH, true) as $chunk) {
            DB::transaction(fn () => BulkUpdate::run('products', $chunk));
        }

        foreach (array_chunk($plan->creates, self::BATCH, true) as $chunk) {
            DB::transaction(function () use ($chunk) {
                foreach ($chunk as $attributes) {
                    (new Product)->forceFill($attributes)->save();
                }
            });
        }

        // BulkUpdate идёт мимо модели — меню каталога (видимость товаров) сбрасывается вручную
        if ($plan->hidden !== [] || $plan->shown !== []) {
            CatalogMenu::flush();
        }
    }

    /**
     * Товары сайта: текущие значения полей обмена и индексы поиска.
     */
    private function load(): void
    {
        $this->products = $this->byGuid = $this->bySku = $this->byKey = $this->takenSku = [];

        DB::table('products')
            ->select(['id', 'guid', 'sku', 'article', 'brand', 'name', 'unit', 'vat_rate', 'min_qty', 'qty_step',
                'barcode', 'group_1c', 'is_active', 'hidden_by_1c', 'match_key', 'deleted_at'])
            ->lazyById(2000)
            ->each(function ($p) {
                $guid = $p->guid !== null && $p->guid !== '' ? $p->guid : null;

                $this->products[$p->id] = [
                    'guid' => $guid,
                    'deleted' => $p->deleted_at !== null,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'article' => $p->article,
                    'brand' => $p->brand,
                    'unit' => $p->unit,
                    'vat_rate' => self::vatValue($p->vat_rate),
                    'min_qty' => (int) $p->min_qty,
                    'qty_step' => (int) $p->qty_step,
                    'barcode' => $p->barcode,
                    'group_1c' => $p->group_1c,
                    'is_active' => (bool) $p->is_active,
                    'hidden_by_1c' => (bool) $p->hidden_by_1c,
                    'match_key' => $p->match_key,
                ];

                if ($guid !== null) {
                    $this->byGuid[mb_strtolower($guid)] = $p->id;
                }

                if (($key = self::key($p->sku)) !== '') {
                    $this->bySku[$key][] = $p->id;
                    $this->takenSku[$key] = true;
                }

                if ($guid === null && $p->deleted_at === null && $p->match_key !== null) {
                    $this->byKey[$p->match_key][] = $p->id;
                }
            });
    }

    /**
     * Значения строки, приведённые к колонкам products; ошибки и предупреждения — в $errors / $warnings.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function values(array $row, string $guid, string $sku, array &$errors, array &$warnings): array
    {
        $optional = fn (string $column) => ($value = self::clean($row[$column] ?? '')) === '' ? null : $value;

        $values = [
            'name' => self::clean($row['name'] ?? ''),
            'article' => self::clean($row['article'] ?? ''),
            'brand' => $optional('brand'),
            'unit' => $optional('unit') ?? 'шт',
            'vat_rate' => $this->vat(self::clean($row['vat_rate'] ?? ''), $errors),
            'barcode' => $optional('barcode'),
            'group_1c' => $optional('group_1c'),
            'active' => $this->active(self::clean($row['active'] ?? ''), $errors),
        ];

        if ($values['name'] === '') {
            $errors[] = 'Нет наименования (name)';
        }

        if ($values['article'] === '') {
            $errors[] = 'Нет артикула (article)';
        }

        // Колонки нет — поле не меняется; есть, но пусто — 1 (раздел 4)
        foreach (['min_qty', 'qty_step'] as $column) {
            if (array_key_exists($column, $row)) {
                $values[$column] = $this->quantity($column, self::clean($row[$column]), $errors);
            }
        }

        foreach (self::LENGTHS as $column => $length) {
            $value = match ($column) {
                'guid' => $guid,
                'sku' => $sku,
                default => $values[$column],
            };

            if ($value === null || mb_strlen($value) <= $length) {
                continue;
            }

            if (in_array($column, self::TRUNCATE, true)) {
                $values[$column] = mb_substr($value, 0, $length);
                $warnings[] = "Поле {$column} длиннее {$length} символов — обрезано";
            } else {
                $errors[] = "Поле {$column} длиннее {$length} символов";
            }
        }

        return $values;
    }

    /**
     * Товар строки: [id, как найден: guid | sku | key] или [null, 'new'] — создать;
     * [null, null] — ошибка (в $errors).
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{0: ?int, 1: ?string}
     */
    private function find(string $guid, string $sku, array $values, array &$errors, array &$warnings): array
    {
        if (isset($this->byGuid[mb_strtolower($guid)])) {
            $id = $this->byGuid[mb_strtolower($guid)];
            $siteSku = (string) $this->products[$id]['sku'];

            if ($sku !== '' && self::key($sku) !== self::key($siteSku)) {
                $warnings[] = "SKU «{$sku}» отличается от кода товара на сайте «{$siteSku}» (товар найден по GUID, код сайта не меняется)";
            }

            return [$id, 'guid'];
        }

        if ($sku !== '') {
            $ids = array_values(array_unique($this->bySku[self::key($sku)] ?? []));
            // Товар с другим GUID — другая позиция 1С, а не пара по SKU
            $free = array_values(array_filter($ids, fn ($id) => $this->products[$id]['guid'] === null));

            if (count($free) === 1) {
                return [$free[0], 'sku'];
            }

            $errors[] = match (true) {
                count($free) > 1 => "SKU «{$sku}» есть у нескольких товаров сайта без GUID",
                $ids !== [] => "SKU «{$sku}» — у товара сайта с другим GUID",
                default => "Товар с SKU «{$sku}» на сайте не найден: заполненный sku — код существующего товара сайта; у новой позиции оставьте sku пустым",
            };

            return [null, null];
        }

        if ($values['article'] === '') {
            return [null, null];
        }

        if ($values['brand'] === null) {
            $errors[] = 'Нет бренда (brand): без sku товар сопоставляется и создаётся по «бренд + артикул»';

            return [null, null];
        }

        $key = Product::matchKey($values['brand'], $values['article']);

        if ($key === null) {
            $errors[] = "Артикул «{$values['article']}» не годится для сопоставления";

            return [null, null];
        }

        $ids = array_values(array_filter(
            array_unique($this->byKey[$key] ?? []),
            fn ($id) => $this->products[$id]['guid'] === null,
        ));

        if (count($ids) > 1) {
            $skus = implode(', ', array_map(fn ($id) => (string) $this->products[$id]['sku'], $ids));
            $errors[] = "Неоднозначное сопоставление: «{$values['brand']}» + «{$values['article']}» — у товаров сайта {$skus}; укажите sku нужного товара";

            return [null, null];
        }

        return $ids !== [] ? [$ids[0], 'key'] : [null, 'new'];
    }

    /**
     * Код сайта нового товара: «БРЕНД-артикул» (бренд в верхнем регистре, артикул как есть);
     * занят (без учёта регистра и пробелов по краям) — суффикс -2, -3 … и предупреждение.
     *
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function newSku(string $brand, string $article, array &$errors, array &$warnings): ?string
    {
        $base = mb_strtoupper($brand).'-'.$article;
        $code = $base;

        for ($n = 2; isset($this->takenSku[self::key($code)]); $n++) {
            $code = "{$base}-{$n}";
        }

        if (mb_strlen($code) > self::LENGTHS['sku']) {
            $errors[] = "Код сайта «{$code}» длиннее ".self::LENGTHS['sku'].' символов';

            return null;
        }

        if ($code !== $base) {
            $warnings[] = "Код {$base} занят, присвоен {$code}";
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function planCreate(CatalogPlan $plan, int $line, string $guid, string $code, array $values, string $now): void
    {
        $this->takenSku[self::key($code)] = true;

        $plan->creates[$line] = [
            'guid' => $guid,
            'sku' => $code,
            'name' => $values['name'],
            'article' => $values['article'],
            'brand' => $values['brand'],
            'unit' => $values['unit'],
            'vat_rate' => $values['vat_rate'],
            'min_qty' => $values['min_qty'] ?? 1,
            'qty_step' => $values['qty_step'] ?? 1,
            'barcode' => $values['barcode'],
            'group_1c' => $values['group_1c'],
            // Черновик без категории: на витрину — после категории и публикации в админке
            'category_id' => null,
            'is_published' => false,
            'is_active' => $values['active'],
            'hidden_by_1c' => ! $values['active'],
            'price' => null,
            'stock' => 0,
            'synced_at' => $now,
        ];

        $plan->result->created[] = ['line' => $line, 'guid' => $guid, 'sku' => $code];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $managed
     */
    private function planUpdate(CatalogPlan $plan, int $line, int $id, string $how, string $guid, array $values, array $managed, string $now): void
    {
        $product = &$this->products[$id];
        $changes = [];

        foreach ([...$managed, ...self::ALWAYS] as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== $product[$field]) {
                $changes[$field] = $values[$field];
            }
        }

        if ($how !== 'guid') {
            // GUID товару присваивает только этот поток (раздел 4, правило 1)
            $changes['guid'] = $guid;
            $product['guid'] = $guid;
            $this->byGuid[mb_strtolower($guid)] = $id;
        }

        if (! $values['active'] && $product['is_active']) {
            $changes['is_active'] = false;
            $changes['hidden_by_1c'] = true;
            $plan->hidden[] = $id;
        } elseif ($values['active'] && $product['hidden_by_1c']) {
            $changes['is_active'] = true;
            $changes['hidden_by_1c'] = false;
            $plan->shown[] = $id;
        }

        if (array_key_exists('brand', $changes) || array_key_exists('article', $changes)) {
            $key = Product::matchKey($changes['brand'] ?? $product['brand'], $changes['article'] ?? $product['article']);

            if ($key !== $product['match_key']) {
                $changes['match_key'] = $key;
            }
        }

        $shown = [];

        foreach ($changes as $field => $value) {
            if (! in_array($field, ['hidden_by_1c', 'match_key'], true)) {
                $plan->countField($field);
                $shown[$field] = [$product[$field], $value];
            }

            $product[$field] = $value;
        }

        // Для предпросмотра: привязка GUID и изменения полей (без строк «ничего не меняется»)
        if ($shown !== []) {
            $plan->matches[] = ['line' => $line, 'guid' => $guid, 'id' => $id, 'how' => $how, 'changes' => $shown];
        }

        match ($how) {
            'guid' => $plan->byGuid++,
            'sku' => $plan->bySku++,
            default => $plan->byKey++,
        };

        if ($changes !== []) {
            $plan->updated++;
            $changes['updated_at'] = $now;
        } else {
            $plan->unchanged++;
        }

        $changes['synced_at'] = $now;
        $plan->updates[$id] = $changes;
        unset($product);
    }

    /**
     * mode=full: товары с GUID, которых нет в файле, — только список в отчёте (не скрываются).
     * Предохранитель: нет больше доли из настроек — failed, данные не меняются.
     *
     * @param  list<int>  $known  товары с GUID до загрузки (не удалённые)
     * @param  array<int, true>  $present
     */
    private function missing(array $known, array $present, CatalogPlan $plan, bool $dryRun): void
    {
        $missing = array_values(array_diff($known, array_keys($present)));

        if ($known === [] || $missing === []) {
            return;
        }

        $share = count($missing) / count($known);
        $limit = ExchangeSettings::fullMissingShare();

        if ($share > $limit) {
            $reason = sprintf('в файле нет %d из %d товаров с GUID (%s %% при пороге %s %%)',
                count($missing), count($known), round($share * 100, 1), round($limit * 100));

            if (! $dryRun) {
                throw new ExchangeJobFailedException("mode=full: {$reason} — файл похож на неполный, данные сайта не изменены");
            }

            $plan->guard = $reason;
        }

        foreach ($missing as $id) {
            $plan->result->missing[] = ['guid' => $this->products[$id]['guid'], 'sku' => $this->products[$id]['sku']];
        }
    }

    private function message(CatalogPlan $plan, ?string $mode, bool $dryRun): string
    {
        $parts = [sprintf(
            'Сопоставлено по GUID %d, по SKU %d, по бренду и артикулу %d; создано %d; изменено %d, без изменений %d; скрыто %d, показано %d',
            $plan->byGuid, $plan->bySku, $plan->byKey, count($plan->creates), $plan->updated, $plan->unchanged,
            count($plan->hidden), count($plan->shown),
        )];

        if ($mode === 'full' && $plan->result->missing !== []) {
            $parts[] = 'mode=full: '.count($plan->result->missing).' товаров с GUID нет в файле — не скрыты, список в отчёте задания';
        }

        if ($plan->guard !== null) {
            $parts[] = 'предохранитель mode=full сработал бы: '.$plan->guard.' — без dry_run задание завершится failed';
        }

        return ($dryRun ? 'Пробный прогон, данные сайта не изменены. ' : '').implode('. ', $parts);
    }

    /**
     * @param  list<string>  $errors
     */
    private function vat(string $value, array &$errors): ?string
    {
        $number = rtrim($value, '% ');

        if (mb_strtolower(preg_replace('/\s+/u', ' ', $value)) === 'без ндс') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $number) && (float) $number <= 100) {
            return self::vatValue($number);
        }

        $errors[] = match (true) {
            $value === '' => 'Нет ставки НДС (vat_rate)',
            str_contains($value, ',') => "Ставка НДС «{$value}»: ожидается точка",
            default => "Ставка НДС «{$value}»: ожидается 20, 10, 0 или «без НДС»",
        };

        return null;
    }

    /**
     * @param  list<string>  $errors
     */
    private function active(string $value, array &$errors): bool
    {
        return match (mb_strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => (function () use ($value, &$errors) {
                $errors[] = $value === '' ? 'Нет признака active' : "active «{$value}»: ожидается 1 или 0";

                return true;
            })(),
        };
    }

    /**
     * @param  list<string>  $errors
     */
    private function quantity(string $column, string $value, array &$errors): int
    {
        if ($value === '') {
            return 1;
        }

        if (preg_match('/^\d{1,9}$/', $value) && (int) $value >= 1) {
            return (int) $value;
        }

        $errors[] = "{$column} «{$value}»: ожидается целое число ≥ 1";

        return 1;
    }

    /** Ставка как в БД (decimal 5,2) или null — «Без НДС» */
    private static function vatValue(mixed $rate): ?string
    {
        return $rate === null || $rate === '' ? null : number_format((float) $rate, 2, '.', '');
    }

    /** Значение ячейки без пробелов по краям, в том числе неразрывных (trim() побайтный — портил бы кириллицу) */
    private static function clean(mixed $value): string
    {
        return (string) preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', (string) $value);
    }

    /** Ключ сравнения SKU: без учёта регистра и пробелов по краям, внутренние пробелы значимы */
    private static function key(?string $value): string
    {
        return mb_strtoupper(self::clean($value));
    }
}
