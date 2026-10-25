<?php

namespace App\Services\CatalogImport;

/**
 * Маппинги импорта каталога из файлов database/data/catalog/*.php (их правит заказчик):
 * бренды, категории, характеристики, фильтры, дубли и тестовые товары. Только чтение и
 * сопоставление — без обращения к БД.
 */
class CatalogMapping
{
    /** @var array<string, string> ключ бренда → каноническое название */
    private array $brandIndex = [];

    /** @var array<string, array> ключ значения category → путь */
    private array $categoryIndex = [];

    /** @var array<string, string> ключ серии «Новинок» → путь */
    private array $noveltySeriesIndex = [];

    /** @var array<string, string> ключ из каталога → каноническая характеристика */
    private array $attributeKeyIndex = [];

    /** @var array<string, true> */
    private array $skipIndex = [];

    public function __construct(
        public readonly array $brands,
        public readonly array $categories,
        public readonly array $attributes,
        public readonly array $filters,
        public readonly array $cleanup,
    ) {
        foreach ($brands['canonical'] ?? [] as $brand) {
            $this->brandIndex[self::key($brand)] = $brand;
        }
        foreach ($brands['aliases'] ?? [] as $alias => $brand) {
            $this->brandIndex[self::key($alias)] = $brand;
        }
        foreach ($categories['map'] ?? [] as $value => $path) {
            $this->categoryIndex[self::key($value)] = $path;
        }
        foreach ($categories['novelty_series'] ?? [] as $series => $path) {
            $this->noveltySeriesIndex[self::key($series)] = $path;
        }
        foreach ($attributes['attributes'] ?? [] as $name => $def) {
            foreach ($def['keys'] ?? [] as $key) {
                $this->attributeKeyIndex[self::key($key)] = $name;
            }
        }
        foreach ($attributes['skip'] ?? [] as $key) {
            $this->skipIndex[self::key($key)] = true;
        }
    }

    public static function load(string $dir): self
    {
        $read = fn (string $file) => is_file("{$dir}/{$file}.php") ? require "{$dir}/{$file}.php" : [];

        return new self($read('brands'), $read('categories'), $read('attributes'), $read('filters'), $read('cleanup'));
    }

    /**
     * Ключ сравнения: без регистра, «ё», кавычек и лишних пробелов.
     */
    public static function key(?string $value): string
    {
        $value = str_replace(['ё', 'Ё'], ['е', 'Е'], (string) $value);
        $value = preg_replace('/["«»“”„\'`]/u', '', $value);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    // --- Бренды ---

    /**
     * Канонический бренд; неизвестный — как в каталоге (без лишних пробелов); пустой — null.
     */
    public function brand(?string $raw): ?string
    {
        $raw = trim(preg_replace('/\s+/u', ' ', (string) $raw));

        if ($raw === '') {
            return null;
        }

        return $this->brandIndex[self::key($raw)] ?? $raw;
    }

    /**
     * Бренд-серия (TIM Thermo, AquaTIM) → значение «Серии»; иначе null.
     */
    public function brandSeries(?string $raw): ?string
    {
        foreach ($this->brands['series'] ?? [] as $alias => $series) {
            if (self::key($alias) === self::key($raw)) {
                return $series;
            }
        }

        return null;
    }

    // --- Категории ---

    public function uncategorized(): string
    {
        return $this->categories['uncategorized'] ?? 'Без категории';
    }

    public function isNovelty(?string $value): bool
    {
        return isset($this->categories['novelty']) && self::key($value) === self::key($this->categories['novelty']);
    }

    /**
     * Путь из map для значения category или null (значение остаётся в своей категории).
     *
     * @return list<string>|null
     */
    public function mappedPath(?string $value): ?array
    {
        return $this->categoryIndex[self::key($value)] ?? null;
    }

    /**
     * Категория по словам названия, затем серии: [путь, описание правила] или null.
     *
     * @return array{0: list<string>, 1: string}|null
     */
    public function ruleFor(?string $name, ?string $series): ?array
    {
        foreach (['название' => $name, 'серия' => $series] as $field => $text) {
            $text = mb_strtolower((string) $text);

            if (trim($text) === '') {
                continue;
            }

            foreach ($this->categories['rules'] ?? [] as [$pattern, $path]) {
                if (preg_match('/'.$pattern.'/u', $text)) {
                    return [$path, "{$field}: /{$pattern}/"];
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    public function noveltySeriesPath(?string $series): ?array
    {
        return $this->noveltySeriesIndex[self::key($series)] ?? null;
    }

    public function dropRoots(): array
    {
        return $this->categories['drop_roots'] ?? [];
    }

    // --- Характеристики ---

    public function isSkippedKey(string $key): bool
    {
        return isset($this->skipIndex[self::key($key)]);
    }

    /**
     * Каноническая характеристика для ключа каталога или null (ключ не загружается).
     */
    public function attributeFor(string $key): ?string
    {
        return $this->attributeKeyIndex[self::key($key)] ?? null;
    }

    /**
     * @return array{type: string, unit: ?string, logistics: bool, admin: bool, values: array<string, string>}
     */
    public function attributeDef(string $name): array
    {
        $def = $this->attributes['attributes'][$name] ?? [];

        return [
            'type' => $def['type'] ?? 'text',
            'unit' => $def['unit'] ?? null,
            'logistics' => (bool) ($def['logistics'] ?? false),
            'admin' => (bool) ($def['admin'] ?? false),
            'values' => collect($def['values'] ?? [])->mapWithKeys(fn ($to, $from) => [self::key($from) => $to])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    public function attributeNames(): array
    {
        return array_keys($this->attributes['attributes'] ?? []);
    }

    public function seriesAttribute(): string
    {
        return $this->attributes['series'] ?? 'Серия';
    }

    /**
     * Значение для сайта и число для фильтров. number: «35 мм» → ['35', 35.0]; «2,6» → ['2,6', 2.6];
     * число с пояснением («6 бар (10 для испытания)», «600-900») — текстом, число — первое.
     *
     * @return array{0: string, 1: ?float}
     */
    public function normalizeValue(string $attribute, string $raw): array
    {
        $def = $this->attributeDef($attribute);
        $value = trim(preg_replace('/\s+/u', ' ', $raw));
        $value = $def['values'][self::key($value)] ?? $value;

        if ($def['type'] !== 'number') {
            return [$value, null];
        }

        if (! preg_match('/^(-?\d+(?:[.,]\d+)?)\s*(.*)$/u', $value, $m)) {
            return [$value, null];
        }

        $number = (float) str_replace(',', '.', $m[1]);
        $rest = trim($m[2]);

        // Остаток — только единица («мм», «лет», «бар», «°C») — значение без неё, единица в справочнике
        if ($rest === '' || preg_match('/^[\p{L}°³².\/"\']{1,12}$/u', $rest)) {
            return [str_replace('.', ',', $m[1]), $number];
        }

        return [$value, $number];
    }

    // --- Фильтры, дубли, тестовые товары ---

    /**
     * @return array<string, list<string>> путь «Корень / Подкатегория» → фильтры
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, string> оставить → удалить
     */
    public function duplicates(): array
    {
        return $this->cleanup['duplicates'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function testProducts(): array
    {
        return $this->cleanup['test_products'] ?? [];
    }
}
