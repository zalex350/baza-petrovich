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
 * Карточка товара по макету docs/design/Product: шапка товара, карточка цены и покупки,
 * ключевые характеристики, характеристики и описание без вкладок.
 */
class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $root = Category::create(['name' => 'Сантехника', 'slug' => 'santehnika']);
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli', 'parent_id' => $root->id]);

        $this->product = Product::create([
            'name' => 'Смеситель LEDEME L5069', 'sku' => 'L5069', 'brand' => 'LEDEME', 'price' => 576.58, 'stock' => 20,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true, 'is_hit' => true,
            'min_qty' => 2, 'qty_step' => 2, 'default_qty' => 2, 'description' => 'Описание смесителя',
        ]);
    }

    private function attribute(string $name, string $value, int $order, bool $filterable = true): ProductAttribute
    {
        $attribute = ProductAttribute::create(['name' => $name, 'is_filterable' => $filterable]);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $attribute->id, 'value' => $value, 'sort_order' => $order]);

        return $attribute;
    }

    public function test_header_badges_sku_copy_and_brand_link(): void
    {
        app(CatalogFilters::class)->save($this->category, ['brand', 'price']);

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSeeInOrder(['aria-label="Хлебные крошки"', 'Главная', 'Каталог', 'Сантехника', 'Смесители'], false)
            ->assertSee('data-product-badge="hit"', false)
            ->assertSee('<h1', false)
            ->assertSee('Артикул:')
            ->assertSee('aria-label="Скопировать артикул"', false)
            // Производитель — ссылка на товары бренда в категории (там есть фильтр по производителю)
            ->assertSee('href="/catalog/smesiteli?brand%5B0%5D=LEDEME"', false);
    }

    public function test_brand_link_falls_back_to_search_without_brand_filter(): void
    {
        app(CatalogFilters::class)->save($this->category, ['price']);

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertSee('href="/search?q=LEDEME"', false);
    }

    public function test_price_card_with_vat_stock_buy_and_qty_rules(): void
    {
        $html = $this->actingAs($this->client)->get("/product/{$this->product->id}")->assertOk()->getContent();

        $card = substr($html, strpos($html, 'data-buy-card'));

        $this->assertStringContainsString('Цена без НДС, за шт.', $card);
        $this->assertStringContainsString(Money::format(576.58), $card);
        $this->assertStringContainsString('с НДС 20% — '.Money::format(691.90), $card);
        $this->assertStringContainsString('data-stock=', $card);
        // «В корзину» — без перезагрузки, блок покупки — вариант карточки
        $this->assertStringContainsString('action="/order/add/'.$this->product->id.'"', $card);
        $this->assertStringContainsString('data-cart-add', $card);
        $this->assertStringContainsString('name="layout" value="card"', $card);
        $this->assertMatchesRegularExpression('#name="qty"[^>]*value="2"[^>]*min="2"[^>]*step="2"#s', $card);
        $this->assertStringContainsString('Мин. заказ 2 шт. · кратность 2 шт.', $card);
    }

    public function test_product_in_cart_shows_current_quantity_and_link(): void
    {
        $cart = Cart::create(['user_id' => $this->client->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => 4, 'price_at_add' => 576.58]);

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            // «В корзину» видна и для товара в корзине — добавляет к имеющемуся
            ->assertSee('action="/order/add/'.$this->product->id.'"', false)
            ->assertDontSee('action="/order/update/'.$item->id.'"', false)
            ->assertSee('В корзине: 4 шт.')
            ->assertSee('href="/order"', false);
    }

    public function test_add_from_card_returns_card_layout(): void
    {
        $html = $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 2, 'layout' => 'card'])
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('data-cart-add', $html);
        $this->assertStringContainsString('name="layout" value="card"', $html);
        $this->assertStringContainsString('В корзине: 2 шт.', $html);
        $this->assertStringContainsString('h-12', $html);
    }

    public function test_guest_sees_login_to_order(): void
    {
        $this->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Цена доступна после входа')
            ->assertSee('Войдите, чтобы заказать')
            ->assertSee(e(route('login', ['return' => "/product/{$this->product->id}"])), false)
            ->assertDontSee('data-cart-add', false);
    }

    public function test_key_rows_specs_and_description_without_tabs(): void
    {
        $color = $this->attribute('Цвет', 'Хром', 0);
        $this->attribute('Материал', 'Латунь', 1, false);
        $spout = $this->attribute('Тип излива', 'Гибкий', 2);
        $this->attribute('Гарантия', '5 лет', 3, false);
        $this->attribute('Подводка', 'Гибкая', 4, false);

        // Фильтры категории: тип излива, затем цвет — они первыми среди ключевых
        app(CatalogFilters::class)->save($this->category, ["attribute:{$spout->id}", "attribute:{$color->id}"]);

        $html = $this->actingAs($this->client)->get("/product/{$this->product->id}")->assertOk()->getContent();

        $key = substr($html, strpos($html, 'data-key-rows'), 2000);
        $this->assertSame(4, substr_count($key, 'text-ink-muted">'));
        $this->assertMatchesRegularExpression('#Тип излива.*Цвет.*Материал.*Гарантия#s', $key);
        $this->assertStringNotContainsString('Подводка', $key);

        $this->assertStringContainsString('href="#specs"', $html);
        $this->assertStringContainsString('id="specs"', $html);
        $this->assertSame(5, substr_count($html, 'data-spec-row'));
        $this->assertStringContainsString('Описание смесителя', $html);

        // Без вкладок
        $this->assertStringNotContainsString('showTab(', $html);
    }

    public function test_documents_block_hidden_without_documents(): void
    {
        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertDontSee('data-documents', false);
    }
}
