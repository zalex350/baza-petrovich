<?php

namespace Tests\Feature;

use App\Enums\CartProblemType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\CartValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кратность заказа на сервере: количество ≥ min_qty и (количество − min_qty) % qty_step = 0 —
 * при добавлении, изменении количества и перед оформлением.
 */
class CartQuantityRuleTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Количество должно быть не меньше 2 и кратно 3';

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        // Допустимо: 2, 5, 8, …
        $this->product = Product::create([
            'name' => 'Подводка', 'sku' => 'FLX-1', 'price' => 10, 'stock' => 100,
            'is_active' => true, 'is_published' => true, 'min_qty' => 2, 'qty_step' => 3, 'default_qty' => 2,
        ]);
    }

    private function inCart(int $quantity): CartItem
    {
        $cart = Cart::firstOrCreate(['user_id' => $this->client->id]);

        return CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => $quantity, 'price_at_add' => 10]);
    }

    public function test_validator_rule(): void
    {
        $validator = app(CartValidator::class);

        foreach ([2, 5, 8, 11] as $ok) {
            $this->assertNull($validator->quantityRule($this->product, $ok, 'x'), "qty {$ok}");
        }

        $this->assertSame(CartProblemType::BelowMinQuantity, $validator->quantityRule($this->product, 1, 'x')->type);
        $this->assertSame(CartProblemType::StepMismatch, $validator->quantityRule($this->product, 4, 'x')->type);
        $this->assertSame(self::MESSAGE, $validator->quantityRule($this->product, 4, 'x')->text());

        // Шаг 1 — без «кратно 1»; 1/1 — любое количество
        $this->assertSame('Количество должно быть не меньше 3', CartValidator::quantityMessage(3, 1));
        $this->assertNull($validator->quantityRule(new Product(['min_qty' => 1, 'qty_step' => 1]), 7, 'x'));
    }

    public function test_add_refuses_wrong_quantity_and_changes_nothing(): void
    {
        foreach ([1, 3, 4] as $qty) {
            $this->actingAs($this->client)->from('/catalog')
                ->post("/order/add/{$this->product->id}", ['qty' => $qty])
                ->assertRedirect('/catalog')
                ->assertSessionHas('error', self::MESSAGE);
        }

        $this->assertSame(0, CartItem::count());

        $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 4])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => self::MESSAGE]);

        $this->assertSame(0, CartItem::count());
    }

    public function test_add_checks_resulting_quantity_in_cart(): void
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 2])->assertSessionHas('success');

        // 2 + 2 = 4 — не по шагу; 2 + 3 = 5 — можно
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 2])->assertSessionHas('error', self::MESSAGE);
        $this->assertSame(2, CartItem::sole()->quantity);

        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 3])->assertSessionHas('success');
        $this->assertSame(5, CartItem::sole()->quantity);
    }

    public function test_update_refuses_wrong_quantity_and_keeps_old(): void
    {
        $item = $this->inCart(5);

        $this->actingAs($this->client)->from('/order')
            ->post("/order/update/{$item->id}", ['quantity' => 6])
            ->assertRedirect('/order')
            ->assertSessionHas('error', self::MESSAGE);

        $this->actingAs($this->client)
            ->postJson("/order/update/{$item->id}", ['quantity' => 1])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => self::MESSAGE, 'quantity' => 5]);

        $this->assertSame(5, $item->refresh()->quantity);

        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 8])->assertOk();
        $this->assertSame(8, $item->refresh()->quantity);
    }

    public function test_checkout_refuses_cart_with_wrong_quantity(): void
    {
        // Положено до того, как настроили кратность
        $this->inCart(4);

        $response = $this->actingAs($this->client)->from('/order')
            ->post('/order/checkout', $this->checkoutData($this->client));

        $response->assertRedirect('/order');
        $this->assertSame(0, Order::count());
        $this->assertContains('Подводка: '.self::MESSAGE, $response->getSession()->get('errors')?->all() ?? []);

        // Корзина показывает позицию как требующую корректировки
        $this->actingAs($this->client)->get('/order')->assertOk()->assertSee(self::MESSAGE);
    }
}
