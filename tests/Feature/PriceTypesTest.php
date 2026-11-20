<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use App\Services\Pricing\PriceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\B2bPricing;
use Tests\TestCase;

/**
 * Типы цен (PriceType, ProductPrice, PriceContext, PriceTypes): «Применяемая цена» организации —
 * витрина, поиск, сортировка и фильтр, корзина, заказ, выгрузка ассортимента; запасная цена по
 * умолчанию; скрытый тип; админка (справочник, «по умолчанию», карточка клиента и товара).
 * Обмен с 1С по типам цен — Exchange\PriceTypesExchangeTest.
 */
class PriceTypesTest extends TestCase
{
    use B2bPricing;
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
    }

    private function type(string $code): PriceType
    {
        return PriceType::where('code', $code)->firstOrFail();
    }

    /** Товар с ценой по умолчанию (opt) и ценами других типов */
    private function product(string $sku, ?float $opt, array $prices = [], array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => "Смеситель {$sku}", 'sku' => $sku, 'price' => $opt, 'stock' => 10,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true]);

        foreach ($prices as $code => $price) {
            ProductPrice::create(['product_id' => $product->id, 'price_type_id' => $this->type($code)->id, 'price' => $price]);
        }

        return $product;
    }

    private function client(?string $code = null): User
    {
        $organization = Organization::factory()->create(['price_type_id' => $code ? $this->type($code)->id : null]);

        return User::factory()->create(['organization_id' => $organization->id]);
    }

    // --- Справочник и данные ---

    public function test_initial_types_and_default_row_follows_product_price(): void
    {
        $this->assertSame(
            [['opt', 'Оптовая', true], ['retail', 'Розничная', false], ['special', 'Специальная', false], ['vip', 'VIP', false]],
            PriceType::query()->ordered()->get()->map(fn ($t) => [$t->code, $t->name, $t->is_default])->all()
        );

        $product = $this->product('A-1', 100);
        $row = fn () => ProductPrice::where('product_id', $product->id)->where('price_type_id', $this->type('opt')->id)->value('price');
        $this->assertEquals(100, $row());

        $product->update(['price' => 120.5]);
        $this->assertEquals(120.5, $row());

        $product->update(['price' => null]);
        $this->assertNull($row());
    }

    // --- Витрина ---

    public function test_client_sees_price_of_own_type_with_fallback_to_default(): void
    {
        $both = $this->product('BOTH-1', 100, ['retail' => 130]);
        $optOnly = $this->product('OPT-1', 200);
        $retailOnly = $this->product('RET-1', null, ['retail' => 70]);
        $none = $this->product('NONE-1', null);

        $retail = $this->client('retail');
        $default = $this->client();

        $both->update(['is_hit' => true]); // «Популярные товары» на главной — хиты
        $this->assertSame(130.0, (float) $this->actingAs($retail)->get('/')->getOriginalContent()->getData()['featuredProducts']->firstWhere('id', $both->id)->price);

        $html = $this->actingAs($retail)->get('/catalog/mixers')->assertOk()->getContent();
        $this->assertStringContainsString("130,00\u{00A0}BYN", $html);
        $this->assertStringContainsString("200,00\u{00A0}BYN", $html, 'нет цены типа — цена по умолчанию');
        $this->assertStringContainsString("70,00\u{00A0}BYN", $html);
        $this->assertStringContainsString('Цена уточняется', $html);
        $this->assertStringNotContainsString('Розничная', $html, 'клиент не видит название типа');

        $this->actingAs($retail)->get("/product/{$both->id}")->assertSee("130,00\u{00A0}BYN")->assertDontSee('Розничная');
        $this->actingAs($default)->get("/product/{$both->id}")->assertSee("100,00\u{00A0}BYN")->assertDontSee("130,00\u{00A0}BYN");
        $this->actingAs($default)->get("/product/{$retailOnly->id}")->assertSee('Цена уточняется');

        $suggest = $this->actingAs($retail)->getJson('/search/suggest?q=BOTH')->json('products.0.price');
        $this->assertSame('130.00', $suggest);
    }

    public function test_hidden_type_is_not_applied(): void
    {
        $product = $this->product('A-1', 100, ['vip' => 90]);
        $this->type('vip')->update(['is_hidden' => true]);

        $this->actingAs($this->client('vip'))->get("/product/{$product->id}")->assertSee("100,00\u{00A0}BYN")->assertDontSee("90,00\u{00A0}BYN");
    }

    public function test_sort_filter_and_facet_use_client_price(): void
    {
        $a = $this->product('A-1', 100, ['retail' => 300]);
        $b = $this->product('B-1', 200);
        $c = $this->product('C-1', 300, ['retail' => 50]);
        $retail = $this->client('retail');
        app(CatalogFilters::class)->save($this->category, ['price']);

        $ids = fn ($response) => $response->assertOk()->getOriginalContent()->getData()['products']->pluck('id')->all();

        $this->assertSame([$c->id, $b->id, $a->id], $ids($this->actingAs($retail)->get('/catalog/mixers?sort=price_asc')));
        $this->assertSame([$a->id, $b->id, $c->id], $ids($this->actingAs($this->client())->get('/catalog/mixers?sort=price_asc')));
        $this->assertSame([$b->id], $ids($this->actingAs($retail)->get('/catalog/mixers?price[from]=150&price[to]=250')));

        $this->assertSame([$a->id, $b->id, $c->id], $ids($this->actingAs($retail)->get('/search?q=Смеситель&sort=price_desc')));
    }

    public function test_cart_and_order_use_client_price(): void
    {
        $product = $this->product('A-1', 100, ['special' => 80]);
        $client = $this->client('special');

        $this->actingAs($client)->from('/catalog')->post("/order/add/{$product->id}", ['qty' => 2])->assertSessionHasNoErrors();
        $this->assertEquals(80, $client->cart->items()->first()->price_at_add);
        $this->actingAs($client)->get('/order')->assertSee("160,00\u{00A0}BYN");

        $this->actingAs($client)->from('/order')->post('/order/checkout', $this->checkoutData($client))->assertSessionHasNoErrors();
        $this->assertEquals(80, Order::firstOrFail()->items()->first()->price);
    }

    public function test_assortment_export_uses_organization_price(): void
    {
        $product = $this->product('A-1', 100, ['vip' => 90]);
        $client = $this->client('vip');
        ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $client->organization_id, 'code' => 'K-1']);

        $response = $this->actingAs($client)->get('/account/assortment/download')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        $line = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'K-1');
        $this->assertEquals(90, $line[4]);
        $this->assertEquals(108, $line[5]);
    }

    public function test_effective_price_model_cannot_be_saved_and_admin_sees_default(): void
    {
        $product = $this->product('A-1', 100, ['retail' => 130]);

        $loaded = app(PriceContext::class)->using($this->type('retail')->id, fn () => Product::query()->withEffectivePrice()->find($product->id));
        $this->assertEquals(130, $loaded->price);
        $this->expectException(LogicException::class);
        $loaded->save();
    }

    // --- Админка ---

    public function test_admin_pages_ignore_admin_own_organization_type(): void
    {
        $product = $this->product('A-1', 100, ['retail' => 130]);
        $admin = User::factory()->admin()->create(['organization_id' => Organization::factory()->create(['price_type_id' => $this->type('retail')->id])->id]);

        $this->actingAs($admin)->get("/admin/product/{$product->id}")->assertOk()
            ->assertSee('value="100', false)->assertDontSee('value="130', false)
            ->assertSee('data-type-prices', false)->assertSee('Розничная');
    }

    public function test_admin_manages_types_and_default(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->product('A-1', 100, ['retail' => 130]);
        $b = $this->product('B-1', 200);

        $this->actingAs($admin)->get('/admin/price-types')->assertOk()
            ->assertSee('data-price-type="opt"', false)->assertSee('price_retail');

        $this->actingAs($admin)->post('/admin/price-types', ['code' => 'Dealer', 'name' => 'Дилерская', 'is_hidden' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->type('dealer')->is_hidden);
        $this->actingAs($admin)->post('/admin/price-types', ['code' => 'опт', 'name' => 'x'])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/price-types', ['code' => 'opt', 'name' => 'x'])->assertSessionHasErrors('code');

        $this->actingAs($admin)->post("/admin/price-type/{$this->type('opt')->id}", ['name' => 'Опт', 'is_hidden' => '1'])->assertSessionHasErrors('is_hidden');
        $this->actingAs($admin)->post("/admin/price-type/{$this->type('dealer')->id}/default")->assertSessionHasErrors('default');

        // Розничная — по умолчанию: products.price — розничные цены, без розничной — «Цена уточняется»
        $this->actingAs($admin)->post("/admin/price-type/{$this->type('retail')->id}/default")->assertSessionHasNoErrors();
        $this->assertSame(['retail'], PriceType::where('is_default', true)->pluck('code')->all());
        $this->assertEquals(130, $a->fresh()->getRawOriginal('price'));
        $this->assertNull($b->fresh()->getRawOriginal('price'));
        $this->assertEquals(100, ProductPrice::where('product_id', $a->id)->where('price_type_id', $this->type('opt')->id)->value('price'), 'оптовые цены остаются');

        $this->actingAs(User::factory()->create())->get('/admin/price-types')->assertForbidden();
    }

    public function test_admin_sets_organization_price_type(): void
    {
        $admin = User::factory()->admin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($admin)->get("/admin/organization/{$organization->id}/edit")->assertOk()
            ->assertSee('data-price-type', false)->assertSee('По умолчанию (Оптовая)');

        $this->actingAs($admin)->post("/admin/organization/{$organization->id}", ['name' => $organization->name, 'price_type_id' => $this->type('vip')->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->type('vip')->id, $organization->fresh()->price_type_id);
        $this->actingAs($admin)->get("/admin/organization/{$organization->id}")->assertSee('VIP (vip)');

        $this->actingAs($admin)->post("/admin/organization/{$organization->id}", ['name' => $organization->name, 'price_type_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($organization->fresh()->price_type_id);
        $this->actingAs($admin)->post("/admin/organization/{$organization->id}", ['name' => $organization->name, 'price_type_id' => 999])->assertSessionHasErrors('price_type_id');
    }
}
