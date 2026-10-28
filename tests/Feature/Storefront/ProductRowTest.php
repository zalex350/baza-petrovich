<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Строка товара x-product-row в каталоге и поиске (макет docs/design/Catalog):
 * характеристики, цена, «В корзине · перейти», изменение количества без перезагрузки.
 */
class ProductRowTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    private Product $product;

    private ProductAttribute $color;

    private ProductAttribute $width;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.stock_many' => 50, 'shop.stock_few' => 5]);

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->color = ProductAttribute::create(['name' => 'Цвет', 'is_filterable' => true]);
        $this->width = ProductAttribute::create(['name' => 'Ширина', 'unit' => 'мм', 'type' => 'number', 'is_filterable' => true]);
        $material = ProductAttribute::create(['name' => 'Материал', 'is_filterable' => true]);

        // Ширина — раньше цвета: характеристики в строке идут в порядке фильтров категории
        app(CatalogFilters::class)->save($this->category, ['brand', 'stock', "attribute:{$this->width->id}", "attribute:{$this->color->id}"]);

        $this->product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'brand' => 'Grohe', 'price' => 100, 'stock' => 20,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true, 'is_hit' => true,
        ]);

        foreach ([[$this->color, 'Хром'], [$material, 'Латунь'], [$this->width, '150']] as $i => [$attribute, $value]) {
            ProductAttributeValue::create([
                'product_id' => $this->product->id, 'attribute_id' => $attribute->id,
                'value' => $value, 'value_number' => ProductAttribute::parseNumber($value), 'sort_order' => $i,
            ]);
        }
    }

    public function test_row_shows_badges_sku_brand_key_attributes_stock_and_price(): void
    {
        foreach (['/catalog/smesiteli', '/search?q=Grohe'] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('data-product-row', false)
                ->assertSee('data-product-badge="hit"', false)
                ->assertSee('Арт. GR-001 · Grohe')
                // Только фильтруемые характеристики категории, в порядке фильтров; «Материал» — не фильтр
                ->assertSee('150 мм · Хром')
                ->assertDontSee('Латунь')
                ->assertSee('data-stock="few"', false)
                ->assertSee(Money::format(100))
                ->assertSeeInOrder(['без НДС', 'с НДС '.Money::format(120, false)])
                // Наличие | цена | покупка — колонки фиксированной ширины
                ->assertSee('md:grid-cols-product-row', false)
                ->assertSee('md:grid-cols-product-buy', false);
        }
    }

    public function test_product_in_cart_keeps_add_button_and_shows_quantity_in_cart(): void
    {
        $cart = Cart::create(['user_id' => $this->client->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 3, 'price_at_add' => 100]);

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertOk()
            ->assertSee('action="/order/add/'.$this->product->id.'"', false)
            ->assertDontSee('action="/order/update/'.$item->id.'"', false)
            ->assertSee('В корзине: 3 шт.')
            ->getContent();

        // Место под «В корзине: N шт.» — в каждой строке
        $this->assertSame(substr_count($html, 'data-product-row'), substr_count($html, 'data-in-cart-slot'));
    }

    public function test_add_from_row_adds_to_quantity_in_cart(): void
    {
        $cart = Cart::create(['user_id' => $this->client->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 1, 'price_at_add' => 100]);

        $html = $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertOk()
            ->json('html');

        $this->assertSame(3, CartItem::sole()->quantity);
        $this->assertStringContainsString('В корзине: 3 шт.', $html);
        $this->assertStringContainsString('action="/order/add/'.$this->product->id.'"', $html);
    }

    public function test_add_returns_in_cart_block_and_header_summary(): void
    {
        $response = $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertOk()
            ->assertJsonPath('cart.count', 1)
            ->assertJsonPath('cart.total_text', Money::format(240))
            ->assertJsonPath('cart.label', 'Корзина: 1 поз.');

        $item = CartItem::sole();

        $this->assertStringContainsString('action="/order/add/'.$this->product->id.'"', $response->json('html'));
        $this->assertStringContainsString('В корзине: '.$item->quantity.' шт.', $response->json('html'));
        $this->assertStringContainsString('Товар добавлен в корзину', $response->json('toast'));
    }

    public function test_update_from_list_answers_json(): void
    {
        $cart = Cart::create(['user_id' => $this->client->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 1, 'price_at_add' => 100]);

        $this->actingAs($this->client)
            ->postJson("/order/update/{$item->id}", ['quantity' => 4])
            ->assertOk()
            ->assertJson(['ok' => true, 'quantity' => 4])
            ->assertJsonPath('cart.total_text', Money::format(480));

        // Больше остатка — значение не применяется (не урезается до остатка), «Доступно N шт.» (SRS §7.11)
        $this->actingAs($this->client)
            ->postJson("/order/update/{$item->id}", ['quantity' => 99])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'quantity' => 4, 'message' => 'Доступно '.$this->product->stock.' шт.']);

        $this->assertSame(4, $item->refresh()->quantity);

        // Обычная форма — как раньше, назад
        $this->actingAs($this->client)->from('/catalog/smesiteli')
            ->post("/order/update/{$item->id}", ['quantity' => 2])
            ->assertRedirect('/catalog/smesiteli');
    }

    public function test_qty_follows_product_order_rules(): void
    {
        $this->product->update(['min_qty' => 2, 'qty_step' => 2, 'default_qty' => 4]);

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli')->getContent();

        $this->assertMatchesRegularExpression('#id="qty-'.$this->product->id.'"[^>]*name="qty"[^>]*value="4"[^>]*min="2"[^>]*step="2"#s', $html);
    }

    public function test_empty_catalog_uses_empty_state(): void
    {
        $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Нет')
            ->assertOk()
            ->assertSee('data-empty-state', false)
            ->assertSee('Товаров не найдено')
            ->assertSee('Сбросить фильтры');
    }

    public function test_breadcrumbs_and_count_in_heading(): void
    {
        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Хлебные крошки"', 'Главная', 'Каталог', 'Смесители'], false)
            ->assertSee('1 товар');
    }
}
