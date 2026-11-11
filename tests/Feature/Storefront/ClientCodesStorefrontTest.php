<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Коды товаров клиента на витрине (docs/MODULES.md §11): «Ваш код» рядом с артикулом
 * в строке и плитке, карточке товара, корзине и заказе; поиск и подсказки по коду;
 * «Добавить по вашему коду» в корзине; снимок кода в позиции заказа.
 * Код видит только пользователь своей организации: гость, администратор и другие
 * организации — нет.
 */
class ClientCodesStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $client;

    private User $otherClient;

    private Product $a;

    private Product $b;

    private Product $c;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->client = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherClient = User::factory()->create(['organization_id' => $this->other->id]);

        $make = fn (string $sku, string $name, array $extra = []) => Product::create($extra + [
            'name' => $name, 'sku' => $sku, 'price' => 100, 'stock' => 50, 'brand' => 'Grohe',
            'category_id' => $category->id, 'is_active' => true, 'is_new' => true,
        ]);

        $this->a = $make('GR-001', 'Смеситель Grohe Start');
        $this->b = $make('HG-002', 'Смеситель Hansgrohe Logis');
        $this->c = $make('OR-003', 'Смеситель Oras Safira');

        // Кириллица в коде: поиск без учёта регистра должен работать и в SQLite
        $this->code($this->a, $this->org, 'КЛ-Альфа-7');
        $this->code($this->b, $this->org, 'kl-beta');
        $this->code($this->a, $this->other, 'CHUZHOY-1');
    }

    private function code(Product $product, Organization $org, string $code): ProductClientCode
    {
        return ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $org->id, 'code' => $code]);
    }

    /**
     * Число запросов к кодам за время $request; $pattern — только такие (например, выборка
     * кодов для товаров страницы).
     */
    private function codeQueries(callable $request, string $pattern = '/^select \* from "product_client_codes"/'): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $count = collect(DB::getQueryLog())->filter(fn ($query) => preg_match($pattern, $query['query']))->count();
        DB::disableQueryLog();

        return $count;
    }

    // --- 2. «Ваш код» рядом с артикулом ---

    public function test_client_sees_own_codes_in_list_tile_product_page_and_search(): void
    {
        foreach (['/catalog/smesiteli', '/catalog/smesiteli?view=grid', '/search?q=Grohe', '/', "/product/{$this->a->id}"] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('Ваш код')
                ->assertSee('КЛ-Альфа-7')
                ->assertDontSee('CHUZHOY-1');
        }

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli')->getContent();
        $this->assertSame(2, substr_count($html, 'data-client-code'), 'у товара без кода — только наш артикул');
        $this->assertStringContainsString('Арт. OR-003 · Grohe', $html);
    }

    public function test_other_organization_sees_only_its_codes(): void
    {
        $this->actingAs($this->otherClient)->get('/catalog/smesiteli')
            ->assertSee('CHUZHOY-1')
            ->assertDontSee('КЛ-Альфа-7')
            ->assertDontSee('kl-beta');
    }

    public function test_guest_admin_and_user_without_codes_see_no_codes_and_make_no_code_queries(): void
    {
        $admin = User::factory()->admin()->create();
        $noCodes = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        foreach ([null, $admin, $noCodes] as $user) {
            $user ? $this->actingAs($user) : auth()->logout();

            foreach (['/catalog/smesiteli', '/catalog/smesiteli?view=grid', "/product/{$this->a->id}"] as $url) {
                $this->get($url)->assertOk()->assertDontSee('data-client-code', false)->assertDontSee('КЛ-Альфа-7')->assertDontSee('CHUZHOY-1');
            }
        }

        auth()->logout();
        $this->assertSame(0, $this->codeQueries(fn () => $this->get('/catalog/smesiteli')));
    }

    public function test_codes_are_loaded_with_one_query_per_page(): void
    {
        // Ещё 20 товаров с кодами: число запросов к кодам не растёт с числом товаров
        for ($i = 1; $i <= 20; $i++) {
            $product = Product::create(['name' => "Смеситель доп {$i}", 'sku' => "EX-{$i}", 'brand' => 'Grohe', 'price' => 10, 'stock' => 1,
                'category_id' => $this->a->category_id, 'is_active' => true, 'is_new' => true]);
            $this->code($product, $this->org, "EXTRA-{$i}");
        }

        foreach (['/catalog/smesiteli', '/catalog/smesiteli?view=grid', '/search?q=Grohe'] as $url) {
            $this->assertSame(1, $this->codeQueries(fn () => $this->actingAs($this->client)->get($url)->assertSee('EXTRA-20')), $url);
        }

        // Главная: карусели и «Популярные» — одна выборка на всю страницу
        $this->assertSame(1, $this->codeQueries(fn () => $this->actingAs($this->client)->get('/')->assertOk()));
    }

    // --- 3. Поиск по коду ---

    public function test_search_finds_by_own_code_case_insensitive(): void
    {
        // Кириллица в другом регистре — сравнение по code_key, одинаково в MySQL и SQLite
        $this->actingAs($this->client)->get('/search?q='.urlencode('кл-альфа'))
            ->assertSee('Смеситель Grohe Start')
            ->assertDontSee('Смеситель Oras Safira');

        $this->actingAs($this->client)->get('/search?q=KL-BETA')
            ->assertSee('Смеситель Hansgrohe Logis')
            ->assertDontSee('Смеситель Grohe Start');
    }

    public function test_search_does_not_use_other_organizations_codes(): void
    {
        $this->actingAs($this->client)->get('/search?q=chuzhoy')->assertDontSee('Смеситель Grohe Start');
        $this->get('/search?q=chuzhoy')->assertDontSee('Смеситель Grohe Start');
        auth()->logout();
        $this->get('/search?q='.urlencode('кл-альфа'))->assertDontSee('Смеситель Grohe Start');

        $this->actingAs($this->otherClient)->get('/search?q=chuzhoy')->assertSee('Смеситель Grohe Start');
    }

    public function test_suggest_returns_own_code_and_puts_exact_code_first(): void
    {
        // «kl» есть в обоих кодах организации; точное совпадение кода — первым
        $products = $this->actingAs($this->client)->getJson('/search/suggest?q=KL-BETA')->assertOk()->json('products');

        $this->assertSame('HG-002', $products[0]['article']);
        $this->assertSame('kl-beta', $products[0]['client_code']);

        $products = collect($this->actingAs($this->client)->getJson('/search/suggest?q=Grohe')->json('products'))->keyBy('article');
        $this->assertSame('КЛ-Альфа-7', $products['GR-001']['client_code']);
        $this->assertNull($products['OR-003']['client_code']);

        // Чужой код: не находится и не отдаётся
        $this->assertSame([], $this->actingAs($this->client)->getJson('/search/suggest?q=chuzhoy')->json('products'));
        $this->assertSame('CHUZHOY-1', collect($this->actingAs($this->otherClient)->getJson('/search/suggest?q=Grohe')->json('products'))->keyBy('article')['GR-001']['client_code']);

        auth()->logout();
        $guest = collect($this->getJson('/search/suggest?q=Grohe')->json('products'))->keyBy('article');
        $this->assertNull($guest['GR-001']['client_code']);
        $this->assertSame([], $this->getJson('/search/suggest?q=kl-beta')->json('products'));
    }

    // --- 4. Корзина: «Добавить по вашему коду» ---

    public function test_cart_strip_by_code_only_when_organization_has_codes(): void
    {
        $this->actingAs($this->client)->get('/order')->assertSee('Добавить по вашему коду')->assertSee('Ваш код или артикул');

        $noCodes = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($noCodes)->get('/order')->assertSee('Добавить по артикулу')->assertDontSee('Добавить по вашему коду');
    }

    public function test_quick_add_by_own_code_or_sku(): void
    {
        $this->actingAs($this->client)->post('/order/quick-add', ['sku' => 'кл-альфа-7', 'qty' => 2])->assertRedirect('/order');
        $this->actingAs($this->client)->post('/order/quick-add', ['sku' => 'or-003', 'qty' => 1])->assertRedirect('/order');

        $items = $this->client->cart->items()->pluck('quantity', 'product_id')->all();
        $this->assertSame([$this->a->id => 2, $this->c->id => 1], $items);

        // Чужой код не принимается
        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => 'CHUZHOY-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.sku', 'Товар с кодом или артикулом «CHUZHOY-1» не найден');
    }

    public function test_cart_rows_show_own_code(): void
    {
        $this->actingAs($this->client)->post("/order/add/{$this->a->id}", ['qty' => 1]);
        $this->actingAs($this->client)->post("/order/add/{$this->c->id}", ['qty' => 1]);

        $html = $this->actingAs($this->client)->get('/order')->assertSee('КЛ-Альфа-7')->assertDontSee('CHUZHOY-1')->getContent();
        // Строка (xl) и карточка (уже xl) — по одному разу; у товара без кода — нет
        $this->assertSame(2, substr_count($html, 'data-client-code'));

        // Коды позиций — одной выборкой (отдельно от проверки «есть ли у организации коды»)
        $this->assertSame(1, $this->codeQueries(fn () => $this->actingAs($this->client)->get('/order'), '/product_client_codes.*"product_id" in/'));
    }

    // --- 5. Заказ: снимок кода ---

    public function test_checkout_saves_code_snapshot_and_shows_it_in_cabinet_admin_and_mail(): void
    {
        $this->actingAs($this->client)->post("/order/add/{$this->a->id}", ['qty' => 2]);
        $this->actingAs($this->client)->post("/order/add/{$this->c->id}", ['qty' => 1]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))->assertRedirectContains('/placed');

        $order = Order::where('user_id', $this->client->id)->firstOrFail();
        $this->assertSame([$this->a->id => 'КЛ-Альфа-7', $this->c->id => null], $order->items()->pluck('client_code', 'product_id')->all());

        // Снимок: правка кода после оформления заказ не меняет
        ProductClientCode::where('code', 'КЛ-Альфа-7')->first()->update(['code' => 'NEW-CODE']);

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()->assertSee('Ваш код')->assertSee('КЛ-Альфа-7')->assertDontSee('NEW-CODE');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get("/admin/order/{$order->id}")
            ->assertOk()->assertSee('Код клиента')->assertSee('КЛ-Альфа-7');

        $html = (string) (new NewOrderNotification($order))->toMail($admin)->render();
        $this->assertMatchesRegularExpression('~GR-001 \(код клиента: <strong[^>]*>КЛ-Альфа-7</strong>\) — Смеситель Grohe Start × 2 шт\.~u', $html);
        $this->assertStringContainsString('OR-003 — Смеситель Oras Safira × 1 шт.', $html);
    }

    public function test_order_of_other_organization_gets_its_own_code(): void
    {
        $this->actingAs($this->otherClient)->post("/order/add/{$this->a->id}", ['qty' => 1]);
        $this->actingAs($this->otherClient)->post('/order/checkout', $this->checkoutData($this->otherClient));

        $this->assertSame('CHUZHOY-1', OrderItem::firstOrFail()->client_code);
    }

    // --- code_key ---

    public function test_code_key_is_kept_in_sync(): void
    {
        $code = ProductClientCode::where('code', 'kl-beta')->first();
        $code->update(['code' => '  ЖЖ-Big ']);

        $this->assertSame('жж-big', $code->fresh()->code_key);
    }
}
