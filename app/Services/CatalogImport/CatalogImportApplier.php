<?php

namespace App\Services\CatalogImport;

use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\CatalogFilters;
use App\Services\CatalogMenu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Применяет план импорта (CatalogImportPlanner). Идемпотентно: повторный план по тем же данным пуст.
 * Товары — пачками по CHUNK в отдельных транзакциях; справочники и категории — до товаров,
 * удаление опустевших категорий и фильтры — после.
 */
class CatalogImportApplier
{
    public const CHUNK = 500;

    /** @var array<string, int> pathKey → id категории */
    private array $categoryIds = [];

    /** @var array<string, int> название характеристики → id */
    private array $attributeIds = [];

    public function __construct(private readonly CatalogFilters $filters) {}

    /**
     * @param  callable(string): void|null  $progress
     */
    public function apply(CatalogImportPlan $plan, ?callable $progress = null): void
    {
        $say = $progress ?? fn () => null;

        $say('Справочник характеристик');
        DB::transaction(fn () => $this->applyAttributes($plan));

        $say('Категории');
        DB::transaction(fn () => $this->applyCategories($plan));

        $say('Товары: '.count($plan->products));
        foreach (array_chunk($plan->products, self::CHUNK, true) as $chunk) {
            DB::transaction(fn () => $this->applyProducts($chunk));
        }

        if ($plan->newProducts) {
            $say('Новые товары (черновики): '.count($plan->newProducts));
            foreach (array_chunk($plan->newProducts, self::CHUNK) as $chunk) {
                DB::transaction(fn () => $this->createProducts($chunk));
            }
        }

        $say('Дубли и тестовые товары');
        DB::transaction(fn () => $this->applyCleanup($plan));

        $say('Опустевшие категории и фильтры');
        DB::transaction(function () use ($plan) {
            $this->deleteCategories($plan);
            $this->applyFilters($plan);
        });

        CatalogMenu::flush();
    }

    /**
     * Справочник характеристик: найти по name_key или создать. У существующей название
     * не меняется (его могли поправить в админке), тип, единица и флаги — из маппинга.
     */
    private function applyAttributes(CatalogImportPlan $plan): void
    {
        foreach ($plan->attributes as $name => $def) {
            $attribute = ProductAttribute::firstOrNew(['name_key' => ProductAttribute::key($name)]);
            $attribute->fill([
                'name' => $attribute->exists ? $attribute->name : $name,
                'type' => $def['type'],
                'unit' => $def['unit'],
                'is_logistics' => $def['is_logistics'],
                'is_admin_only' => $def['is_admin_only'],
            ]);
            $attribute->save();

            $this->attributeIds[$name] = $attribute->id;
        }
    }

    /**
     * Категории из плана: existing — как есть, rename — новое название (slug сохраняется,
     * старые ссылки работают), иначе — создать с уникальным slug. Родители — раньше детей.
     * Запоминает id по pathKey для переноса товаров.
     */
    private function applyCategories(CatalogImportPlan $plan): void
    {
        // Сначала корни, затем подкатегории
        uasort($plan->categories, fn ($a, $b) => count($a['path']) <=> count($b['path']));

        foreach ($plan->categories as $key => $target) {
            $name = end($target['path']);

            if ($target['action'] === 'existing') {
                $category = Category::find($target['id']);
            } elseif ($target['action'] === 'rename') {
                $category = Category::find($target['id']);
                $category->name = $name;
            } else {
                $parentId = count($target['path']) > 1
                    ? ($this->categoryIds[CatalogImportPlan::pathKey(array_slice($target['path'], 0, -1))] ?? $this->ensurePath(array_slice($target['path'], 0, -1)))
                    : null;

                $category = new Category(['name' => $name, 'parent_id' => $parentId, 'slug' => $this->uniqueSlug($name)]);
            }

            if ($target['hidden']) {
                $category->is_hidden = true;
            }

            $category->save();
            $this->categoryIds[$key] = $category->id;
        }
    }

    /**
     * Найти или создать категории по пути (родитель цели, которого нет в плане).
     */
    private function ensurePath(array $path): int
    {
        $parentId = null;

        foreach ($path as $name) {
            $category = Category::where('parent_id', $parentId)->get()
                ->first(fn (Category $c) => CatalogMapping::key($c->name) === CatalogMapping::key($name))
                ?? Category::create(['name' => $name, 'parent_id' => $parentId, 'slug' => $this->uniqueSlug($name)]);

            $parentId = $category->id;
        }

        return $parentId;
    }

    /**
     * Slug из названия; занят — с суффиксом -2, -3…
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @param  array<int, array>  $chunk
     */
    private function applyProducts(array $chunk): void
    {
        foreach ($chunk as $id => $change) {
            $update = [];

            if (isset($change['brand'])) {
                $update['brand'] = $change['brand'][1];
                // Обновление мимо модели: ключ сопоставления с 1С — тем же правилом, что Product::saving
                $update['match_key'] = Product::matchKey($update['brand'], Product::withTrashed()->whereKey($id)->value('article'));
            }
            if (isset($change['category'])) {
                $update['category_id'] = $this->categoryIds[$change['category'][1]];
            }
            if (isset($change['is_new'])) {
                $update['is_new'] = true;
            }
            if (isset($change['video_url'])) {
                $update['video_url'] = $change['video_url'][1];
            }
            if (isset($change['is_published'])) {
                $update['is_published'] = $change['is_published'][1];
            }
            if (isset($change['description'])) {
                $update['description'] = $change['description'][1];
            }
            if (isset($change['description_generated'])) {
                $update['description_generated'] = $change['description_generated'][1];
            }

            if ($update) {
                Product::withTrashed()->whereKey($id)->update($update);
            }

            if (array_key_exists('attributes', $change)) {
                $this->writeAttributes($id, $change['attributes']);
            }
        }
    }

    /**
     * Набор характеристик товара целиком: старые значения удаляются, новые — в порядке набора.
     */
    private function writeAttributes(int $productId, array $set): void
    {
        ProductAttributeValue::where('product_id', $productId)->delete();

        $now = now();
        $rows = [];
        $order = 0;

        foreach ($set as $name => $value) {
            $rows[] = [
                'product_id' => $productId,
                'attribute_id' => $this->attributeIds[$name] ?? ProductAttribute::findOrCreateByName($name)->id,
                'value' => $value,
                'value_number' => ProductAttribute::parseNumber($value)
                    ?? (preg_match('/^-?\d+(?:[.,]\d+)?/u', $value, $m) ? (float) str_replace(',', '.', $m[0]) : null),
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            ProductAttributeValue::insert($rows);
        }
    }

    /**
     * Товары каталога, которых нет у нас: черновики без цены (цена и остаток — из 1С).
     */
    private function createProducts(array $chunk): void
    {
        foreach ($chunk as $new) {
            $product = new Product([
                'name' => $new['name'],
                'sku' => $new['sku'],
                'brand' => $new['brand'],
                'price' => null,
                'stock' => 0,
                'category_id' => $new['category'] ? $this->categoryIds[$new['category']] : null,
                'description' => $new['description'],
                'description_generated' => $new['description_generated'],
                'video_url' => $new['video_url'],
                'is_new' => $new['is_new'],
                'is_active' => true,
                'is_published' => false,
            ]);
            // Артикул — поле номенклатуры 1С, не в $fillable; match_key считает Product::saving
            $product->article = $new['article'] ?? null;
            $product->save();

            $this->writeAttributes($product->id, $new['attributes']);
        }
    }

    /**
     * Дубли: в оставляемый товар — недостающие поля, «Новинка» и характеристики дубля, позиции
     * корзин переводятся на него; дубль удаляется мягко или физически — по признаку soft из плана
     * (мягко — если он есть в заказах). Тестовые товары — по действию из плана (soft / force).
     */
    private function applyCleanup(CatalogImportPlan $plan): void
    {
        foreach ($plan->duplicates as $dup) {
            $keep = Product::find($dup['keep_id']);
            $remove = Product::withTrashed()->find($dup['remove_id']);

            if (! $keep || ! $remove) {
                continue;
            }

            if ($dup['fill']) {
                $keep->forceFill($dup['fill'])->save();
            }
            if ($dup['is_new']) {
                $keep->update(['is_new' => true]);
            }
            if ($dup['attributes']) {
                $this->writeAttributes($keep->id, $dup['keep_attributes'] + $dup['attributes']);
            }

            // Позиции корзин дубля — на оставляемый товар (если его там ещё нет)
            // id корзин — отдельным запросом: MySQL не обновляет таблицу с подзапросом к ней же (1093)
            $taken = DB::table('cart_items')->where('product_id', $keep->id)->pluck('cart_id');
            DB::table('cart_items')->where('product_id', $remove->id)
                ->whereNotIn('cart_id', $taken)
                ->update(['product_id' => $keep->id]);

            $dup['soft'] ? $remove->delete() : $remove->forceDelete();
        }

        foreach ($plan->deletions as $del) {
            $product = Product::withTrashed()->find($del['id']);

            match ($del['action']) {
                'soft' => $product?->delete(),
                'force' => $product?->forceDelete(),
                default => null,
            };
        }
    }

    /**
     * Удаление опустевших категорий из плана с повторной проверкой: ни товаров (включая
     * удалённые), ни подкатегорий.
     */
    private function deleteCategories(CatalogImportPlan $plan): void
    {
        foreach (array_keys($plan->deleteCategories) as $id) {
            $category = Category::find($id);

            // Защита: категория удаляется, только если в ней и правда пусто
            if ($category
                && ! Product::withTrashed()->where('category_id', $id)->exists()
                && ! Category::where('parent_id', $id)->exists()) {
                $category->delete();
            }
        }
    }

    /**
     * Фильтры категорий из filters.php: стандартные ключи (brand, price, stock) — как есть,
     * характеристики — в ключ attribute:ID, такие характеристики помечаются is_filterable.
     * Набор категории заменяется целиком (CatalogFilters::save). Категории нет — пропуск.
     */
    private function applyFilters(CatalogImportPlan $plan): void
    {
        $filterable = [];

        foreach ($plan->filters as $path => $keys) {
            $category = $this->findByPathKey($path);

            if (! $category) {
                continue;
            }

            $keys = array_map(function ($key) use (&$filterable) {
                if (in_array($key, [CategoryFilter::BRAND, CategoryFilter::PRICE, CategoryFilter::STOCK], true)) {
                    return $key;
                }

                $id = $this->attributeIds[$key] ?? ProductAttribute::where('name_key', ProductAttribute::key($key))->value('id');
                $filterable[] = $id;

                return CategoryFilter::ATTRIBUTE.':'.$id;
            }, $keys);

            ProductAttribute::whereIn('id', array_filter($filterable))->update(['is_filterable' => true]);
            $this->filters->save($category, $keys);
        }
    }

    /**
     * Категория по pathKey («Корень / Подкатегория») — как findCategory в планировщике,
     * но запросами к БД (после applyCategories).
     */
    private function findByPathKey(string $pathKey): ?Category
    {
        $parentId = null;
        $found = null;

        foreach (explode(' / ', $pathKey) as $name) {
            $found = Category::where('parent_id', $parentId)->get()
                ->first(fn (Category $c) => CatalogMapping::key($c->name) === CatalogMapping::key($name));

            if (! $found) {
                return null;
            }

            $parentId = $found->id;
        }

        return $found;
    }
}
