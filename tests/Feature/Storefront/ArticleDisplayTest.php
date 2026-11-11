<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Артикул — то, что видят и ищут покупатели (products.article, без него — sku); SKU — внутренний
 * код обмена с 1С: на витрине, в корзине, заказе и выгрузке не показывается. Поиск и «Добавить
 * по артикулу» — по артикулу, sku и коду организации; артикул у нескольких брендов — выбор.
 */
class ArticleDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function product(string $sku, ?string $article = null, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => "Смеситель {$sku}", 'sku' => $sku, 'article' => $article, 'price' => 100, 'stock' => 10,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    private function quickAdd(array $data)
    {
        return $this->actingAs($this->client)->postJson('/order/quick-add', $data);
    }

    // --- Показ ---

    public function test_storefront_shows_article_instead_of_sku(): void
    {
        // Названия латиницей: SQLite в тестах не приводит кириллицу к нижнему регистру
        $product = $this->product('LEDEME-L4755-2', 'L4755-2', ['brand' => 'LEDEME', 'name' => 'Mixer kitchen']);
        $noArticle = $this->product('TIM-BL5800', null, ['brand' => 'TIM', 'name' => 'Mixer bath']);

        // Строка поиска: «Арт. L4755-2 · LEDEME»; без артикула — SKU
        $this->actingAs($this->client)->get('/search?q=mixer')->assertOk()
            ->assertSee('Арт. L4755-2')
            ->assertDontSee('LEDEME-L4755-2')
            ->assertSee('Арт. TIM-BL5800');

        $this->actingAs($this->client)->get("/product/{$product->id}")->assertOk()
            ->assertSee('Арт.')
            ->assertSee('L4755-2')
            ->assertDontSee('LEDEME-L4755-2');

        CartItem::create([
            'cart_id' => Cart::create(['user_id' => $this->client->id])->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'quantity' => 1, 'price_at_add' => 100, 'selected' => true,
        ]);
        $this->actingAs($this->client)->get('/order')->assertOk()
            ->assertSee('Арт. L4755-2')
            ->assertDontSee('LEDEME-L4755-2');

        $this->assertSame('TIM-BL5800', $noArticle->displayArticle());
    }

    public function test_order_snapshots_article_and_hides_sku_from_client(): void
    {
        $product = $this->product('LEDEME-L4755-2', 'L4755-2', ['name' => 'Смеситель кухонный']);
        ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $this->org->id, 'code' => 'WB-001']);
        $this->actingAs($this->client)->post("/order/add/{$product->id}", ['qty' => 1]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))->assertSessionHasNoErrors();

        $order = Order::sole();
        $item = $order->items()->sole();
        $this->assertSame(['LEDEME-L4755-2', 'L4755-2'], [$item->product_sku, $item->product_article]);

        // Снимок: артикул товара потом поменялся — в заказе прежний
        $product->update(['article' => 'L4755-3']);

        $this->actingAs($this->client)->get("/order/{$order->id}")->assertOk()
            ->assertSee('L4755-2')
            ->assertSee('Ваш код')
            ->assertDontSee('LEDEME-L4755-2')
            ->assertDontSee('L4755-3');

        // Админка заказа — и артикул, и SKU
        $this->actingAs(User::factory()->admin()->create())->get("/admin/order/{$order->id}")->assertOk()
            ->assertSee('L4755-2')
            ->assertSee('SKU: LEDEME-L4755-2');

        // Письмо администраторам — «Арт. … (SKU …, код клиента: …)»
        $mail = (new NewOrderNotification($order->fresh()))->toMail(User::factory()->admin()->create());
        $this->assertContains('Арт. L4755-2 (SKU LEDEME-L4755-2, код клиента: **WB-001**) — Смеситель кухонный × 1 шт.', $mail->introLines);
    }

    public function test_order_items_migration_backfills_article(): void
    {
        $product = $this->product('LEDEME-A1', 'A1');
        $order = Order::create(['user_id' => $this->client->id, 'organization_id' => $this->org->id, 'status' => 'new']);
        $item = $order->items()->create(['product_id' => $product->id, 'product_name' => 'x', 'product_sku' => 'LEDEME-A1', 'quantity' => 1, 'price' => 1]);
        $gone = $order->items()->create(['product_id' => null, 'product_name' => 'y', 'product_sku' => 'OLD-1', 'quantity' => 1, 'price' => 1]);
        DB::table('order_items')->update(['product_article' => null]);

        (require database_path('migrations/2026_11_14_100000_add_product_article_to_order_items.php'))->down();
        (require database_path('migrations/2026_11_14_100000_add_product_article_to_order_items.php'))->up();

        $this->assertSame('A1', $item->fresh()->product_article);
        $this->assertNull($gone->fresh()->product_article);
        $this->assertSame('OLD-1', $gone->fresh()->displayArticle());
    }

    // --- Админка ---

    public function test_admin_list_and_form_show_article_and_sku(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product('LEDEME-L4755-2', 'L4755-2');

        $this->actingAs($admin)->get('/admin/products?q=L4755')->assertOk()
            ->assertSeeInOrder(['Артикул', 'SKU'])
            ->assertSeeInOrder(['L4755-2', 'LEDEME-L4755-2']);

        $html = $this->actingAs($admin)->get("/admin/product/{$product->id}")->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-article"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-sku"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
    }

    // --- Поиск ---

    public function test_search_finds_by_article_sku_and_client_code(): void
    {
        $product = $this->product('LEDEME-L4755-2', 'L4755-2', ['name' => 'Смеситель кухонный']);
        ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $this->org->id, 'code' => 'WB-777']);
        $this->product('OTHER-1', 'X-1', ['name' => 'Другой']);

        foreach (['L4755-2', 'ledeme-l4755', 'WB-777'] as $query) {
            $this->actingAs($this->client)->get('/search?q='.urlencode($query))->assertOk()
                ->assertSee('Смеситель кухонный')
                ->assertDontSee('Другой');
        }

        $this->actingAs($this->client)->getJson('/search/suggest?q=L4755')
            ->assertJsonPath('products.0.article', 'L4755-2')
            ->assertJsonMissingPath('products.0.sku');

        // Чужой код не находится
        auth()->logout();
        $this->get('/search?q=WB-777')->assertDontSee('Смеситель кухонный');
    }

    public function test_search_suggests_variant_without_dashes_but_does_not_redirect(): void
    {
        $this->product('LEDEME-L4755-2', 'L4755-2', ['name' => 'Смеситель кухонный']);

        $this->actingAs($this->client)->get('/search?q=L47552')->assertOk()
            ->assertSee('Найдено товаров: 0')
            ->assertSee('Возможно, вы искали')
            ->assertSee('href="'.route('search.index', ['q' => 'L4755-2']).'"', false)
            ->assertDontSee('Смеситель кухонный');

        $this->getJson('/search/suggest?q=L47552')
            ->assertJsonCount(0, 'products')
            ->assertJsonPath('did_you_mean', ['L4755-2']);

        // Есть точные результаты — подсказки нет
        $this->get('/search?q=L4755-2')->assertDontSee('Возможно, вы искали');
    }

    // --- «Добавить по артикулу» ---

    public function test_quick_add_by_article_sku_and_client_code(): void
    {
        $product = $this->product('LEDEME-L4755-2', 'L4755-2');
        ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $this->org->id, 'code' => 'WB-001']);

        $this->quickAdd(['sku' => ' l4755-2 ', 'qty' => 1])->assertOk();
        $this->quickAdd(['sku' => 'LEDEME-L4755-2', 'qty' => 1])->assertOk();
        $this->quickAdd(['sku' => 'wb-001', 'qty' => 1])->assertOk();

        $this->assertSame(3, CartItem::sole()->quantity);
    }

    public function test_ambiguous_article_offers_choice_with_brand_and_adds_nothing(): void
    {
        $ledeme = $this->product('LEDEME-A-100', 'A-100', ['brand' => 'LEDEME', 'name' => 'Смеситель Ledeme']);
        $tim = $this->product('TIM-A-100', 'A-100', ['brand' => 'TIM', 'name' => 'Смеситель Tim']);

        $response = $this->quickAdd(['sku' => 'a-100', 'qty' => 2])->assertStatus(422)
            ->assertJsonPath('errors.sku', 'Артикул «a-100» есть у нескольких товаров — выберите нужный');
        $this->assertSame(0, CartItem::count());

        $html = $response->json('choices_html');
        $this->assertStringContainsString('name="product_id" value="'.$ledeme->id.'"', $html);
        $this->assertStringContainsString('name="product_id" value="'.$tim->id.'"', $html);
        $this->assertSame(1, preg_match('/LEDEME.*Арт\. A-100.*Смеситель Ledeme.*TIM.*Смеситель Tim/s', $html));

        // Выбор — добавляется именно выбранный
        $this->quickAdd(['sku' => 'a-100', 'qty' => 2, 'product_id' => $tim->id])->assertOk();
        $this->assertSame([$tim->id], CartItem::pluck('product_id')->all());

        // Без JS: выбор — на странице корзины в той же форме
        $this->actingAs($this->client)->from('/order')->post('/order/quick-add', ['sku' => 'A-100', 'layout' => 'strip'])
            ->assertRedirect('/order');
        $this->actingAs($this->client)->get('/order')->assertOk()
            ->assertSee('выберите нужный')
            ->assertSee('data-quick-choice="'.$ledeme->id.'"', false);
    }

    public function test_quick_add_not_found_hints_variant_without_dashes(): void
    {
        $this->product('LEDEME-L4755-2', 'L4755-2');

        $this->quickAdd(['sku' => 'L47552', 'qty' => 1])->assertStatus(422)
            ->assertJsonPath('errors.sku', 'Товар с артикулом «L47552» не найден. Возможно, вы искали L4755-2');

        $this->assertSame(0, CartItem::count());
    }
}
