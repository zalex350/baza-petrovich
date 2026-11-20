<?php

namespace App\Http\Controllers;

use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductDocument;
use App\Services\CartSummary;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\KeyAttributes;
use App\Services\CatalogMenu;
use App\Services\ClientCodes;
use App\Support\Seo;
use App\Support\SeoSchema;
use App\Support\SeoTemplates;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Карточка товара (макет docs/design/Product).
 */
class ProductController extends Controller
{
    /** Ключевых характеристик под карточкой цены */
    public const KEY_ROWS = 4;

    /**
     * $key — slug товара или id (старые ссылки, товары без slug). По id у товара со slug — 301 на ЧПУ:
     * у карточки один адрес для поиска и рекламы.
     */
    public function show(string $key)
    {
        $shown = fn () => Product::with(['images', 'category'])
            ->where('is_active', 1)
            ->where('is_published', true);

        $product = $shown()->where('slug', $key)->first();

        if (! $product && ctype_digit($key)) {
            $product = $shown()->findOrFail((int) $key);

            if ($product->slug) {
                return redirect()->to($product->url().(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301);
            }
        }

        abort_unless($product, 404);

        // BR-082: сбой при загрузке характеристик не роняет карточку — блок не показывается
        rescue(
            fn () => $product->load('attributeValues.attribute'),
            fn () => $product->setRelation('attributeValues', collect())
        );

        // «Ваш код» рядом с артикулом
        app(ClientCodes::class)->load([$product]);

        $data = self::cardData($product);
        $this->seo($product, $data['breadcrumbs']);

        return view('product', $data);
    }

    /**
     * Заголовок и описание по шаблону (или свои у товара), фото для мессенджеров,
     * разметка Product (цена, наличие) и хлебные крошки — для поиска и Google Merchant.
     */
    private function seo(Product $product, array $breadcrumbs): void
    {
        $product->category?->loadMissing('parent');
        $images = $product->gallery()->take(5)->map(fn ($image) => Seo::absolute($image->url('large')))->values()->all();
        $description = SeoTemplates::productDescription($product);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $product->description)));

        seo()->title(SeoTemplates::productTitle($product))
            ->description($description)
            ->image($images[0] ?? null)
            ->type('product')
            ->canonical($product->url(false))
            ->breadcrumbs($breadcrumbs)
            ->schema(SeoSchema::product($product, Str::limit($text !== '' ? $text : $description, 1000, '…'), $images));
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
            'inCart' => app(CartSummary::class)->items()->get($product->id),
            // Документы (SRS §4.8): нет ни одного — блок «Документы» скрыт
            'documents' => $product->documents->map(fn (ProductDocument $document) => [
                'title' => $document->title,
                'type' => $document->type->label(),
                'icon' => $document->type->icon(),
                'url' => $document->url(),
                'meta' => $document->type->label().' · '.$document->sizeLabel(),
            ]),
        ];
    }

    /**
     * Главная / Каталог / разделы / категория товара — всегда цепочка категории самого товара.
     * Если последний открытый список — эта категория или её предок, его крошка ведёт на список
     * с фильтрами, сортировкой и страницей (BR-084), прокрутка восстановится. Список другой
     * категории (товар открыт из поиска, по ссылке, из корзины) в крошки не попадает.
     *
     * @return list<array{label: string, url: string}>
     */
    private function breadcrumbs(Product $product, CatalogMenu $menu): array
    {
        // Служебной категории («Без категории») в крошках нет
        $chain = $product->category && ! $product->category->is_hidden ? $menu->chain($product->category) : [];

        $back = CatalogController::backUrl();
        $backPath = $back ? parse_url($back, PHP_URL_PATH) : null;

        foreach ($chain as $i => $crumb) {
            if ($backPath === $crumb['url']) {
                $chain[$i]['url'] = $back;
            }
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
            // Логистика и служебные (ТН ВЭД) в «Коротко» не попадают; «Комплектация» — свой раздел
            ->reject(fn (ProductAttributeValue $v) => $v->attribute->is_logistics || $v->attribute->is_admin_only
                || Product::isPackageAttribute($v->attribute->name))
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
