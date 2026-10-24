<?php

namespace App\Http\Controllers;

use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\CartSummary;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\KeyAttributes;
use App\Services\CatalogMenu;
use Illuminate\Support\Collection;

/**
 * Карточка товара (макет docs/design/Product).
 */
class ProductController extends Controller
{
    /** Ключевых характеристик под карточкой цены */
    public const KEY_ROWS = 4;

    public function show($id)
    {
        $product = Product::with([
            'images',
            'category',
        ])
            ->where('is_active', 1)
            ->where('is_published', true)
            ->findOrFail($id);

        // BR-082: сбой при загрузке характеристик не роняет карточку — блок не показывается
        rescue(
            fn () => $product->load('attributeValues.attribute'),
            fn () => $product->setRelation('attributeValues', collect())
        );

        return view('product', self::cardData($product));
    }

    /**
     * Данные шаблона product — для карточки и для предпросмотра из админки (AdminProductController::preview).
     * Характеристики товара уже загружены (attributeValues.attribute или черновые).
     */
    public static function cardData(Product $product): array
    {
        $self = new self;
        $category = $product->category;
        $categoryFilters = $category ? app(CatalogFilters::class)->forCategory($category)['filters'] : collect();

        return [
            'product' => $product,
            'breadcrumbs' => $self->breadcrumbs($product, app(CatalogMenu::class)),
            'keyRows' => $self->keyRows($product, app(KeyAttributes::class)->attributeIds($categoryFilters)),
            'brandUrl' => $self->brandUrl($product, $categoryFilters),
            'inCart' => app(CartSummary::class)->items(auth()->user())->get($product->id),
            // Хранения документов товара (инструкции, сертификаты) пока нет — блок «Документы» скрыт
            'documents' => collect(),
        ];
    }

    /**
     * Главная / Каталог / разделы / категория товара. Категория ведёт на последний открытый
     * список с фильтрами, сортировкой и страницей (BR-084), прокрутка восстановится.
     *
     * @return list<array{label: string, url: string}>
     */
    private function breadcrumbs(Product $product, CatalogMenu $menu): array
    {
        $chain = $product->category ? $menu->chain($product->category) : [];

        if ($chain) {
            $chain[count($chain) - 1]['url'] = CatalogController::backUrl($product->category);
        }

        return [
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
            ...$chain,
        ];
    }

    /**
     * Ключевые характеристики: сначала фильтруемые в категории товара (в порядке фильтров),
     * затем остальные по порядку из админки; пустые не выводятся.
     *
     * @param  list<int>  $filterIds
     * @return Collection<int, array{name: string, value: string}>
     */
    private function keyRows(Product $product, array $filterIds): Collection
    {
        $order = array_flip($filterIds);

        return rescue(fn () => $product->attributeValues
            ->filter(fn (ProductAttributeValue $v) => $v->attribute && trim((string) $v->value) !== '')
            ->sortBy(fn (ProductAttributeValue $v, $i) => [isset($order[$v->attribute_id]) ? 0 : 1, $order[$v->attribute_id] ?? $i])
            ->take(self::KEY_ROWS)
            ->map(fn (ProductAttributeValue $v) => ['name' => $v->attribute->name, 'value' => $v->displayValue()])
            ->values(), collect());
    }

    /**
     * Производитель — ссылкой: товары бренда в категории (если там есть фильтр по производителю),
     * иначе — поиск по бренду.
     */
    private function brandUrl(Product $product, Collection $categoryFilters): ?string
    {
        if (! $product->brand) {
            return null;
        }

        if ($product->category && $categoryFilters->contains(fn ($f) => $f->type === CategoryFilter::BRAND)) {
            return route('catalog.show', $product->category->slug, false).'?'.http_build_query(['brand' => [$product->brand]]);
        }

        return route('search.index', ['q' => $product->brand], false);
    }
}
