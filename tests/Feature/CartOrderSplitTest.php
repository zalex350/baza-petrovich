<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Корзина (carts / cart_items) отделена от заказа (orders / order_items).
 */
class CartOrderSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->product = $this->product('GR-001', ['name' => 'Grohe Start', 'vat_rate' => 20]);
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => "Смеситель {$sku}",
            'sku' => $sku,
            'price' => 100,
            'stock' => 10,
            'is_active' => true,
            'is_published' => true,
        ], $attributes));
    }

    private function addToCart(Product $product, int $qty = 1, ?User $user = null): void
    {
        $this->actingAs($user ?? $this->client)
            ->post("/order/add/{$product->id}", ['qty' => $qty])
            ->assertSessionHas('success');
    }

    private function checkout(?User $user = null): Order
    {
        $user ??= $this->client;

        $this->actingAs($user)->post('/order/checkout', $this->checkoutData($user))->assertRedirectContains('/placed');

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    // --- Корзина ---

    public function test_add_creates_cart_and_does_not_create_order(): void
    {
        $this->addToCart($this->product, 2);

        $this->assertSame(0, Order::count());

        $item = $this->client->cart->items()->sole();
        $this->assertSame($this->product->id, $item->product_id);
        $this->assertSame(2, $item->quantity);
        $this->assertEquals(100, $item->price_at_add);
    }

    public function test_repeated_add_increases_quantity_of_single_position(): void
    {
        $this->addToCart($this->product, 2);
        $this->addToCart($this->product, 3);

        $this->assertSame(1, Cart::count());
        $this->assertSame(1, CartItem::count());
        $this->assertSame(5, CartItem::sole()->quantity);
    }

    public function test_cart_item_is_unique_per_cart_and_product(): void
    {
        $this->addToCart($this->product);

        $this->expectException(UniqueConstraintViolationException::class);

        CartItem::create([
            'cart_id' => $this->client->cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price_at_add' => 100,
        ]);
    }

    public function test_user_has_single_cart(): void
    {
        $this->addToCart($this->product);

        $this->expectException(UniqueConstraintViolationException::class);

        Cart::create(['user_id' => $this->client->id]);
    }

    public function test_cart_page_shows_items_and_total(): void
    {
        $this->addToCart($this->product, 2);
        $this->addToCart($this->product('GR-002', ['price' => 50.5]), 1);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('Смеситель GR-002')
            // Панель «Ваш заказ»
            ->assertSeeInOrder(['Позиций / штук', '2 / 3', 'Сумма без НДС', Money::format(250.50)]);
    }

    public function test_update_and_delete_work_with_cart_items(): void
    {
        $this->addToCart($this->product, 2);
        $item = CartItem::sole();

        $this->actingAs($this->client)
            ->post("/order/update/{$item->id}", ['quantity' => 4])
            ->assertSessionHas('success');
        $this->assertSame(4, $item->fresh()->quantity);

        $this->actingAs($this->client)
            ->post("/order/update/{$item->id}", ['quantity' => 11])
            ->assertSessionHas('error');
        $this->assertSame(4, $item->fresh()->quantity);

        $this->actingAs($this->client)
            ->post("/order/delete/{$item->id}")
            ->assertSessionHas('success');
        $this->assertSame(0, CartItem::count());
    }

    public function test_foreign_cart_item_is_forbidden(): void
    {
        $this->addToCart($this->product, 2);
        $item = CartItem::sole();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post("/order/update/{$item->id}", ['quantity' => 5])->assertForbidden();
        $this->actingAs($stranger)->post("/order/delete/{$item->id}")->assertForbidden();

        $this->assertSame(2, $item->fresh()->quantity);
    }

    // --- Удалённый товар ---

    public function test_cart_with_deleted_product_opens_and_item_can_be_removed(): void
    {
        $other = $this->product('GR-002', ['price' => 30]);

        $this->addToCart($this->product, 2);
        $this->addToCart($other, 1);

        $this->product->delete();

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Товар отсутствует в каталоге')
            ->assertSee('Grohe Start')
            // недоступная позиция в итог не входит
            ->assertSeeInOrder(['Позиций / штук', '1 / 1', 'Сумма без НДС', Money::format(30)]);

        $item = CartItem::where('product_id', $this->product->id)->sole();

        $this->actingAs($this->client)
            ->post("/order/update/{$item->id}", ['quantity' => 5])
            ->assertSessionHas('error');

        $this->actingAs($this->client)
            ->post("/order/delete/{$item->id}")
            ->assertSessionHas('success');

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertDontSee('Товар отсутствует в каталоге');
    }

    public function test_header_summary_skips_deleted_product(): void
    {
        $this->addToCart($this->product, 2);
        $this->addToCart($this->product('GR-002', ['price' => 30]), 1);

        $this->product->delete();

        $this->actingAs($this->client)->get('/')
            ->assertOk()
            ->assertSee('Корзина: 1 поз.')
            // Сумма с НДС в шапке: 30 + 20 % — только оставшийся товар
            ->assertSee(Money::format(36));
    }

    public function test_checkout_is_refused_while_cart_has_deleted_product(): void
    {
        $this->addToCart($this->product, 2);
        $this->product->delete();

        $this->actingAs($this->client)
            ->from('/order')
            ->post('/order/checkout', $this->checkoutData($this->client))
            ->assertRedirect('/order')
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, CartItem::count());
    }

    public function test_order_pages_open_after_product_is_deleted(): void
    {
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        $this->product->delete();

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('GR-001');

        $this->actingAs($this->client)->get('/orders')->assertOk();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('GR-001');
    }

    public function test_order_with_deleted_product_can_be_cancelled_by_request(): void
    {
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        $this->product->delete();

        $this->actingAs($this->client)
            ->post("/order/{$order->id}/cancel-request", ['cancel_reason' => 'Товар больше не нужен'])
            ->assertRedirect("/order/{$order->id}");

        $this->actingAs(User::factory()->admin()->create())
            ->post("/admin/order/{$order->id}/cancel-request/approve")
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    // --- Оформление ---

    public function test_checkout_creates_order_with_number_and_placed_at_and_clears_cart(): void
    {
        Carbon::setTestNow('2026-09-20 08:00:00');
        $this->addToCart($this->product, 2);

        // Номер и дата — момента оформления, а не создания корзины
        Carbon::setTestNow('2026-10-01 12:30:00');
        $order = $this->checkout();

        $this->assertSame('SM-2026-000001', $order->number);
        $this->assertSame('2026-10-01 12:30:00', $order->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertEquals(200, $order->total);
        $this->assertSame(8, $this->product->fresh()->stock);

        // Корзина очищена, но сама остаётся
        $this->assertSame(1, Cart::count());
        $this->assertSame(0, CartItem::count());

        $this->actingAs($this->client)->get('/order')->assertOk()->assertSee('Корзина пуста');
    }

    public function test_order_numbers_are_sequential_within_year(): void
    {
        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);

        // Год номера — по Минску: 20:30 UTC = 23:30 31.12, 21:10 UTC = 00:10 01.01
        Carbon::setTestNow('2026-12-31 20:30:00');
        $this->addToCart($this->product);
        $first = $this->checkout();

        $this->addToCart($this->product, 1, $colleague);
        $second = $this->checkout($colleague);

        Carbon::setTestNow('2026-12-31 21:10:00');
        $this->addToCart($this->product);
        $third = $this->checkout();

        $this->assertSame('SM-2026-000001', $first->number);
        $this->assertSame('SM-2026-000002', $second->number);
        $this->assertSame('SM-2027-000001', $third->number);
    }

    public function test_second_checkout_with_empty_cart_does_not_create_order(): void
    {
        $this->addToCart($this->product);
        $this->checkout();

        $this->actingAs($this->client)
            ->from('/order')
            ->post('/order/checkout', $this->checkoutData($this->client))
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Корзина пуста');

        $this->assertSame(1, Order::count());
    }

    // --- Снимок ---

    public function test_order_snapshot_does_not_change_after_product_update(): void
    {
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        $this->product->update([
            'name' => 'Переименованный',
            'display_name' => 'Новое витринное имя',
            'sku' => 'NEW-SKU',
            'price' => 999,
            'vat_rate' => 10,
        ]);

        $item = $order->items()->sole();

        $this->assertSame('Grohe Start', $item->product_name);
        $this->assertSame('GR-001', $item->product_sku);
        $this->assertEquals(100, $item->price);
        $this->assertEquals(20, $item->vat_rate);
        $this->assertEquals(200, $order->fresh()->total);

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('GR-001')
            ->assertDontSee('Новое витринное имя')
            ->assertDontSee('NEW-SKU');

        $this->actingAs(User::factory()->admin()->create())->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertDontSee('Переименованный');
    }

    public function test_snapshot_uses_display_name_when_set(): void
    {
        $product = $this->product('GR-002', ['display_name' => 'Смеситель для кухни Grohe']);

        $this->addToCart($product);
        $order = $this->checkout();

        $this->assertSame('Смеситель для кухни Grohe', $order->items()->sole()->product_name);
    }

    // --- Списки заказов ---

    public function test_admin_orders_list_does_not_contain_carts(): void
    {
        $admin = User::factory()->admin()->create();

        $this->addToCart($this->product, 2);

        $this->actingAs($admin)->get('/admin/orders')
            ->assertOk()
            ->assertSee('Заказов нет');

        Carbon::setTestNow('2026-10-01 12:30:00');
        $order = $this->checkout();

        // У клиента снова есть корзина — в списке заказов её быть не должно
        $this->addToCart($this->product, 1);

        $response = $this->actingAs($admin)->get('/admin/orders')
            ->assertOk()
            ->assertSee('SM-2026-000001')
            ->assertSee('01.10.2026')
            ->assertDontSee('01.10.2026 15:30'); // список — без времени (date_ru)

        $this->assertSame(1, substr_count($response->getContent(), '/admin/order/'));
        $this->assertSame([$order->id], $response->viewData('orders')->pluck('id')->all());
    }

    public function test_my_orders_show_number_and_placed_date(): void
    {
        Carbon::setTestNow('2026-10-01 12:30:00');
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        // Новая корзина в «Мои заказы» не попадает
        $this->addToCart($this->product, 1);

        $response = $this->actingAs($this->client)->get('/orders')
            ->assertOk()
            ->assertSee('SM-2026-000001')
            ->assertSee('01.10.2026')
            ->assertDontSee('01.10.2026 15:30'); // список — без времени (date_ru)

        $this->assertSame([$order->id], $response->viewData('activeOrders')->pluck('id')->all());

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('Заказ SM-2026-000001')
            ->assertSee('01.10.2026 15:30'); // карточка — со временем по Минску (12:30 UTC)
    }

    // --- Повторный заказ ---

    public function test_reorder_fills_cart_and_does_not_create_order(): void
    {
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        $this->product->update(['price' => 120]);

        $this->actingAs($this->client)
            ->post("/order/{$order->id}/reorder")
            ->assertRedirect('/order');

        $this->assertSame(1, Order::count());

        $item = $this->client->cart->items()->sole();
        $this->assertSame(2, $item->quantity);
        $this->assertEquals(120, $item->price_at_add);
    }

    public function test_reorder_skips_deleted_product_with_warning(): void
    {
        $this->addToCart($this->product, 2);
        $order = $this->checkout();

        $this->product->delete();

        $this->actingAs($this->client)
            ->post("/order/{$order->id}/reorder")
            ->assertRedirect('/order')
            ->assertSessionHas('reorder_issues', [['name' => 'Grohe Start', 'reason' => 'снят с продажи']])
            ->assertSessionHas('error');

        $this->assertSame(0, CartItem::count());
    }
}
