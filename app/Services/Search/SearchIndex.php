<?php

namespace App\Services\Search;

use App\Models\Category;
use App\Models\Product;
use App\Support\BulkUpdate;
use Illuminate\Support\Facades\DB;

/**
 * products.search_text — слова и основы названия сайта, наименования 1С, бренда и категории
 * с родителями (SearchText::index). Пересчитывается при сохранении товара (Product::booted),
 * переименовании / переносе категории (Category::booted), после записи обмена с 1С и импорта
 * каталога; все товары — командой search:reindex.
 */
class SearchIndex
{
    /** Поля товара, от которых зависит индекс */
    public const FIELDS = ['name', 'display_name', 'brand', 'category_id'];

    private const CHUNK = 1000;

    /** @var array<int, array{name: string, parent_id: ?int}>|null */
    private ?array $categories = null;

    public function __construct(private readonly SearchText $text) {}

    public function textFor(Product $product): string
    {
        return $this->build($product->display_name, $product->name, $product->brand, $product->category_id);
    }

    /**
     * Пересчитать индекс товаров (мимо модели: без событий и updated_at); null — все товары,
     * включая удалённые. Возвращает число изменённых строк.
     *
     * @param  list<int>|null  $ids
     */
    public function reindex(?array $ids = null): int
    {
        $this->categories = null;
        $changed = 0;

        $query = DB::table('products')->select(['id', 'name', 'display_name', 'brand', 'category_id', 'search_text']);

        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $query->whereIn('id', $ids);
        }

        $query->chunkById(self::CHUNK, function ($rows) use (&$changed) {
            $updates = [];

            foreach ($rows as $row) {
                $text = $this->build($row->display_name, $row->name, $row->brand, $row->category_id);

                if ($text !== (string) $row->search_text) {
                    $updates[$row->id] = ['search_text' => $text];
                }
            }

            if ($updates !== []) {
                BulkUpdate::run('products', $updates);
                $changed += count($updates);
            }
        });

        return $changed;
    }

    /**
     * Пересчитать товары категории и всех её подкатегорий (переименование, перенос).
     */
    public function reindexCategory(int $categoryId): int
    {
        $this->categories = null;
        $children = [];

        foreach ($this->categories() as $id => $category) {
            $children[(int) $category['parent_id']][] = $id;
        }

        $ids = [];
        $queue = [$categoryId];

        while ($queue !== [] && count($ids) < 10000) {
            $id = array_shift($queue);
            if (! isset($ids[$id])) {
                $ids[$id] = true;
                array_push($queue, ...($children[$id] ?? []));
            }
        }

        $products = DB::table('products')->whereIn('category_id', array_keys($ids))->pluck('id')->all();

        return $this->reindex(array_map('intval', $products));
    }

    private function build(?string $displayName, ?string $name, ?string $brand, ?int $categoryId): string
    {
        return $this->text->index($displayName, $name, $brand, ...$this->path($categoryId));
    }

    /**
     * Названия категории и её родителей; служебная «Без категории» — не слова товара.
     *
     * @return list<string>
     */
    private function path(?int $categoryId): array
    {
        $categories = $this->categories();
        $names = [];

        for ($id = $categoryId, $depth = 0; $id && isset($categories[$id]) && $depth < 10; $depth++) {
            if ($categories[$id]['name'] !== Category::UNCATEGORIZED) {
                $names[] = $categories[$id]['name'];
            }
            $id = $categories[$id]['parent_id'];
        }

        return $names;
    }

    /** @return array<int, array{name: string, parent_id: ?int}> */
    private function categories(): array
    {
        return $this->categories ??= DB::table('categories')->get(['id', 'name', 'parent_id'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => ['name' => (string) $c->name, 'parent_id' => $c->parent_id ? (int) $c->parent_id : null]])
            ->all();
    }
}
