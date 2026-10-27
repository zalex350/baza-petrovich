<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Vat\VatCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * НДС везде (B2B): расчёт по позиции с округлением до копеек, итог — сумма позиций,
 * снимок в заказе, «Без НДС».
 */
class OrderVatTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->admin = User::factory()->admin()->create();
    }

    private function makeProduct(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => "Товар {$n}",
            'sku' => "VAT-{$n}",
            'price' => 100,
            'stock' => 50,
            'is_active' => true,
            'is_published' => true,
        ], $attributes));
    }

    private function add(Product $product, int $qty): void
    {
        $this->actingAs($this->client)
            ->post("/order/add/{$product->id}", ['qty' => $qty])
            ->assertSessionHas('success');
    }

    private function checkout(): Order
    {
        $this->actingAs($this->client)
            ->post('/order/checkout', $this->checkoutData($this->client))
            ->assertRedirectContains('/placed');

        return Order::with('items')->latest('id')->firstOrFail();
    }

    /**
     * Корзина с тремя ставками: 20%, 10% и «Без НДС».
     */
    private function placeMixedOrder(): Order
    {
        $this->add($this->makeProduct(['price' => 33.33, 'vat_rate' => 20]), 3);    // 99.99 + 20.00 = 119.99
        $this->add($this->makeProduct(['price' => 10.05, 'vat_rate' => 10]), 7);    // 70.35 + 7.04 = 77.39
        $this->add($this->makeProduct(['price' => 50, 'vat_rate' => null]), 2);     // 100.00 + 0 = 100.00

        return $this->checkout();
    }

    // --- Расчёт ---

    public function test_line_is_rounded_to_kopecks(): void
    {
        $line = app(VatCalculator::class)->line('33.33', 3, '20.00');

        $this->assertSame(99.99, $line->net);
        $this->assertSame(20.0, $line->vat);     // 19.998 → 20.00
        $this->assertSame(119.99, $line->gross);

        $line = app(VatCalculator::class)->line('10.05', 7, 10);

        $this->assertSame(70.35, $line->net);
        $this->assertSame(7.04, $line->vat);     // 7.035 → 7.04
        $this->assertSame(77.39, $line->gross);
    }

    public function test_total_is_sum_of_lines_not_vat_of_total(): void
    {
        $vat = app(VatCalculator::class);

        // По позиции: 0.03 × 20% = 0.006 → 0.01; от итога 0.06 × 20% было бы 0.012 → 0.01
        $total = $vat->total([$vat->line('0.03', 1, 20), $vat->line('0.03', 1, 20)]);

        $this->assertSame(0.06, $total->net);
        $this->assertSame(0.02, $total->vat);
        $this->assertSame(0.08, $total->gross);
    }

    public function test_no_vat_differs_from_zero_rate(): void
    {
        $vat = app(VatCalculator::class);

        $none = $vat->line(100, 2, null);
        $zero = $vat->line(100, 2, 0);

        $this->assertSame(0.0, $none->vat);
        $this->assertSame(200.0, $none->gross);
        $this->assertSame('Без НДС', $none->rateLabel());

        $this->assertSame(0.0, $zero->vat);
        $this->assertSame('0%', $zero->rateLabel());

        $this->assertSame('20%', VatCalculator::rateLabel('20.00'));
        $this->assertSame('10,5%', VatCalculator::rateLabel(10.5));
    }

    // --- Снимок в заказе ---

    public function test_order_stores_vat_snapshot_for_several_rates(): void
    {
        $order = $this->placeMixedOrder();

        $items = $order->items->sortBy('id')->values();

        $this->assertEquals([99.99, 70.35, 100.00], $items->pluck('amount_without_vat')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([20.00, 7.04, 0.00], $items->pluck('vat_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([119.99, 77.39, 100.00], $items->pluck('amount_with_vat')->map(fn ($v) => (float) $v)->all());
        $this->assertNull($items[2]->vat_rate);
        $this->assertSame('Без НДС', $items[2]->vat_rate_label);

        // Итог — сумма позиций
        $this->assertEquals(270.34, (float) $order->total_without_vat);
        $this->assertEquals(27.04, (float) $order->total_vat);
        $this->assertEquals(297.38, (float) $order->total_with_vat);
        $this->assertEquals(270.34, (float) $order->total);
    }

    public function test_snapshot_does_not_change_when_rate_or_price_changes(): void
    {
        $product = $this->makeProduct(['price' => 100, 'vat_rate' => 20]);
        $this->add($product, 2);
        $order = $this->checkout();

        $product->update(['vat_rate' => 10, 'price' => 150]);
        $product->update(['vat_rate' => null]);

        $item = $order->items()->sole();

        $this->assertEquals(20, $item->vat_rate);
        $this->assertEquals(100, $item->price);
        $this->assertEquals(40.00, (float) $item->vat_amount);
        $this->assertEquals(240.00, (float) $item->amount_with_vat);
        $this->assertEquals(240.00, (float) $order->fresh()->total_with_vat);

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSeeInOrder(['20%', "40,00\u{00A0}BYN", "240,00\u{00A0}BYN"]);
    }

    // --- Экраны ---

    public function test_cart_shows_vat_by_line_and_totals(): void
    {
        $this->add($this->makeProduct(['price' => 33.33, 'vat_rate' => 20]), 3);
        $this->add($this->makeProduct(['price' => 50, 'vat_rate' => null]), 2);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSeeInOrder(['Цена без НДС', 'Количество', 'Сумма без НДС'])
            // Строки: цена, сумма без НДС и «с НДС» — НДС по позиции
            ->assertSeeInOrder([Money::format(33.33), Money::format(99.99), 'с НДС '.Money::format(119.99, false)])
            ->assertSeeInOrder([Money::format(50), Money::format(100), 'с НДС '.Money::format(100, false)])
            // Панель: ставки разные — просто «НДС»
            ->assertSeeInOrder([
                'Сумма без НДС', Money::format(199.99),
                'НДС', Money::format(20),
                'Итого с НДС', Money::format(219.99),
            ]);
    }

    public function test_order_cards_show_vat_per_line_and_totals(): void
    {
        $order = $this->placeMixedOrder();

        foreach ([[$this->client, "/order/{$order->id}"], [$this->admin, "/admin/order/{$order->id}"]] as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSeeInOrder(['Цена без НДС', 'Сумма без НДС', 'Ставка НДС', 'Сумма НДС', 'Сумма с НДС'])
                ->assertSeeInOrder(["10,05\u{00A0}BYN", '7', "70,35\u{00A0}BYN", '10%', "7,04\u{00A0}BYN", "77,39\u{00A0}BYN"])
                ->assertSeeInOrder(["50,00\u{00A0}BYN", '2', "100,00\u{00A0}BYN", 'Без НДС', "0,00\u{00A0}BYN", "100,00\u{00A0}BYN"])
                ->assertSeeInOrder([
                    'Сумма без НДС:', "270,34\u{00A0}BYN",
                    'НДС:', "27,04\u{00A0}BYN",
                    'Сумма с НДС:', "297,38\u{00A0}BYN",
                ]);
        }
    }

    public function test_order_lists_have_vat_columns(): void
    {
        $order = $this->placeMixedOrder();

        // Кабинет и админка — x-price (1 234,50 BYN, неразрывные пробелы)
        $sums = [
            '/orders' => ["270,34\u{00A0}BYN", "27,04\u{00A0}BYN", "297,38\u{00A0}BYN"],
            '/admin/orders' => ["270,34\u{00A0}BYN", "27,04\u{00A0}BYN", "297,38\u{00A0}BYN"],
        ];

        foreach ([[$this->client, '/orders'], [$this->admin, '/admin/orders']] as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSeeInOrder(['Без НДС', 'НДС', 'С НДС'])
                ->assertSeeInOrder([$order->number, ...$sums[$url]]);
        }
    }

    public function test_success_page_shows_vat_totals(): void
    {
        $this->add($this->makeProduct(['price' => 33.33, 'vat_rate' => 20]), 3);

        $response = $this->actingAs($this->client)
            ->post('/order/checkout', $this->checkoutData($this->client));

        $order = Order::sole();
        $response->assertRedirect("/order/{$order->id}/placed");

        $this->actingAs($this->client)->get("/order/{$order->id}/placed")
            ->assertOk()
            ->assertSee("Заказ {$order->number} оформлен")
            ->assertSeeInOrder([
                'Сумма без НДС:', "99,99\u{00A0}BYN",
                'НДС:', "20,00\u{00A0}BYN",
                'Сумма с НДС:', "119,99\u{00A0}BYN",
            ]);

        // Чужой заказ — 404
        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($stranger)->get("/order/{$order->id}/placed")->assertNotFound();
    }

    public function test_storefront_shows_no_vat_product(): void
    {
        $product = $this->makeProduct(['name' => 'Grohe Без НДС', 'price' => 80, 'vat_rate' => null]);

        $this->actingAs($this->client)->get("/product/{$product->id}")
            ->assertOk()
            ->assertSeeInOrder(['Цена без НДС, за шт.', Money::format(80), 'НДС не облагается']);

        $this->actingAs($this->client)->get('/search?q=Grohe')
            ->assertOk()
            ->assertSeeInOrder([Money::format(80), 'без НДС · НДС не облагается']);
    }

    // --- Админка: ставка «Без НДС» ---

    public function test_admin_sets_no_vat_and_sees_it_selected(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin)->post("/admin/product/{$product->id}", [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => 100,
            'vat_rate' => 'none',
            'stock' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()->vat_rate);

        $this->actingAs($this->admin)->get("/admin/product/{$product->id}")
            ->assertOk()
            ->assertSee('<option value="none" selected', false);

        $this->actingAs($this->admin)->post('/admin/product', [
            'name' => 'Новый без НДС',
            'sku' => 'NEW-NONE',
            'price' => 10,
            'vat_rate' => 'none',
            'stock' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Product::where('sku', 'NEW-NONE')->value('vat_rate'));
    }

    // --- Миграция существующих заказов ---

    public function test_migration_fills_vat_for_existing_orders(): void
    {
        $migration = require database_path('migrations/2026_10_06_100000_add_vat_snapshot_to_orders.php');
        $migration->down();

        $product = DB::table('products')->insertGetId([
            'name' => 'Старый', 'sku' => 'OLD-1', 'price' => 10, 'vat_rate' => 10, 'stock' => 1,
            'is_active' => true, 'is_published' => true,
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->client->id, 'status' => 'new', 'total' => 0,
        ]);
        $emptyOrderId = DB::table('orders')->insertGetId([
            'user_id' => $this->client->id, 'status' => 'new', 'total' => 50,
        ]);

        DB::table('order_items')->insert([
            ['order_id' => $orderId, 'product_id' => null, 'product_name' => 'A', 'quantity' => 3, 'price' => '33.33', 'vat_rate' => 20],
            // Ставки нет в снимке — берётся ставка товара
            ['order_id' => $orderId, 'product_id' => $product, 'product_name' => 'B', 'quantity' => 7, 'price' => '10.05', 'vat_rate' => null],
        ]);

        $migration->up();

        $items = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get();

        $this->assertEquals([20, 10], $items->pluck('vat_rate')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([20.00, 7.04], $items->pluck('vat_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals([119.99, 77.39], $items->pluck('amount_with_vat')->map(fn ($v) => (float) $v)->all());

        $order = DB::table('orders')->find($orderId);
        $this->assertEquals(170.34, (float) $order->total_without_vat);
        $this->assertEquals(27.04, (float) $order->total_vat);
        $this->assertEquals(197.38, (float) $order->total_with_vat);

        // Заказ без позиций: итог — сумма позиций, то есть 0; старый total не трогается
        $empty = DB::table('orders')->find($emptyOrderId);
        $this->assertEquals(0, (float) $empty->total_with_vat);
        $this->assertEquals(50, (float) $empty->total);
    }
}
