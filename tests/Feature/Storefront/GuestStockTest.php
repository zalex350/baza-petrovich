<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\B2bPricing;
use Tests\TestCase;

/**
 * Гость (не вошёл) не видит наличия — ни «Много / Мало / N шт.», ни «Под заказ», ни «Нет в наличии»:
 * каталог (строки и плитка), карточка товара, поиск и подсказки, главная, /promo, /hits, /new.
 * Вместо цены и наличия — «Цена и наличие — после входа» и «Войдите, чтобы заказать».
 * Фильтр «Наличие» гостю не показывается, его параметр в URL игнорируется.
 */
class GuestStockTest extends TestCase
{
    use B2bPricing;
    use RefreshDatabase;

    private const GUEST_LINE = 'Цена и наличие — после входа';

    /** Подписи и разметка наличия (x-stock) */
    private const STOCK_MARKERS = ['data-stock=', 'Много', 'Мало', '7 шт.', 'Под заказ', 'Нет в наличии', 'В наличии'];

    private Category $category;

    private Product $many;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        app(CatalogFilters::class)->save($this->category, ['brand', 'price', 'stock']);

        $flags = ['is_promo' => true, 'is_hit' => true, 'is_new' => true];
        $this->many = $this->product('Mixer Many', ['stock' => 500] + $flags);
        $this->product('Mixer Few', ['stock' => 7] + $flags);
        $this->product('Mixer Order', ['stock' => 0, 'on_order' => true] + $flags);
        $this->product('Mixer None', ['stock' => 0] + $flags);
    }

    private function product(string $name, array $attributes): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + [
            'name' => $name, 'sku' => "MX-{$n}", 'brand' => 'Grohe', 'price' => 100,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        return [
            'catalog list' => '/catalog/smesiteli',
            'catalog grid' => '/catalog/smesiteli?view=grid',
            'product' => '/product/'.$this->many->id,
            'search' => '/search?q=mixer',
            'home' => '/',
            'promo' => '/promo',
            'hits' => '/hits',
            'new' => '/new',
        ];
    }

    private function assertNoStock(string $html, string $page): void
    {
        foreach (self::STOCK_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $html, "{$page}: «{$marker}»");
        }
    }

    public function test_guest_sees_no_stock_anywhere(): void
    {
        foreach ($this->pages() as $page => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Mixer Many', $html, $page);
            $this->assertNoStock($html, $page);
            $this->assertStringContainsString(self::GUEST_LINE, $html, $page);
            $this->assertStringContainsString('Войдите, чтобы заказать', $html, $page);
        }
    }

    public function test_client_still_sees_stock(): void
    {
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        foreach ($this->pages() as $page => $url) {
            $html = $this->actingAs($client)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-stock=', $html, $page);
            $this->assertStringNotContainsString(self::GUEST_LINE, $html, $page);
        }

        $this->actingAs($client)->get('/catalog/smesiteli')
            ->assertSee('Много')->assertSee('7 шт.')->assertSee('Под заказ')->assertSee('Нет в наличии');
    }

    public function test_guest_has_no_stock_filter_and_its_parameter_is_ignored(): void
    {
        $html = $this->get('/catalog/smesiteli?stock[]=in_stock')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-facet="stock"', $html);
        $this->assertStringNotContainsString('Только в наличии', $html);
        // Фильтр не применён: товары без остатка в списке — иначе по нему видно наличие
        $this->assertStringContainsString('Mixer None', $html);
        $this->assertStringContainsString('Mixer Order', $html);

        $this->get('/promo?stock[]=out_of_stock')->assertOk()->assertSee('Mixer Many');

        // У клиента фильтр работает
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($client)->get('/catalog/smesiteli?stock[]=in_stock')->assertOk()
            ->assertSee('data-facet="stock"', false)
            ->assertSee('Mixer Many')
            ->assertDontSee('Mixer None');
    }

    public function test_suggest_json_has_no_stock_for_guest(): void
    {
        $response = $this->getJson('/search/suggest?q=mixer')->assertOk()
            ->assertJsonPath('guest', true)
            ->assertJsonCount(4, 'products');

        foreach ($response->json('products') as $product) {
            $this->assertNull($product['price']);
            foreach (array_keys($product) as $key) {
                $this->assertDoesNotMatchRegularExpression('/stock|order|avail/i', $key);
            }
        }

        $json = $response->getContent();
        foreach (['500', 'on_order', 'in_stock', 'Под заказ', 'Нет в наличии'] as $marker) {
            $this->assertStringNotContainsString($marker, $json, $marker);
        }
    }
}
