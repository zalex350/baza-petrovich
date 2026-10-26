<?php

namespace App\Services\CatalogImport;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Support\CategoryName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Строит план импорта catalog.db (docs/CATALOG_ANALYSIS.md, «Решения»): только чтение —
 * catalog.db открывается в режиме mode=ro, наша БД — только SELECT.
 * Ключ сопоставления — products.sku = catalog.sku (без учёта регистра).
 */
class CatalogImportPlanner
{
    /** @var Collection<int, Category> */
    private Collection $categories;

    public function __construct(
        private readonly CatalogMapping $map,
        private readonly ?ColumnLimits $limits = null,
    ) {}

    public function plan(string $path): CatalogImportPlan
    {
        $plan = new CatalogImportPlan;
        $rows = $this->readCatalog($path);
        $plan->catalogRows = count($rows);

        $this->categories = Category::all()->keyBy('id');

        $ours = Product::withTrashed()
            ->get(['id', 'sku', 'name', 'display_name', 'brand', 'category_id', 'description', 'description_generated', 'is_new', 'is_published', 'video_url', 'deleted_at'])
            ->keyBy(fn (Product $p) => mb_strtoupper(trim($p->sku)));

        $currentValues = $this->currentAttributeValues();
        $spellings = $this->spellings($rows);

        $skipSkus = collect($this->map->duplicates())->values()->merge($this->map->testProducts())
            ->map(fn ($sku) => mb_strtoupper($sku))->flip();

        $processed = 0;      // существующих товаров каталога, проверенных на изменения
        $targets = [];       // pathKey → path
        $moves = [];         // product id → [from category id, to pathKey]
        $attrUse = [];       // атрибут → товаров
        $stats = ['brand' => [], 'category_moves' => [], 'is_new' => 0, 'description_filled' => 0,
            'description_flagged' => 0, 'attributes_products' => 0, 'attributes_values' => 0];

        foreach ($rows as $row) {
            $skuKey = mb_strtoupper(trim($row['sku']));

            if (isset($skipSkus[$skuKey])) {
                continue;
            }

            $our = $ours[$skuKey] ?? null;
            $target = $this->targetFor($row, $plan);

            if ($target['path']) {
                $targets[CatalogImportPlan::pathKey($target['path'])] = $target['path'];
            }

            $set = $this->attributeSet($row, $spellings, $plan);

            foreach ($set as $name => $_) {
                $attrUse[$name] = ($attrUse[$name] ?? 0) + 1;
            }

            if (! $our) {
                $plan->newProducts[] = [
                    'sku' => $row['sku'], 'name' => $row['name'] ?: trim(($row['brand'] ?? '').' '.$row['article']),
                    'brand' => $this->map->brand($row['brand']), 'category' => $target['path'] ? CatalogImportPlan::pathKey($target['path']) : null,
                    'is_new' => $target['novelty'], 'description' => $row['description'],
                    'description_generated' => (bool) $row['description_generated'], 'attributes' => $set,
                    'video_url' => $this->videoUrl($row),
                ];

                continue;
            }

            if ($our->trashed()) {
                continue;
            }

            $change = [];

            $brand = $this->map->brand($row['brand']);
            if ($brand !== null && $brand !== $our->brand) {
                $change['brand'] = [$our->brand, $brand];
                $stats['brand']["{$our->brand} → {$brand}"] = ($stats['brand']["{$our->brand} → {$brand}"] ?? 0) + 1;
            }

            $processed++;

            if ($target['path']) {
                $moves[$our->id] = [$our->category_id, CatalogImportPlan::pathKey($target['path']), $our->sku];
            }

            $video = $this->videoUrl($row);
            if ($video !== null && $video !== $our->video_url) {
                $change['video_url'] = [$our->video_url, $video];
            }

            // Без названия и без категории — показывать нечего: снять с публикации
            if (trim((string) $row['name']) === '' && $target['path'] === [$this->map->uncategorized()] && $our->is_published) {
                $change['is_published'] = [true, false];
            }

            if ($target['novelty'] && ! $our->is_new) {
                $change['is_new'] = [false, true];
                $stats['is_new']++;
            }

            // Описание: пустое у нас — из каталога; совпадает — переносится только признак «сгенерировано»
            $desc = trim((string) $row['description']);
            $flag = (bool) $row['description_generated'];
            if ($desc !== '' && trim((string) $our->description) === '') {
                $change['description'] = [null, $desc];
                $stats['description_filled']++;
            }
            if ($desc !== '' && (trim((string) $our->description) === '' || trim((string) $our->description) === $desc) && $flag !== (bool) $our->description_generated) {
                $change['description_generated'] = [(bool) $our->description_generated, $flag];
                $stats['description_flagged'] += $flag ? 1 : 0;
            }

            // Каталог обновляет только свои характеристики; добавленные в админке (и перенесённые
            // из дублей) остаются — после значений каталога, в прежнем порядке
            $current = $currentValues[$our->id] ?? [];
            $set += array_diff_key($current, $set);

            if ($set !== $current) {
                $change['attributes'] = $set;
                $stats['attributes_products']++;
                $stats['attributes_values'] += count($set);
            }

            if ($change) {
                $plan->products[$our->id] = ['sku' => $our->sku] + $change;
            }
        }

        // Очистка — раньше категорий: удаляемые совсем товары освобождают свои категории
        $this->planCleanup($plan, $ours, $rows, $spellings, $currentValues);
        $this->planCategories($plan, $targets, $moves, $stats);

        // Характеристики, которые придут из дублей, — тоже в справочник (и в фильтры)
        foreach ($plan->duplicates as $dup) {
            foreach (array_keys($dup['attributes']) as $name) {
                $attrUse[$name] = ($attrUse[$name] ?? 0) + 1;
            }
        }

        $this->planAttributes($plan, $attrUse);
        $this->planFilters($plan);

        $plan->unchanged = $processed - count($plan->products);
        $this->checkLimits($plan);
        arsort($stats['brand']);
        arsort($stats['category_moves']);
        arsort($plan->droppedKeys);
        $plan->stats = $stats;

        return $plan;
    }

    // --- Чтение ---

    /**
     * @return list<array<string, mixed>>
     */
    private function readCatalog(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Файл не найден: {$path}");
        }

        $pdo = new PDO('sqlite:file:'.$path.'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return $pdo->query('SELECT sku, article, brand, series, name, category, description,
                COALESCE(description_generated, 0) AS description_generated, attributes
            FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Текущие характеристики: товар → [название => значение] в порядке показа.
     *
     * @return array<int, array<string, string>>
     */
    private function currentAttributeValues(): array
    {
        $out = [];

        DB::table('product_attribute_values as v')
            ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->orderBy('v.product_id')->orderBy('v.sort_order')->orderBy('v.id')
            ->select('v.product_id', 'a.name', 'v.value')
            ->chunk(5000, function ($chunk) use (&$out) {
                foreach ($chunk as $v) {
                    $out[$v->product_id][$v->name] = (string) $v->value;
                }
            });

        return $out;
    }

    /**
     * Самое частое написание текстовых значений: характеристика → ключ значения → написание.
     *
     * @return array<string, array<string, string>>
     */
    private function spellings(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach ($this->rawAttributes($row) as $name => $raw) {
                if ($this->map->attributeDef($name)['type'] !== 'text') {
                    continue;
                }

                [$value] = $this->map->normalizeValue($name, $raw);
                $counts[$name][CatalogMapping::key($value)][$value] = ($counts[$name][CatalogMapping::key($value)][$value] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counts as $name => $byKey) {
            foreach ($byKey as $key => $variants) {
                arsort($variants);
                // (string): числовое написание («8481») PHP хранит в ключе массива как int
                $out[$name][$key] = (string) array_key_first($variants);
            }
        }

        return $out;
    }

    /**
     * Сырые значения характеристик товара: каноническое название → значение (первый найденный ключ).
     *
     * @return array<string, string>
     */
    private function rawAttributes(array $row, ?CatalogImportPlan $plan = null): array
    {
        $json = json_decode((string) $row['attributes'], true);
        $out = [];

        foreach (is_array($json) ? $json : [] as $key => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value === '' || $this->map->isSkippedKey((string) $key) || $this->map->fieldFor((string) $key)) {
                continue;
            }

            $byUnit = $this->map->attributeByUnit((string) $key, $value);

            if ($byUnit === false) {
                if ($plan) {
                    $label = $key.' (единица не см / мм)';
                    $plan->droppedKeys[$label] = ($plan->droppedKeys[$label] ?? 0) + 1;
                }

                continue;
            }

            $name = $byUnit ?? $this->map->attributeFor((string) $key);

            if ($name === null) {
                if ($plan) {
                    $plan->droppedKeys[(string) $key] = ($plan->droppedKeys[(string) $key] ?? 0) + 1;
                }

                continue;
            }

            $out[$name] ??= $value;
        }

        return $out;
    }

    /**
     * Набор характеристик товара для записи: название → значение, в порядке справочника, «Серия» — последней.
     *
     * @return array<string, string>
     */
    private function attributeSet(array $row, array $spellings, ?CatalogImportPlan $plan): array
    {
        $raw = $this->rawAttributes($row, $plan);
        $set = [];

        foreach ($this->map->attributeNames() as $name) {
            if (! isset($raw[$name])) {
                continue;
            }

            [$value] = $this->map->normalizeValue($name, $raw[$name]);

            if ($this->map->attributeDef($name)['type'] === 'text') {
                $value = $spellings[$name][CatalogMapping::key($value)] ?? $value;
            }

            if ($value !== '') {
                $set[$name] = $value;
            }
        }

        $series = $this->series($row);
        if ($series !== null) {
            $set[$this->map->seriesAttribute()] = $series;
        }

        return $set;
    }

    /**
     * Адрес видео из ключа-поля («Ссылка на видео» → video_url) или null.
     */
    private function videoUrl(array $row): ?string
    {
        $json = json_decode((string) $row['attributes'], true);

        foreach (is_array($json) ? $json : [] as $key => $value) {
            if ($this->map->fieldFor((string) $key) === 'video_url') {
                return CatalogMapping::videoUrl(is_scalar($value) ? (string) $value : null);
            }
        }

        return null;
    }

    /**
     * «Серия»: колонка series каталога; бренд-серия (TIM Thermo); «Производитель» = AquaTIM.
     */
    private function series(array $row): ?string
    {
        $series = trim((string) $row['series']);

        if ($series !== '') {
            return $series;
        }

        $json = json_decode((string) $row['attributes'], true);

        return $this->map->brandSeries($row['brand'])
            ?? $this->map->brandSeries(is_array($json) ? ($json['Производитель'] ?? null) : null);
    }

    /**
     * Куда отнести товар: путь (null — оставить как есть) и признак «Новинка».
     *
     * @return array{path: ?array, novelty: bool}
     */
    private function targetFor(array $row, CatalogImportPlan $plan): array
    {
        $target = $this->rawTargetFor($row, $plan);

        // Названия категорий — с заглавной первой буквы (остальное как есть)
        if ($target['path']) {
            $target['path'] = array_map(CategoryName::capitalize(...), $target['path']);
        }

        return $target;
    }

    /**
     * @return array{path: ?array, novelty: bool}
     */
    private function rawTargetFor(array $row, CatalogImportPlan $plan): array
    {
        $value = trim((string) $row['category']);

        if ($value === '' || $this->map->isNovelty($value)) {
            $novelty = $value !== '';
            $rule = $this->map->ruleFor($row['name'], $row['series']);
            $path = $rule[0] ?? ($novelty ? $this->map->noveltySeriesPath($row['series']) : null);
            $how = $rule[1] ?? ($path ? 'серия «Новинок»: '.$row['series'] : 'не распознано');
            $path ??= [$this->map->uncategorized()];

            // В список для проверки — и пустые, и «Новинки»: раскладка у обоих по правилам
            $plan->uncategorized[] = [$row['sku'], $row['name'] ?: '(нет названия; серия: '.($row['series'] ?: '—').')',
                $novelty ? 'Новинки' : '(пусто)', CatalogImportPlan::pathKey($path), $how];

            return ['path' => $path, 'novelty' => $novelty];
        }

        return ['path' => $this->map->mappedPath($value), 'novelty' => false];
    }

    // --- Категории ---

    private function findCategory(array $path): ?Category
    {
        $parentId = null;
        $found = null;

        foreach ($path as $name) {
            $found = $this->categories->first(fn (Category $c) => $c->parent_id == $parentId && CatalogMapping::key($c->name) === CatalogMapping::key($name));

            if (! $found) {
                return null;
            }

            $parentId = $found->id;
        }

        return $found;
    }

    private function categoryLabel(?int $id): string
    {
        $c = $this->categories[$id] ?? null;

        if (! $c) {
            return '(нет)';
        }

        $parent = $c->parent_id ? ($this->categories[$c->parent_id]->name ?? '?').' / ' : '';

        return $parent.$c->name;
    }

    /**
     * Цели: существующая / переименовать самую большую категорию-источник того же корня / создать.
     * Товары, у которых категория не меняется, из плана убираются. Опустевшие — к удалению.
     */
    private function planCategories(CatalogImportPlan $plan, array $targets, array $moves, array &$stats): void
    {
        $productCounts = Product::withTrashed()->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS n')->pluck('n', 'category_id');

        $uncategorized = CatalogImportPlan::pathKey([$this->map->uncategorized()]);

        // Товары, которые этот прогон удалит совсем (дубли, тестовые), — уходят из своих категорий
        $leaving = collect($plan->duplicates)->reject(fn ($d) => $d['soft'])->pluck('remove_id')
            ->merge(collect($plan->deletions)->where('action', 'force')->pluck('id'));
        $leavingFrom = Product::withTrashed()->whereIn('id', $leaving)->whereNotNull('category_id')->pluck('category_id');

        // Источники каждой цели: категория → сколько её товаров уходит в цель
        $sources = [];
        $outgoing = [];
        foreach ($moves as [$from, $to, $_]) {
            $sources[$to][$from] = ($sources[$to][$from] ?? 0) + 1;
            if ($from) {
                $outgoing[$from][$to] = true;
            }
        }

        $renamed = [];
        foreach ($targets as $key => $path) {
            $existing = $this->findCategory($path);

            if ($existing) {
                // Та же категория, но написание другое (капс, строчная буква, «е» вместо «ё») — переименовать
                $plan->categories[$key] = $existing->name === end($path)
                    ? ['action' => 'existing', 'id' => $existing->id, 'path' => $path, 'hidden' => $key === $uncategorized]
                    : ['action' => 'rename', 'id' => $existing->id, 'from' => $this->categoryLabel($existing->id), 'path' => $path, 'hidden' => $key === $uncategorized];
                $renamed[$existing->id] = true;

                continue;
            }

            $root = count($path) > 1 ? $this->findCategory([$path[0]]) : null;
            $candidates = collect($sources[$key] ?? [])
                ->filter(fn ($n, $from) => $from && $root
                    && ($this->categories[$from]->parent_id ?? null) == $root->id
                    && ! isset($renamed[$from])
                    && count($outgoing[$from] ?? []) === 1                         // все её товары — сюда
                    && $n === (int) ($productCounts[$from] ?? 0)
                    && $this->categories->where('parent_id', $from)->isEmpty())
                ->sortDesc();

            if (count($path) > 1 && $candidates->isNotEmpty()) {
                $from = $candidates->keys()->first();
                $renamed[$from] = true;
                $plan->categories[$key] = ['action' => 'rename', 'id' => $from, 'from' => $this->categoryLabel($from), 'path' => $path, 'hidden' => false];
            } else {
                $plan->categories[$key] = ['action' => 'create', 'id' => null, 'path' => $path, 'hidden' => $key === $uncategorized,
                    'missing_parent' => count($path) > 1 && ! $root];
            }
        }

        // Переезды: только если категория меняется
        $remaining = $productCounts->all();
        foreach ($leavingFrom as $categoryId) {
            $remaining[$categoryId]--;
            $outgoing[$categoryId]['(удаление)'] = true;
        }
        foreach ($moves as $productId => [$from, $to, $sku]) {
            $target = $plan->categories[$to];

            if ($target['id'] !== null && (int) $target['id'] === (int) $from) {
                continue;
            }

            $plan->products[$productId]['category'] = [$from, $to];
            $plan->products[$productId]['sku'] ??= $sku;
            $label = $this->categoryLabel($from).' → '.$to;
            $stats['category_moves'][$label] = ($stats['category_moves'][$label] ?? 0) + 1;

            if ($from) {
                $remaining[$from] = ($remaining[$from] ?? 0) - 1;
            }
        }

        // Опустевшие подкатегории (не переименованные), затем корни из drop_roots без детей
        $deleted = [];
        foreach ($remaining as $id => $n) {
            if ($n <= 0 && isset($outgoing[$id]) && ! isset($renamed[$id]) && $this->categories->where('parent_id', $id)->isEmpty()) {
                $deleted[$id] = true;
                $plan->deleteCategories[$id] = $this->categoryLabel($id);
            }
        }

        foreach ($this->map->dropRoots() as $rootName) {
            $root = $this->findCategory([$rootName]);

            if ($root
                && (int) ($remaining[$root->id] ?? 0) === 0
                && $this->categories->where('parent_id', $root->id)->every(fn ($c) => isset($deleted[$c->id]))) {
                $plan->deleteCategories[$root->id] = $root->name;
            }
        }
    }

    /**
     * Длины по схеме БД (ColumnLimits): всё, что будет записано, — в пределах колонок.
     * Нарушения — в $plan->errors; боевой прогон при них не стартует.
     */
    private function checkLimits(CatalogImportPlan $plan): void
    {
        $limits = $this->limits ?? new ColumnLimits;
        $found = [];

        $check = function (string $column, ?string $value, string $where) use ($limits, &$found) {
            if ($over = $limits->exceeds($column, $value)) {
                [$limit, $unit, $length] = $over;
                $found[$column] ??= ['column' => $column, 'limit' => "{$limit} {$unit}", 'count' => 0, 'max' => 0, 'example' => $where];
                $found[$column]['count']++;
                if ($length > $found[$column]['max']) {
                    $found[$column]['max'] = $length;
                    $found[$column]['example'] = $where;
                }
            }
        };

        foreach ($plan->products as $change) {
            $sku = (string) ($change['sku'] ?? '');
            $check('products.brand', $change['brand'][1] ?? null, $sku);
            $check('products.description', $change['description'][1] ?? null, $sku);
            $check('products.video_url', $change['video_url'][1] ?? null, $sku);
            foreach ($change['attributes'] ?? [] as $name => $value) {
                $check('product_attribute_values.value', (string) $value, "{$sku}: {$name}");
            }
        }

        foreach ($plan->newProducts as $new) {
            foreach (['sku', 'name', 'brand', 'description', 'video_url'] as $field) {
                $check("products.{$field}", $new[$field] ?? null, $new['sku']);
            }
            foreach ($new['attributes'] as $name => $value) {
                $check('product_attribute_values.value', (string) $value, "{$new['sku']}: {$name}");
            }
        }

        foreach ($plan->duplicates as $dup) {
            foreach ($dup['fill'] as $field => $value) {
                $check("products.{$field}", is_string($value) ? $value : null, $dup['keep_sku']);
            }
            foreach ($dup['attributes'] as $name => $value) {
                $check('product_attribute_values.value', (string) $value, "{$dup['keep_sku']}: {$name}");
            }
        }

        foreach ($plan->attributes as $name => $def) {
            $check('attributes.name', $name, $name);
            $check('attributes.unit', $def['unit'], $name);
        }

        foreach ($plan->categories as $key => $target) {
            $check('categories.name', end($target['path']), $key);
        }

        $plan->errors = array_values($found);
    }

    /**
     * Название в основном капсом (больше половины букв — заглавные): «НАСОС ЦИРКУЛЯЦИОННЫЙ …».
     */
    public static function isCaps(string $name): bool
    {
        preg_match_all('/\p{L}/u', $name, $letters);
        $upper = count(array_filter($letters[0], fn ($ch) => mb_strtoupper($ch) === $ch && mb_strtolower($ch) !== $ch));

        return $letters[0] && $upper / count($letters[0]) > 0.5;
    }

    // --- Справочник характеристик ---

    private function planAttributes(CatalogImportPlan $plan, array $use): void
    {
        $existing = ProductAttribute::all()->keyBy('name_key');

        foreach ([...$this->map->attributeNames(), $this->map->seriesAttribute()] as $name) {
            $def = $name === $this->map->seriesAttribute()
                ? ['type' => 'text', 'unit' => null, 'logistics' => false, 'admin' => false]
                : $this->map->attributeDef($name);

            if (! isset($use[$name]) && ! isset($existing[ProductAttribute::key($name)])) {
                continue;
            }

            $current = $existing[ProductAttribute::key($name)] ?? null;
            $want = ['type' => $def['type'], 'unit' => $def['unit'], 'is_logistics' => $def['logistics'], 'is_admin_only' => $def['admin']];
            $changes = [];

            if ($current) {
                foreach ($want as $field => $value) {
                    if ($current->{$field} != $value) {
                        $changes[$field] = [$current->{$field}, $value];
                    }
                }
            }

            $plan->attributes[$name] = $want + [
                'action' => $current ? ($changes ? 'update' : 'same') : 'create',
                'products' => $use[$name] ?? 0,
                'changes' => $changes,
            ];
        }
    }

    // --- Фильтры ---

    private function planFilters(CatalogImportPlan $plan): void
    {
        foreach ($this->map->filters() as $path => $keys) {
            $plan->filters[$path] = array_values(array_filter(
                $keys,
                fn ($key) => in_array($key, ['brand', 'price', 'stock'], true) || isset($plan->attributes[$key])
            ));
        }
    }

    // --- Дубли и тестовые товары ---

    private function planCleanup(CatalogImportPlan $plan, Collection $ours, array $rows, array $spellings, array $currentValues): void
    {
        $bySku = collect($rows)->keyBy(fn ($r) => mb_strtoupper(trim($r['sku'])));
        $ordered = DB::table('order_items')->whereNotNull('product_id')->distinct()->pluck('product_id')->flip();

        foreach ($this->map->duplicates() as $keepSku => $removeSku) {
            $keep = $ours[mb_strtoupper($keepSku)] ?? null;
            $remove = $ours[mb_strtoupper($removeSku)] ?? null;

            if (! $keep || ! $remove || $remove->trashed()) {
                continue;
            }

            $removeRow = $bySku[mb_strtoupper($removeSku)] ?? null;
            $keepRow = $bySku[mb_strtoupper($keepSku)] ?? null;
            $fill = [];

            // Название «БРЕНД артикул» — по сути пустое
            $genericName = fn (?string $name) => trim((string) $name) === ''
                || ($keepRow && CatalogMapping::key($name) === CatalogMapping::key(($keepRow['brand'] ?? '').' '.$keepRow['article']));

            if ($genericName($keep->name) && trim((string) $remove->name) !== '' && ! $genericName($remove->name)) {
                $fill['name'] = $remove->name;
            }
            if (trim((string) $keep->description) === '' && trim((string) $remove->description) !== '') {
                $fill['description'] = $remove->description;
            }
            if (! $keep->category_id && $remove->category_id) {
                $fill['category_id'] = $remove->category_id;
            }

            $keepSet = $plan->products[$keep->id]['attributes'] ?? ($currentValues[$keep->id] ?? []);
            $removeSet = $removeRow ? $this->attributeSet($removeRow, $spellings, null) : ($currentValues[$remove->id] ?? []);
            $extra = array_diff_key($removeSet, $keepSet);

            $plan->duplicates[] = [
                'keep_id' => $keep->id, 'keep_sku' => $keep->sku, 'remove_id' => $remove->id, 'remove_sku' => $remove->sku,
                'fill' => $fill, 'attributes' => $extra, 'keep_attributes' => $keepSet,
                'is_new' => $removeRow && $this->map->isNovelty($removeRow['category']) && ! $keep->is_new,
                'soft' => isset($ordered[$remove->id]),
            ];
        }

        foreach ($this->map->testProducts() as $sku) {
            $product = $ours[mb_strtoupper($sku)] ?? null;

            if (! $product) {
                continue;
            }

            $inOrders = isset($ordered[$product->id]);

            $action = match (true) {
                $inOrders && $product->trashed() => 'already',
                $inOrders => 'soft',
                default => 'force',
            };

            // Уже мягко удалён и есть в заказах — делать нечего (повторный прогон)
            if ($action === 'already') {
                continue;
            }

            $plan->deletions[] = ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'action' => $action];
        }
    }
}
