<?php

namespace App\Services\Catalog;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список товаров категории с фильтрами и сортировкой (SRS §3.9, §3.10; BR-017…022).
 *
 * Параметры URL (GET): brand[]=…, price[from]/price[to], stock[]=in_stock|on_order|out_of_stock,
 * a[ID][]=значение (текстовая характеристика), a[ID][from]/a[ID][to] (числовая),
 * sort=…, page=…. Между фильтрами — И, между значениями одного фильтра — ИЛИ (BR-018).
 * Значения фильтра считаются по всей категории и от выбора в других не зависят (BR-017).
 * Только активные и опубликованные товары.
 */
class CatalogListing
{
    public const PER_PAGE = 24;

    public const SORTS = [
        'default'    => 'По умолчанию',
        'name_asc'   => 'Название: А–Я',
        'name_desc'  => 'Название: Я–А',
        'price_asc'  => 'Сначала дешевле',
        'price_desc' => 'Сначала дороже',
        'popular'    => 'Популярные',
    ];

    public const STOCK = [
        'in_stock'     => 'В наличии',
        'on_order'     => 'Под заказ',
        'out_of_stock' => 'Нет в наличии',
    ];

    /** @var Collection<int, CategoryFilter> */
    public Collection $filters;

    public ?Category $filtersSource;

    /** Выбранное: brand, stock — списки; price — [from, to]; text[id] — список; number[id] — [from, to] */
    public array $selected = ['brand' => [], 'price' => [null, null], 'stock' => [], 'text' => [], 'number' => []];

    public string $sort = 'default';

    private array $categoryIds;

    public function __construct(
        public readonly Category $category,
        array $categoryIds,
        CatalogFilters $config,
        private readonly array $query,
        private readonly bool $withPrices,
    ) {
        $this->categoryIds = $categoryIds;

        ['source' => $this->filtersSource, 'filters' => $filters] = $config->forCategory($category);

        // Цены видны только после входа — фильтр по цене гостю не показывается
        $this->filters = $filters->reject(fn ($f) => $f->type === CategoryFilter::PRICE && ! $withPrices)->values();

        $this->parse();
    }

    // --- Разбор параметров ---

    /**
     * Разбор GET-параметров в $selected. Принимаются только фильтры, включённые для категории
     * (CatalogFilters), остальные параметры молча игнорируются — ссылка со «старым» фильтром
     * не ломает список. Текстовая характеристика берёт только элементы списка a[ID][] (целые
     * ключи), числовая — только a[ID][from] / a[ID][to]. Неизвестная сортировка — default.
     */
    private function parse(): void
    {
        $q = $this->query;
        $enabled = $this->filters->map->key()->flip();

        if (isset($enabled[CategoryFilter::BRAND])) {
            $this->selected['brand'] = $this->strings($q['brand'] ?? []);
        }

        if (isset($enabled[CategoryFilter::PRICE])) {
            $this->selected['price'] = $this->range($q['price'] ?? []);
        }

        if (isset($enabled[CategoryFilter::STOCK])) {
            $this->selected['stock'] = array_values(array_intersect($this->strings($q['stock'] ?? []), array_keys(self::STOCK)));
        }

        foreach ($this->filters->where('type', CategoryFilter::ATTRIBUTE) as $filter) {
            $raw = $q['a'][$filter->attribute_id] ?? null;

            if (! is_array($raw)) {
                continue;
            }

            if ($filter->attribute->isNumber()) {
                $range = $this->range($raw);

                if ($range !== [null, null]) {
                    $this->selected['number'][$filter->attribute_id] = $range;
                }
            } elseif ($values = $this->strings(array_filter($raw, 'is_int', ARRAY_FILTER_USE_KEY))) {
                $this->selected['text'][$filter->attribute_id] = $values;
            }
        }

        $sort = (string) ($q['sort'] ?? 'default');
        $this->sort = array_key_exists($sort, $this->sorts()) ? $sort : 'default';
    }

    /**
     * Значения фильтра-списка: только строки, без пустых и повторов, не длиннее 255 символов.
     */
    private function strings($values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', (array) $values),
            fn ($v) => $v !== '' && mb_strlen($v) <= 255
        )));
    }

    /**
     * Диапазон «от–до» как [from, to] (null — граница не задана); числа — как в характеристиках
     * («1,5» → 1.5, ProductAttribute::parseNumber), не число — null.
     */
    private function range($raw): array
    {
        $raw = (array) $raw;
        $from = ProductAttribute::parseNumber(is_string($raw['from'] ?? null) ? $raw['from'] : null);
        $to = ProductAttribute::parseNumber(is_string($raw['to'] ?? null) ? $raw['to'] : null);

        // «от 500 до 100» — меняем местами, а не показываем пустой список
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    // --- Запросы ---

    /**
     * Товары категории с подкатегориями, активные и опубликованные — до фильтров.
     */
    public function base(): Builder
    {
        return Product::query()
            ->whereIn('products.category_id', $this->categoryIds)
            ->where('products.is_active', true)
            ->where('products.is_published', true);
    }

    /**
     * base() с выбранными фильтрами (BR-018): каждый фильтр — отдельное условие (И), значения
     * внутри фильтра — whereIn / orWhere (ИЛИ). Характеристики — whereExists по
     * product_attribute_values: текстовые по value, числовые по value_number.
     */
    public function filtered(): Builder
    {
        $query = $this->base();
        $s = $this->selected;

        if ($s['brand']) {
            $query->whereIn('products.brand', $s['brand']);
        }

        [$from, $to] = $s['price'];

        if ($from !== null) {
            $query->where('products.price', '>=', $from);
        }

        if ($to !== null) {
            $query->where('products.price', '<=', $to);
        }

        if ($s['stock']) {
            $query->where(function (Builder $q) use ($s) {
                foreach ($s['stock'] as $status) {
                    $q->orWhere(fn (Builder $w) => $this->whereStock($w, $status));
                }
            });
        }

        foreach ($s['text'] as $attributeId => $values) {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('product_attribute_values as pav')
                ->whereColumn('pav.product_id', 'products.id')
                ->where('pav.attribute_id', $attributeId)
                ->whereIn('pav.value', $values));
        }

        foreach ($s['number'] as $attributeId => [$from, $to]) {
            $query->whereExists(fn ($e) => $e->selectRaw('1')->from('product_attribute_values as pav')
                ->whereColumn('pav.product_id', 'products.id')
                ->where('pav.attribute_id', $attributeId)
                ->when($from !== null, fn ($w) => $w->where('pav.value_number', '>=', $from))
                ->when($to !== null, fn ($w) => $w->where('pav.value_number', '<=', $to)));
        }

        return $query;
    }

    /**
     * Три взаимоисключающих статуса наличия — те же правила, что CASE в stockOptions():
     * остаток > 0 — «В наличии»; иначе on_order — «Под заказ», иначе — «Нет в наличии».
     */
    private function whereStock(Builder $q, string $status): void
    {
        match ($status) {
            'in_stock'     => $q->where('products.stock', '>', 0),
            'on_order'     => $q->where('products.stock', '<=', 0)->where('products.on_order', true),
            'out_of_stock' => $q->where('products.stock', '<=', 0)->where('products.on_order', false),
        };
    }

    /**
     * Страница товаров: фильтры, затем сортировка (BR-021), параметры — в ссылках пагинации.
     */
    public function products(): LengthAwarePaginator
    {
        $query = $this->filtered()->select('products.*')->with(['category', 'mainImage']);

        match ($this->sort) {
            'name_asc'   => $query->orderByRaw("COALESCE(NULLIF(products.display_name, ''), products.name) asc"),
            'name_desc'  => $query->orderByRaw("COALESCE(NULLIF(products.display_name, ''), products.name) desc"),
            // Товары без цены — в конце при любом направлении
            'price_asc'  => $query->orderByRaw('products.price IS NULL')->orderBy('products.price'),
            'price_desc' => $query->orderByRaw('products.price IS NULL')->orderByDesc('products.price'),
            'popular'    => $query->leftJoinSub($this->popularity(), 'pop', 'pop.product_id', '=', 'products.id')
                ->orderByRaw('COALESCE(pop.units, 0) desc'),
            default      => $query->latest('products.created_at'),
        };

        // Стабильный порядок между страницами
        $query->orderByDesc('products.id');

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Популярность — заказанные единицы в оформленных заказах (отменённые не считаются).
     */
    private function popularity()
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.status', '<>', OrderStatus::Cancelled->value)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units');
    }

    // --- Значения фильтров (BR-017: по всей категории, без учёта выбора) ---

    /**
     * @return list<array{filter: CategoryFilter, key: string, title: string, kind: string, options?: list, min?: ?float, max?: ?float}>
     */
    public function facets(): array
    {
        $ids = $this->base()->select('products.id');
        $attributeIds = $this->filters->where('type', CategoryFilter::ATTRIBUTE)->pluck('attribute_id')->all();

        $textValues = $attributeIds
            ? ProductAttributeValue::query()
                ->whereIn('attribute_id', $attributeIds)
                ->whereIn('product_id', $ids)
                ->where('value', '<>', '')
                ->groupBy('attribute_id', 'value')
                ->selectRaw('attribute_id, value, COUNT(*) as c')
                ->orderBy('value')
                ->get()
                ->groupBy('attribute_id')
            : collect();

        $numberRanges = $attributeIds
            ? ProductAttributeValue::query()
                ->whereIn('attribute_id', $attributeIds)
                ->whereIn('product_id', $ids)
                ->whereNotNull('value_number')
                ->groupBy('attribute_id')
                ->selectRaw('attribute_id, MIN(value_number) as min, MAX(value_number) as max')
                ->get()
                ->keyBy('attribute_id')
            : collect();

        $facets = [];

        foreach ($this->filters as $filter) {
            $facet = match ($filter->type) {
                CategoryFilter::BRAND => [
                    'kind'    => 'list',
                    'title'   => 'Производитель',
                    'name'    => 'brand[]',
                    'options' => $this->base()->whereNotNull('brand')->where('brand', '<>', '')
                        ->groupBy('brand')->selectRaw('brand as value, COUNT(*) as c')->orderBy('brand')->toBase()->get()
                        ->map(fn ($r) => ['value' => $r->value, 'label' => $r->value, 'count' => (int) $r->c,
                            'checked' => in_array($r->value, $this->selected['brand'], true)])->all(),
                ],
                CategoryFilter::PRICE => $this->rangeFacet('Цена без НДС, BYN', 'price',
                    $this->base()->whereNotNull('price')->toBase()->selectRaw('MIN(price) as min, MAX(price) as max')->first(),
                    $this->selected['price']),
                CategoryFilter::STOCK => [
                    'kind'    => 'list',
                    'title'   => 'Наличие',
                    'name'    => 'stock[]',
                    'options' => $this->stockOptions(),
                ],
                CategoryFilter::ATTRIBUTE => $filter->attribute->isNumber()
                    ? $this->rangeFacet(
                        $filter->attribute->name . ($filter->attribute->unit ? ", {$filter->attribute->unit}" : ''),
                        "a[{$filter->attribute_id}]",
                        $numberRanges[$filter->attribute_id] ?? null,
                        $this->selected['number'][$filter->attribute_id] ?? [null, null])
                    : [
                        'kind'    => 'list',
                        'title'   => $filter->attribute->name,
                        'name'    => "a[{$filter->attribute_id}][]",
                        'options' => collect($textValues[$filter->attribute_id] ?? [])
                            ->map(fn ($r) => ['value' => $r->value, 'label' => $r->value . ($filter->attribute->unit ? " {$filter->attribute->unit}" : ''),
                                'count' => (int) $r->c,
                                'checked' => in_array($r->value, $this->selected['text'][$filter->attribute_id] ?? [], true)])
                            ->values()->all(),
                    ],
            };

            // Фильтр без значений в категории не показывается
            if (($facet['kind'] === 'list' && $facet['options']) || ($facet['kind'] === 'range' && $facet['min'] !== null)) {
                $facets[] = $facet + ['key' => $filter->key()];
            }
        }

        return $facets;
    }

    private function rangeFacet(string $title, string $name, $row, array $selected): array
    {
        return [
            'kind'  => 'range',
            'title' => $title,
            'name'  => $name,
            'min'   => $row?->min !== null ? (float) $row->min : null,
            'max'   => $row?->max !== null ? (float) $row->max : null,
            'from'  => $selected[0],
            'to'    => $selected[1],
        ];
    }

    /**
     * Варианты фильтра «Наличие» со счётчиками по всей категории (BR-017, без учёта выбора
     * в других фильтрах); статус без товаров не показывается.
     */
    private function stockOptions(): array
    {
        $counts = $this->base()->toBase()
            ->selectRaw("CASE WHEN stock > 0 THEN 'in_stock' WHEN on_order = 1 THEN 'on_order' ELSE 'out_of_stock' END as status, COUNT(*) as c")
            ->groupBy('status')
            ->pluck('c', 'status');

        return collect(self::STOCK)
            ->filter(fn ($label, $status) => ($counts[$status] ?? 0) > 0)
            ->map(fn ($label, $status) => ['value' => $status, 'label' => $label, 'count' => (int) $counts[$status],
                'checked' => in_array($status, $this->selected['stock'], true)])
            ->values()->all();
    }

    // --- Выбранные фильтры: чипы ---

    /**
     * @return list<array{label: string, url: string}> — «Производитель: Grohe» и ссылка без него
     */
    public function chips(string $baseUrl): array
    {
        $chips = [];
        $names = $this->filters->where('type', CategoryFilter::ATTRIBUTE)->mapWithKeys(fn ($f) => [$f->attribute_id => $f->attribute]);
        $range = fn ($from, $to, $unit = '') => trim(($from !== null ? 'от ' . $this->num($from) . ' ' : '') . ($to !== null ? 'до ' . $this->num($to) : '')) . $unit;

        foreach ($this->selected['brand'] as $brand) {
            $chips[] = ['label' => "Производитель: {$brand}", 'url' => $this->urlWithout($baseUrl, ['brand', $brand])];
        }

        if ($this->selected['price'] !== [null, null]) {
            $chips[] = ['label' => 'Цена: ' . $range(...$this->selected['price']) . ' BYN', 'url' => $this->urlWithout($baseUrl, ['price'])];
        }

        foreach ($this->selected['stock'] as $status) {
            $chips[] = ['label' => self::STOCK[$status], 'url' => $this->urlWithout($baseUrl, ['stock', $status])];
        }

        foreach ($this->selected['text'] as $id => $values) {
            foreach ($values as $value) {
                $chips[] = ['label' => "{$names[$id]->name}: {$value}", 'url' => $this->urlWithout($baseUrl, ['a', $id, $value])];
            }
        }

        foreach ($this->selected['number'] as $id => [$from, $to]) {
            $unit = $names[$id]->unit ? " {$names[$id]->unit}" : '';
            $chips[] = ['label' => "{$names[$id]->name}: " . $range($from, $to, $unit), 'url' => $this->urlWithout($baseUrl, ['a', $id])];
        }

        return $chips;
    }

    public function activeCount(): int
    {
        $s = $this->selected;

        return count($s['brand']) + ($s['price'] !== [null, null] ? 1 : 0) + count($s['stock'])
            + array_sum(array_map('count', $s['text'])) + count($s['number']);
    }

    /**
     * «Сбросить всё» — только сортировка остаётся (BR-022).
     */
    public function resetUrl(string $baseUrl): string
    {
        return $this->sort === 'default' ? $baseUrl : $baseUrl . '?' . http_build_query(['sort' => $this->sort]);
    }

    /**
     * Текущие параметры без одного значения (и без страницы — список изменился).
     */
    private function urlWithout(string $baseUrl, array $path): string
    {
        $q = $this->query;
        unset($q['page']);

        if ($path[0] === 'a') {
            if (count($path) === 2) {
                unset($q['a'][$path[1]]);
            } else {
                $q['a'][$path[1]] = array_values(array_diff((array) ($q['a'][$path[1]] ?? []), [$path[2]]));
            }
        } elseif (count($path) === 1) {
            unset($q[$path[0]]);
        } else {
            $q[$path[0]] = array_values(array_diff((array) ($q[$path[0]] ?? []), [$path[1]]));
        }

        $q = array_filter($q, fn ($v) => $v !== [] && $v !== null && $v !== '');
        $query = http_build_query($q);

        return $query === '' ? $baseUrl : "{$baseUrl}?{$query}";
    }

    public function sorts(): array
    {
        return $this->withPrices ? self::SORTS : array_diff_key(self::SORTS, array_flip(['price_asc', 'price_desc']));
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
