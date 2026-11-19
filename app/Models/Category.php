<?php

namespace App\Models;

use App\Services\Pricing\Discounts;
use App\Services\Search\SearchIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * is_hidden — служебная категория («Без категории»): нет в меню и каталоге, товары находятся поиском.
 * icon — иконка (App\Services\CategoryIcons: plumb:имя | upload), icon_svg — загруженная SVG.
 * tile_product_id — товар, чьё фото на плитке раздела (App\Services\CategoryTiles), если нет своей картинки.
 * is_pinned — закреплена кнопками ↑ / ↓ в админке: при порядке «по количеству товаров» идёт первой
 * в своём порядке sort_order (App\Services\Catalog\CategoryOrder).
 */
class Category extends Model
{
    /** Служебная категория для товаров, которые не удалось разложить (импорт каталога) */
    public const UNCATEGORIZED = 'Без категории';

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'sort_order',
        'is_pinned',
        'is_hidden',
        'discount_percent',
        'description',
        'seo_title',
        'seo_description',
        'faq',
        'image',
        'icon',
        'icon_svg',
        'tile_product_id',
    ];

    protected $casts = [
        'is_hidden' => 'boolean',
        'is_pinned' => 'boolean',
        'sort_order' => 'integer',
        'faq' => 'array',
    ];

    /** Порядок среди соседей (админка «Категории»), при равном — по названию */
    protected static function booted(): void
    {
        // Название категории и её родителей — в поисковом индексе товаров (SearchIndex)
        static::updated(function (Category $category) {
            if ($category->wasChanged(['name', 'parent_id'])) {
                app(SearchIndex::class)->reindexCategory($category->id);
            }

            // Скидка категории действует на товары её и подкатегорий (Discounts)
            if ($category->wasChanged(['discount_percent', 'parent_id'])) {
                app(Discounts::class)->recalculateCategory($category->id);
            }
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->ordered();
    }

    /** Товар для плитки раздела (CategoryTiles) */
    public function tileProduct()
    {
        return $this->belongsTo(Product::class, 'tile_product_id');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Картинка категории (webp, плитки подкатегорий) или null.
     */
    /**
     * Вопросы из текста формы: блоки через пустую строку, первая строка — вопрос, остальные — ответ.
     *
     * @return list<array{q: string, a: string}>|null
     */
    public static function faqFromText(?string $text): ?array
    {
        $items = collect(preg_split('/\R\s*\R/u', trim((string) $text)))
            ->map(function ($block) {
                $lines = preg_split('/\R/u', trim($block));
                $q = trim((string) array_shift($lines));

                return ['q' => $q, 'a' => trim(implode("\n", array_map('trim', $lines)))];
            })
            ->filter(fn ($item) => $item['q'] !== '' && $item['a'] !== '')
            ->values()->all();

        return $items ?: null;
    }

    /** @param  list<array{q: string, a: string}>|null  $faq */
    public static function faqToText(?array $faq): string
    {
        return collect($faq ?? [])->map(fn ($item) => ($item['q'] ?? '')."\n".($item['a'] ?? ''))->implode("\n\n");
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk(ProductImage::disk())->url($this->image) : null;
    }
}
