<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Фильтры, сортировка и состояние каталога (SRS §3.9, §3.10, §3.13;
 * BR-016…022, BR-029, BR-030, BR-084; UC-016, AC-046).
 */
class CatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $parent;

    private Category $child;

    private ProductAttribute $color;

    private ProductAttribute $width;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->parent = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        $this->child = Category::create(['name' => 'Для кухни', 'slug' => 'dlya-kuhni', 'parent_id' => $this->parent->id]);

        $this->color = ProductAttribute::create(['name' => 'Цвет', 'is_filterable' => true]);
        $this->width = ProductAttribute::create(['name' => 'Ширина', 'unit' => 'мм', 'type' => 'number', 'is_filterable' => true]);

        // Фильтры родителя: производитель, цена, наличие, цвет, ширина
        app(CatalogFilters::class)->save($this->parent, ['brand', 'price', 'stock', "attribute:{$this->color->id}", "attribute:{$this->width->id}"]);
    }

    private function product(string $name, array $data = [], array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        $product = Product::create($data + [
            'name' => $name,
            'sku' => "SKU-{$n}",
            'brand' => 'Grohe',
            'price' => 100,
            'stock' => 5,
            'category_id' => $this->child->id,
            'is_active' => true,
            'is_published' => true,
        ]);

        $order = 0;
        foreach ($attrs as $attributeId => $value) {
            ProductAttributeValue::create([
                'product_id' => $product->id,
                'attribute_id' => $attributeId,
                'value' => (string) $value,
                'value_number' => ProductAttribute::parseNumber((string) $value),
                'sort_order' => $order++,
            ]);
        }

        return $product;
    }

    /**
     * Названия товаров на странице — в порядке показа.
     */
    private function names(string $url, ?User $as = null): array
    {
        $html = $this->actingAs($as ?? $this->client)->get($url)->assertOk()->getContent();

        preg_match_all('#data-product-link>\s*(.+?)\s*</a>#u', $html, $m);

        return array_values(array_unique($m[1]));
    }

    // --- Фильтры по типам ---

    public function test_brand_filter_is_or_between_values(): void
    {
        $this->product('Grohe A');
        $this->product('Hansgrohe B', ['brand' => 'Hansgrohe']);
        $this->product('Oras C', ['brand' => 'Oras']);

        $this->assertEqualsCanonicalizing(['Grohe A', 'Hansgrohe B'],
            $this->names('/catalog/smesiteli?brand[]=Grohe&brand[]=Hansgrohe'));
    }

    public function test_price_range_filter(): void
    {
        $this->product('Дешёвый', ['price' => 50]);
        $this->product('Средний', ['price' => 150]);
        $this->product('Дорогой', ['price' => 500]);
        $this->product('Без цены', ['price' => null]);

        $this->assertEqualsCanonicalizing(['Средний'], $this->names('/catalog/smesiteli?price[from]=100&price[to]=200'));
        $this->assertEqualsCanonicalizing(['Средний', 'Дорогой'], $this->names('/catalog/smesiteli?price[from]=100'));
        // «от» больше «до» — меняются местами
        $this->assertEqualsCanonicalizing(['Средний'], $this->names('/catalog/smesiteli?price[from]=200&price[to]=100'));
    }

    public function test_stock_filter_three_statuses(): void
    {
        $this->product('Есть', ['stock' => 3]);
        $this->product('Заказ', ['stock' => 0, 'on_order' => true]);
        $this->product('Нет', ['stock' => 0]);

        $this->assertSame(['Есть'], $this->names('/catalog/smesiteli?stock[]=in_stock'));
        $this->assertSame(['Заказ'], $this->names('/catalog/smesiteli?stock[]=on_order'));
        $this->assertSame(['Нет'], $this->names('/catalog/smesiteli?stock[]=out_of_stock'));
        $this->assertEqualsCanonicalizing(['Есть', 'Заказ'], $this->names('/catalog/smesiteli?stock[]=in_stock&stock[]=on_order'));

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertSee('data-stock="on_order"', false)
            ->assertSee('Под заказ');
    }

    public function test_text_attribute_filter(): void
    {
        $this->product('Хромовый', [], [$this->color->id => 'Хром']);
        $this->product('Белый', [], [$this->color->id => 'Белый']);
        $this->product('Чёрный', [], [$this->color->id => 'Чёрный']);

        $this->assertEqualsCanonicalizing(['Хромовый', 'Белый'],
            $this->names("/catalog/smesiteli?a[{$this->color->id}][]=Хром&a[{$this->color->id}][]=Белый"));
    }

    public function test_number_attribute_range_filter(): void
    {
        $this->product('Узкий', [], [$this->width->id => '100']);
        $this->product('Средний', [], [$this->width->id => '150,5']);
        $this->product('Широкий', [], [$this->width->id => '300']);
        $this->product('Без ширины');

        $this->assertEqualsCanonicalizing(['Средний', 'Широкий'], $this->names("/catalog/smesiteli?a[{$this->width->id}][from]=150"));
        $this->assertEqualsCanonicalizing(['Узкий', 'Средний'], $this->names("/catalog/smesiteli?a[{$this->width->id}][to]=200"));
    }

    // --- Комбинации ---

    public function test_and_between_filters_or_within_filter(): void
    {
        $this->product('Grohe хром', [], [$this->color->id => 'Хром']);
        $this->product('Grohe белый', [], [$this->color->id => 'Белый']);
        $this->product('Oras хром', ['brand' => 'Oras'], [$this->color->id => 'Хром']);
        $this->product('Hansgrohe хром', ['brand' => 'Hansgrohe'], [$this->color->id => 'Хром']);

        $c = $this->color->id;

        $this->assertEqualsCanonicalizing(['Grohe хром', 'Oras хром'],
            $this->names("/catalog/smesiteli?brand[]=Grohe&brand[]=Oras&a[{$c}][]=Хром"));

        $this->assertEqualsCanonicalizing(['Grohe хром', 'Grohe белый', 'Oras хром'],
            $this->names("/catalog/smesiteli?brand[]=Grohe&brand[]=Oras&a[{$c}][]=Хром&a[{$c}][]=Белый"));
    }

    public function test_filter_values_do_not_depend_on_other_selection(): void
    {
        $this->product('Grohe хром', [], [$this->color->id => 'Хром']);
        $this->product('Oras белый', ['brand' => 'Oras'], [$this->color->id => 'Белый']);

        // Выбран только Grohe — в «Цвете» всё равно оба значения, в «Производителе» — оба бренда
        $html = $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Grohe')->getContent();

        $this->assertMatchesRegularExpression('#name="a\['.$this->color->id.'\]\[\]"\s+value="Белый"#', $html);
        $this->assertMatchesRegularExpression('#name="brand\[\]"\s+value="Oras"#', $html);
        $this->assertMatchesRegularExpression('#name="brand\[\]"\s+value="Grohe"\s+checked#', $html);
    }

    public function test_chips_and_reset_filters(): void
    {
        $this->product('Grohe хром', [], [$this->color->id => 'Хром']);
        $c = $this->color->id;

        $html = $this->actingAs($this->client)
            ->get("/catalog/smesiteli?brand[]=Grohe&a[{$c}][]=Хром&sort=name_asc&page=1")
            ->assertOk()
            ->assertSee('Производитель: Grohe')
            ->assertSee('Цвет: Хром')
            ->assertSee('Сбросить фильтры')
            ->assertSee('1 товар')
            ->getContent();

        // Удаление одного чипа: остальное и сортировка остаются, страница — сброшена
        $this->assertStringContainsString(
            'href="/catalog/smesiteli?'.e(http_build_query(['a' => [$c => ['Хром']], 'sort' => 'name_asc'])).'"',
            $html
        );
        // «Сбросить фильтры» — без фильтров, сортировка остаётся (BR-022)
        $this->assertMatchesRegularExpression('#<a href="/catalog/smesiteli\?sort=name_asc"[^>]*data-reset-filters#', $html);
    }

    public function test_inactive_unpublished_and_other_categories_are_excluded(): void
    {
        $this->product('Видимый');
        $this->product('Скрытый', ['is_active' => false, 'brand' => 'HiddenBrand']);
        $this->product('Черновик', ['is_published' => false, 'brand' => 'DraftBrand']);
        $this->product('Чужой', ['category_id' => Category::create(['name' => 'Ванны', 'slug' => 'vanny'])->id, 'brand' => 'OtherBrand']);

        $this->assertSame(['Видимый'], $this->names('/catalog/smesiteli'));

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertDontSee('HiddenBrand')
            ->assertDontSee('DraftBrand')
            ->assertDontSee('OtherBrand');
    }

    // --- Настройка фильтров по категориям ---

    public function test_subcategory_inherits_parent_filters(): void
    {
        $this->product('Хромовый', [], [$this->color->id => 'Хром']);

        $this->actingAs($this->client)->get('/catalog/dlya-kuhni')
            ->assertSee('data-facet="attribute:'.$this->color->id.'"', false);

        $this->assertSame(['Хромовый'], $this->names("/catalog/dlya-kuhni?a[{$this->color->id}][]=Хром"));
    }

    public function test_own_settings_override_parent_and_order_is_kept(): void
    {
        $this->product('Хромовый', [], [$this->color->id => 'Хром']);
        app(CatalogFilters::class)->save($this->child, ["attribute:{$this->color->id}", 'brand']);

        $this->actingAs($this->client)->get('/catalog/dlya-kuhni')
            ->assertSeeInOrder(['data-facet="attribute:'.$this->color->id.'"', 'data-facet="brand"'], false)
            ->assertDontSee('data-facet="stock"', false);

        // Фильтр, которого нет в наборе, не применяется
        $this->assertSame(['Хромовый'], $this->names('/catalog/dlya-kuhni?stock[]=out_of_stock'));
    }

    public function test_without_settings_anywhere_standard_filters_are_used(): void
    {
        CategoryFilter::query()->delete();
        $this->product('Товар', ['stock' => 3]);

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            // «Только в наличии» — первым (макет Catalog), остальные — в порядке настройки
            ->assertSeeInOrder(['data-facet="stock"', 'data-facet="brand"', 'data-facet="price"'], false)
            ->assertDontSee('data-facet="attribute:', false);
    }

    public function test_attribute_not_filterable_anymore_is_dropped(): void
    {
        $this->product('Хромовый', [], [$this->color->id => 'Хром']);
        $this->color->update(['is_filterable' => false]);

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertDontSee('data-facet="attribute:'.$this->color->id.'"', false);
    }

    public function test_admin_configures_filters_with_order_and_inherit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/catalog-filters')
            ->assertOk()
            ->assertSee('Для кухни')
            ->assertSee('наследует от «Смесители»');

        $this->actingAs($admin)->post("/admin/catalog-filters/{$this->child->id}", [
            'mode' => 'own',
            'filters' => ['brand', "attribute:{$this->color->id}"],
            'order' => ['brand' => 2, "attribute:{$this->color->id}" => 1],
        ])->assertRedirect('/admin/catalog-filters');

        $this->assertSame(["attribute:{$this->color->id}", 'brand'],
            app(CatalogFilters::class)->own($this->child)->map->key()->all());

        $this->actingAs($admin)->post("/admin/catalog-filters/{$this->child->id}", ['mode' => 'inherit']);
        $this->assertTrue(app(CatalogFilters::class)->own($this->child)->isEmpty());

        $this->actingAs($this->client)->get('/admin/catalog-filters')->assertForbidden();
    }

    // --- Сортировка ---

    public function test_sort_by_name_both_directions(): void
    {
        $this->product('Бета');
        $this->product('Альфа');
        $this->product('Zeta', ['display_name' => 'Вега']);

        $this->assertSame(['Альфа', 'Бета', 'Вега'], $this->names('/catalog/smesiteli?sort=name_asc'));
        $this->assertSame(['Вега', 'Бета', 'Альфа'], $this->names('/catalog/smesiteli?sort=name_desc'));
    }

    public function test_sort_by_price_puts_products_without_price_last(): void
    {
        $this->product('Средний', ['price' => 200]);
        $this->product('Без цены', ['price' => null]);
        $this->product('Дешёвый', ['price' => 50]);
        $this->product('Дорогой', ['price' => 900]);

        $this->assertSame(['Дешёвый', 'Средний', 'Дорогой', 'Без цены'], $this->names('/catalog/smesiteli?sort=price_asc'));
        $this->assertSame(['Дорогой', 'Средний', 'Дешёвый', 'Без цены'], $this->names('/catalog/smesiteli?sort=price_desc'));
    }

    public function test_sort_by_popularity_counts_ordered_units_not_cancelled(): void
    {
        $rare = $this->product('Редкий');
        $hit = $this->product('Хит');
        $cancelledOnly = $this->product('Отменённый');
        $this->product('Без заказов');

        $order = fn (Product $p, int $qty, OrderStatus $status) => OrderItem::create([
            'order_id' => Order::create(['user_id' => $this->client->id, 'status' => $status])->id,
            'product_id' => $p->id,
            'quantity' => $qty,
            'price' => 100,
        ]);

        $order($hit, 10, OrderStatus::Completed);
        $order($hit, 5, OrderStatus::New);
        $order($rare, 2, OrderStatus::Shipped);
        $order($cancelledOnly, 100, OrderStatus::Cancelled);

        $names = $this->names('/catalog/smesiteli?sort=popular');

        $this->assertSame(['Хит', 'Редкий'], array_slice($names, 0, 2));
        $this->assertEqualsCanonicalizing(['Отменённый', 'Без заказов'], array_slice($names, 2));
    }

    public function test_sort_applies_after_filters_and_keeps_them(): void
    {
        $this->product('Grohe дорогой', ['price' => 900]);
        $this->product('Grohe дешёвый', ['price' => 10]);
        $this->product('Oras', ['brand' => 'Oras', 'price' => 1]);

        $this->assertSame(['Grohe дешёвый', 'Grohe дорогой'], $this->names('/catalog/smesiteli?brand[]=Grohe&sort=price_asc'));

        $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Grohe&sort=price_asc')
            ->assertSee('Производитель: Grohe')
            ->assertSee('<option value="price_asc" selected', false);
    }

    public function test_guest_has_no_price_filter_or_price_sort(): void
    {
        $this->product('Дорогой', ['price' => 900]);
        $this->product('Дешёвый', ['price' => 10]);

        $html = $this->get('/catalog/smesiteli?sort=price_asc&price[from]=500')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-facet="price"', $html);
        $this->assertStringNotContainsString('value="price_asc"', $html);
        // Параметр цены у гостя не действует
        $this->assertStringContainsString('2 товара', $html);
    }

    // --- Пагинация и возврат из карточки ---

    public function test_pagination_keeps_filters_and_sort(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->product(sprintf('Товар %02d', $i));
        }

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Grohe&sort=name_asc')->getContent();

        $this->assertStringContainsString(e('/catalog/smesiteli?'.http_build_query(['brand' => ['Grohe'], 'sort' => 'name_asc', 'page' => 2])), $html);

        $this->assertSame(['Товар 25', 'Товар 26', 'Товар 27', 'Товар 28', 'Товар 29', 'Товар 30'],
            $this->names('/catalog/smesiteli?brand[]=Grohe&sort=name_asc&page=2'));
    }

    public function test_product_back_link_returns_to_same_list_url(): void
    {
        $product = $this->product('Хромовый', [], [$this->color->id => 'Хром']);
        $url = '/catalog/smesiteli?brand%5B0%5D=Grohe&sort=name_asc&page=1';

        $this->actingAs($this->client)->get($url)->assertOk();

        $this->actingAs($this->client)->get("/product/{$product->id}")
            ->assertOk()
            // Возврат — по категории в хлебных крошках (макет Product)
            ->assertSee('data-back-to-list', false)
            ->assertSee('<a href="'.e($url).'"', false);
    }

    public function test_product_back_link_without_list_goes_to_category(): void
    {
        $product = $this->product('Хромовый');

        $this->actingAs($this->client)->get("/product/{$product->id}")
            ->assertSee('href="/catalog/dlya-kuhni"', false);
    }

    public function test_mobile_panel_button_shows_active_count(): void
    {
        $this->product('Grohe', [], [$this->color->id => 'Хром']);

        $this->actingAs($this->client)->get("/catalog/smesiteli?brand[]=Grohe&a[{$this->color->id}][]=Хром")
            ->assertSee('Фильтры (2)')
            ->assertSee('Показать товары')
            ->assertSee('aria-controls="catalog-filters-panel"', false);
    }

    // --- Без N+1 ---

    public function test_query_count_does_not_grow_with_products(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Grohe&a['.$this->color->id.'][]=Хром&sort=popular')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->product("A{$i}", [], [$this->color->id => 'Хром', $this->width->id => 10 * $i]);
        }
        // Первый запрос после добавления товаров перестраивает кэш меню — замер по второму
        $count();
        $few = $count();

        for ($i = 0; $i < 20; $i++) {
            $this->product("B{$i}", [], [$this->color->id => 'Хром', $this->width->id => 10 * $i]);
        }
        $count();
        $many = $count();

        $this->assertSame($few, $many);
    }
}
