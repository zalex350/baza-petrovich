<?php

namespace App\Services\CatalogImport;

/**
 * План импорта каталога: что изменится. Строит CatalogImportPlanner (только чтение),
 * применяет CatalogImportApplier, описывает CatalogImportReport.
 */
class CatalogImportPlan
{
    /** Строк в catalog.db */
    public int $catalogRows = 0;

    /**
     * Изменения существующих товаров: id → [sku, brand?, category?, is_new?, description?,
     * description_generated?, attributes?]. category — ключ пути «Корень / Подкатегория».
     *
     * @var array<int, array>
     */
    public array $products = [];

    /** Товары каталога, которых нет у нас: черновики. @var list<array> */
    public array $newProducts = [];

    /** Товаров каталога без изменений */
    public int $unchanged = 0;

    /**
     * Категории: path → ['action' => existing|rename|create, 'id' => ?, 'from' => ?, 'path' => list, 'hidden' => bool].
     *
     * @var array<string, array>
     */
    public array $categories = [];

    /** Опустевшие категории к удалению: id → «Корень / Название» @var array<int, string> */
    public array $deleteCategories = [];

    /** Справочник: name → [action create|update|same, type, unit, logistics, admin, products, changes] @var array<string, array> */
    public array $attributes = [];

    /** Ключи каталога, которые не загружаются: ключ → товаров @var array<string, int> */
    public array $droppedKeys = [];

    /** Фильтры: путь → ключи фильтров @var array<string, list<string>> */
    public array $filters = [];

    /** Дубли: [keep_id, keep_sku, remove_id, remove_sku, fill (поля), attributes (+N), is_new, soft] @var list<array> */
    public array $duplicates = [];

    /** Тестовые товары: [id, sku, name, action: force|soft|already] @var list<array> */
    public array $deletions = [];

    /** Без категории в каталоге и «Новинки»: [sku, name, было, path, rule] — для uncategorized.csv @var list<array> */
    public array $uncategorized = [];

    /**
     * Нарушения ограничений колонок (ColumnLimits): [column, limit, count, max, example].
     * Есть — боевой прогон не стартует.
     *
     * @var list<array>
     */
    public array $errors = [];

    /** Счётчики для отчёта @var array<string, mixed> */
    public array $stats = [];

    public static function pathKey(array $path): string
    {
        return implode(' / ', $path);
    }
}
