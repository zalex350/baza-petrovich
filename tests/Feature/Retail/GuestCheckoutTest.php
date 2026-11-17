<?php

namespace Tests\Feature\Retail;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\CurrentCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Розница: корзина гостя по cookie и оформление без учётной записи.
 */
class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Сетка', 'slug' => 'setka']);

        $this->product = Product::create([
            'name' => 'Сетка рабица 50x50',
            'sku' => 'SR-1',
            'price' => 12.5,
            'stock' => 3,
            'category_id' => $category->id,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    /** Добавить товар гостем и вернуть cookie корзины */
    private function addAsGuest(int $qty = 2): string
    {
        $response = $this->post("/order/add/{$this->product->id}", ['qty' => $qty])->assertRedirect();
        $cookie = $response->getCookie(CurrentCart::COOKIE);
        $this->assertNotNull($cookie, 'гостю выдана cookie корзины');

        return $cookie->getValue();
    }

    private function validForm(array $override = []): array
    {
        return array_merge([
            'checkout_token' => 'tok-1',
            'name' => 'Пётр',
            'phone' => '+375 29 150-56-05',
            'email' => 'petr@example.com',
            'delivery_method' => 'delivery',
            'address' => 'Минск, ул. Молодёжная, 1',
            'payment_method' => 'on_receipt',
            'comment' => 'Позвонить заранее',
            'consent' => '1',
        ], $override);
    }

    public function test_guest_adds_to_cart_and_sees_it(): void
    {
        $token = $this->addAsGuest(2);

        $cart = Cart::where('token', $token)->first();
        $this->assertNull($cart->user_id);
        $this->assertSame(2, $cart->items()->first()->quantity);

        $this->withCookie(CurrentCart::COOKIE, $token)->get('/order')
            ->assertOk()
            ->assertSee('Сетка рабица 50x50')
            ->assertSee('data-checkout-retail', false)
            ->assertSee('name="delivery_method"', false);

        // Без cookie — своя пустая корзина
        $this->defaultCookies = [];
        $this->get('/order')->assertOk()->assertSee('Корзина пуста');
    }

    public function test_guest_cannot_touch_foreign_cart_item(): void
    {
        $token = $this->addAsGuest();
        $item = CartItem::first();

        $this->post("/order/update/{$item->id}", ['quantity' => 1])->assertForbidden();
        $this->withCookie(CurrentCart::COOKIE, 'x'.substr($token, 1))->post("/order/delete/{$item->id}")->assertForbidden();
        $this->withCookie(CurrentCart::COOKIE, $token)->post("/order/delete/{$item->id}")->assertRedirect();

        $this->assertSame(0, CartItem::count());
    }

    public function test_checkout_creates_retail_order_and_public_page(): void
    {
        Event::fake([OrderPlaced::class]);
        $token = $this->addAsGuest(2);

        $response = $this->withCookie(CurrentCart::COOKIE, $token)->post('/order/checkout', $this->validForm());

        $order = Order::with('items')->sole();
        $response->assertRedirect('/o/'.$order->public_token);

        $this->assertNull($order->user_id);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame('Пётр', $order->delivery_contact_name);
        $this->assertSame('+375 29 150-56-05', $order->delivery_contact_phone);
        $this->assertSame('petr@example.com', $order->customer_email);
        $this->assertSame('delivery', $order->delivery_method);
        $this->assertSame('on_receipt', $order->payment_method);
        $this->assertSame('Минск, ул. Молодёжная, 1', $order->delivery_address);
        $this->assertEquals(25.0, $order->total_with_vat);
        $this->assertEquals(4.17, $order->total_vat);
        $this->assertSame(0, CartItem::count());
        Event::assertDispatched(OrderPlaced::class);

        $this->get('/o/'.$order->public_token)
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Доставка')
            ->assertSee('Наличными или картой при получении');

        $this->get('/o/'.str_repeat('a', 40))->assertNotFound();

        // Повтор формы — тот же заказ
        $this->withCookie(CurrentCart::COOKIE, $token)->post('/order/checkout', $this->validForm())
            ->assertRedirect('/o/'.$order->public_token);
        $this->assertSame(1, Order::count());
    }

    public function test_pickup_uses_store_address_and_needs_no_address(): void
    {
        $token = $this->addAsGuest(1);

        $this->withCookie(CurrentCart::COOKIE, $token)
            ->post('/order/checkout', $this->validForm(['delivery_method' => 'pickup_brest', 'address' => '']))
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame('Самовывоз — Брест', $order->delivery_title);
        $this->assertStringContainsString('Коммерческая, 7', $order->delivery_address);
    }

    public function test_validation(): void
    {
        $token = $this->addAsGuest(1);

        $this->withCookie(CurrentCart::COOKIE, $token)
            ->post('/order/checkout', $this->validForm(['name' => '', 'phone' => 'abc', 'address' => '', 'payment_method' => 'erip', 'consent' => null]))
            ->assertRedirect('/order')
            ->assertSessionHasErrors(['name', 'phone', 'address', 'payment_method', 'consent']);

        $this->assertSame(0, Order::count());
    }

    public function test_guest_cart_moves_to_client_on_login(): void
    {
        $token = $this->addAsGuest(2);
        $client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'password' => bcrypt('secret123'),
        ]);

        $this->withCookie(CurrentCart::COOKIE, $token)
            ->post('/login', ['email' => $client->email, 'password' => 'secret123'])
            ->assertRedirect();

        $this->assertSame(2, $client->cart()->first()->items()->first()->quantity);
        $this->assertSame(0, Cart::whereNotNull('token')->count());
    }

    public function test_without_stock_tracking_quantity_is_not_limited(): void
    {
        config(['shop.track_stock' => false]);

        $this->post("/order/add/{$this->product->id}", ['qty' => 50])->assertSessionHas('success');
        $this->assertSame(50, CartItem::sole()->quantity);

        $this->get("/product/{$this->product->id}")->assertSee('В наличии');
    }
}
