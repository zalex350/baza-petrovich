<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корзина: «← Продолжить покупки» над заголовком — на последнюю категорию или поиск,
 * иначе в каталог; пустая корзина — «Перейти в каталог».
 */
class ContinueShoppingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        $product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 5,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
        ]);

        $cart = Cart::create(['user_id' => $this->client->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'price_at_add' => 100]);
    }

    private function continueHref(): string
    {
        $html = $this->actingAs($this->client)->get('/order')->assertOk()->getContent();

        preg_match('#<a href="([^"]*)"[^>]*data-continue-shopping#', $html, $m);

        return html_entity_decode($m[1] ?? '');
    }

    public function test_link_is_above_heading_and_defaults_to_catalog(): void
    {
        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSeeInOrder(['← Продолжить покупки', '<h1', 'Корзина'], false);

        $this->assertSame('/catalog', $this->continueHref());
    }

    public function test_link_returns_to_last_category_with_filters(): void
    {
        $this->actingAs($this->client)->get('/catalog/smesiteli?brand[]=Grohe&sort=name_asc')->assertOk();

        $this->assertSame('/catalog/smesiteli?brand[]=Grohe&sort=name_asc', urldecode($this->continueHref()));
    }

    public function test_link_returns_to_last_search(): void
    {
        $this->actingAs($this->client)->get('/catalog/smesiteli')->assertOk();
        $this->actingAs($this->client)->get('/search?q=Grohe')->assertOk();

        $this->assertSame('/search?q=Grohe', $this->continueHref());
    }

    public function test_foreign_url_in_session_is_ignored(): void
    {
        $this->withSession(['shop.continue_url' => 'https://evil.example/']);

        $this->assertSame('/catalog', $this->continueHref());
    }

    public function test_empty_cart_offers_catalog_button(): void
    {
        CartItem::query()->delete();

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Корзина пуста')
            ->assertSee('data-empty-state', false)
            ->assertSee('Перейти в каталог')
            ->assertSee('href="'.route('catalog.index').'"', false);
    }
}
