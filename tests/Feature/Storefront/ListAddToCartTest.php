<?php

namespace Tests\Feature\Storefront;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «В корзину» в списках товаров: каталог, поиск, главная.
 */
class ListAddToCartTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->product = $this->makeProduct(['name' => 'Grohe Start', 'sku' => 'GR-001']);
    }

    private function makeProduct(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name'         => "Товар {$n}",
            'sku'          => "SKU-{$n}",
            'price'        => 100,
            'stock'        => 5,
            'category_id'  => $this->category->id,
            'is_active'    => true,
            'is_published' => true,
        ], $attributes));
    }

    /**
     * @return list<string>
     */
    private function listUrls(): array
    {
        return ['/catalog/smesiteli', '/search?q=Grohe', '/'];
    }

    public function test_client_sees_quantity_and_add_button_in_every_list(): void
    {
        foreach ($this->listUrls() as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee("action=\"/order/add/{$this->product->id}\"", false)
                ->assertSee('data-cart-add', false)
                ->assertSee('name="qty"', false)
                ->assertSee('В корзину')
                ->assertSee('Подробнее');
        }
    }

    public function test_guest_sees_login_link_with_return_to_current_page(): void
    {
        foreach ($this->listUrls() as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Войдите, чтобы заказать')
                ->assertSee(e(route('login', ['return' => $url])), false)
                ->assertDontSee("action=\"/order/add/{$this->product->id}\"", false);
        }
    }

    public function test_guest_returns_to_list_after_login(): void
    {
        $this->get('/login?return=' . urlencode('/catalog/smesiteli?page=2'))->assertOk();

        $this->post('/login', ['email' => $this->client->email, 'password' => 'password'])
            ->assertRedirect(url('/catalog/smesiteli?page=2'));
    }

    public function test_login_ignores_external_return_url(): void
    {
        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example'] as $return) {
            $this->get('/login?return=' . urlencode($return))->assertOk();

            $this->post('/login', ['email' => $this->client->email, 'password' => 'password'])
                ->assertRedirect('/');

            $this->post('/logout');
        }
    }

    public function test_admin_does_not_see_button_and_cannot_add(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ($this->listUrls() as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertDontSee("action=\"/order/add/{$this->product->id}\"", false)
                ->assertDontSee('Войдите, чтобы заказать');
        }

        $this->actingAs($admin)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertDontSee("action=\"/order/add/{$this->product->id}\"", false);

        $this->actingAs($admin)->from('/catalog/smesiteli')
            ->post("/order/add/{$this->product->id}", ['qty' => 1])
            ->assertRedirect('/catalog/smesiteli')
            ->assertSessionHas('error', 'Оформлять заказы могут только клиенты');

        $this->assertSame(0, CartItem::count());
    }

    public function test_product_without_stock_or_price_has_disabled_button(): void
    {
        $this->makeProduct(['name' => 'Grohe Пусто', 'stock' => 0]);
        $this->makeProduct(['name' => 'Grohe Запрос', 'price' => null]);

        $this->actingAs($this->client)->get('/search?q=Grohe')
            ->assertOk()
            ->assertSeeInOrder(['Grohe Запрос', 'Цена по запросу'])
            ->assertSeeInOrder(['Grohe Пусто', 'Нет в наличии']);

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli')->getContent();

        // Формы есть только у товара с ценой и остатком
        $this->assertSame(1, substr_count($html, 'data-cart-add'));
        $this->assertSame(2, substr_count($html, 'disabled'));
    }

    public function test_add_from_list_returns_to_same_page_with_filters(): void
    {
        $url = '/catalog/smesiteli?page=1&sort=price';

        $this->actingAs($this->client)->from($url)
            ->post("/order/add/{$this->product->id}", ['qty' => 3])
            ->assertRedirect($url)
            ->assertSessionHas('success', 'Товар добавлен в корзину');

        $this->assertSame(3, CartItem::sole()->quantity);
    }

    public function test_exceeding_stock_from_list_is_refused(): void
    {
        $this->actingAs($this->client)->from('/search?q=Grohe')
            ->post("/order/add/{$this->product->id}", ['qty' => 4])
            ->assertSessionHas('success');

        // В корзине 4 из 5 — ещё 2 нельзя
        $this->actingAs($this->client)->from('/search?q=Grohe')
            ->post("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertRedirect('/search?q=Grohe')
            ->assertSessionHas('error', 'Недостаточный остаток товара: доступно 5, в корзине уже 4');

        $this->assertSame(4, CartItem::sole()->quantity);
    }

    public function test_hidden_or_unpublished_product_cannot_be_added(): void
    {
        $hidden = $this->makeProduct(['is_active' => false]);
        $draft = $this->makeProduct(['is_published' => false]);

        foreach ([$hidden, $draft] as $product) {
            $this->actingAs($this->client)->from('/catalog/smesiteli')
                ->post("/order/add/{$product->id}", ['qty' => 1])
                ->assertSessionHas('error');
        }

        $this->assertSame(0, CartItem::count());
    }

    public function test_add_answers_json_for_fetch(): void
    {
        $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 1])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'Товар добавлен в корзину']);

        $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 10])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(1, CartItem::sole()->quantity);
    }
}
