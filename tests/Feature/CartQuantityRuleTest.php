<?php

namespace Tests\Feature;

use App\Enums\CartProblemType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\CartValidator;
use App\Support\OrderQuantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правило количества (OrderQuantity): кратно qty_step и не меньше min_qty; первое допустимое —
 * ceil(min_qty / qty_step) × qty_step (мин. 2, кратность 3 → 3, 6, 9). Везде одинаково: добавление
 * (каталог, карточка, «по артикулу»), изменение в корзине, повтор заказа, оформление.
 */
class CartQuantityRuleTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Количество должно быть не меньше 3 и кратно 3';

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        // min 2, шаг 3 → допустимо 3, 6, 9, …
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

    public function test_order_quantity_rule(): void
    {
        $rule = new OrderQuantity(2, 3);
        $this->assertSame(3, $rule->first);
        $this->assertTrue($rule->minIsRounded());
        $this->assertSame([3, 3, 3, 6, 6, 9], array_map($rule->roundUp(...), [0, 1, 3, 4, 6, 7]));
        $this->assertSame([null, 3, 6, 9], array_map($rule->roundDown(...), [2, 5, 8, 10]));
        $this->assertSame('Округлено до 6 — кратно 3', $rule->roundedHint(6));
        $this->assertSame('Мин. заказ 3 шт. · кратность 3 шт.', $rule->label());

        // Минимум больше шага: 5 / 3 → 6, 9, …
        $rule = new OrderQuantity(5, 3);
        $this->assertSame(6, $rule->first);
        $this->assertSame('Округлено до 6 — мин. 6, кратно 3', $rule->roundedHint(6));

        // Кратный минимум не меняется: 4 / 2 → 4, 6, …; шаг 1 — «мин. N»; 1/1 — без подписи
        $this->assertSame(4, (new OrderQuantity(4, 2))->first);
        $this->assertFalse((new OrderQuantity(4, 2))->minIsRounded());
        $this->assertSame('Округлено до 5 — мин. 5', (new OrderQuantity(5, 1))->roundedHint(5));
        $this->assertSame('Количество должно быть не меньше 5', (new OrderQuantity(5, 1))->message());
        $this->assertNull((new OrderQuantity(1, 1))->label());
        $this->assertSame(1, (new OrderQuantity(null, 0))->first);
    }

    public function test_validator_rule(): void
    {
        $validator = app(CartValidator::class);

        foreach ([3, 6, 9, 12] as $ok) {
            $this->assertNull($validator->quantityRule($this->product, $ok, 'x'), "qty {$ok}");
        }

        // Прежние «2, 5, 8» больше не допустимы
        $this->assertSame(CartProblemType::BelowMinQuantity, $validator->quantityRule($this->product, 2, 'x')->type);
        $this->assertSame(CartProblemType::StepMismatch, $validator->quantityRule($this->product, 5, 'x')->type);
        $this->assertSame(self::MESSAGE, $validator->quantityRule($this->product, 5, 'x')->text());
        $this->assertNull($validator->quantityRule(new Product(['min_qty' => 1, 'qty_step' => 1]), 7, 'x'));
    }

    public function test_add_rounds_up_with_hint(): void
    {
        $this->actingAs($this->client)->from('/catalog')
            ->post("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertRedirect('/catalog')
            ->assertSessionHas('success', 'Товар добавлен в корзину. Округлено до 3 — кратно 3');
        $this->assertSame(3, CartItem::sole()->quantity);

        // Итог в корзине: 3 + 2 = 5 → 6
        $this->actingAs($this->client)
            ->postJson("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Товар добавлен в корзину. Округлено до 6 — кратно 3']);
        $this->assertSame(6, CartItem::sole()->quantity);

        // Допустимое — без подсказки
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 3])
            ->assertSessionHas('success', 'Товар добавлен в корзину');
        $this->assertSame(9, CartItem::sole()->quantity);
    }

    public function test_add_rounded_over_stock_is_refused(): void
    {
        $this->product->update(['stock' => 4]);

        // 4 → 6 > остатка 4: не добавляется
        $this->actingAs($this->client)->postJson("/order/add/{$this->product->id}", ['qty' => 4])->assertStatus(422);
        $this->assertSame(0, CartItem::count());
    }

    public function test_quick_add_rounds_default_and_typed_quantity(): void
    {
        // default_qty 2 → 3
        $this->actingAs($this->client)->post('/order/quick-add', ['sku' => 'FLX-1'])
            ->assertRedirect('/order')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Добавлено в корзину: Подводка. Округлено до 3 — кратно 3');
        $this->assertSame(3, CartItem::sole()->quantity);

        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => 'FLX-1', 'qty' => 4])
            ->assertOk()->assertJsonPath('message', 'Добавлено в корзину: Подводка. Округлено до 9 — кратно 3');
        $this->assertSame(9, CartItem::sole()->quantity);

        // Подсказка поиска подставляет допустимое количество
        $this->actingAs($this->client)->getJson('/search/suggest?q=FLX-1')->assertOk()->assertJsonPath('products.0.default_qty', 3);
    }

    public function test_update_rounds_up_with_hint(): void
    {
        $item = $this->inCart(3);

        $this->actingAs($this->client)->from('/order')
            ->post("/order/update/{$item->id}", ['quantity' => 4])
            ->assertRedirect('/order')
            ->assertSessionHas('success', 'Округлено до 6 — кратно 3');
        $this->assertSame(6, $item->refresh()->quantity);

        // Ниже первого допустимого — до него
        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 1])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Округлено до 3 — кратно 3', 'hint' => 'Округлено до 3 — кратно 3', 'quantity' => 3]);

        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 9])
            ->assertOk()->assertJson(['message' => 'Корзина обновлена', 'hint' => null, 'quantity' => 9]);

        // Округлённое больше остатка — не применяется
        $this->product->update(['stock' => 10]);
        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 10])
            ->assertStatus(422)->assertJson(['ok' => false, 'message' => 'Доступно 10 шт.', 'quantity' => 9]);
    }

    public function test_checkout_refuses_cart_with_wrong_quantity(): void
    {
        // Положено по прежнему правилу (2, 5, 8): 5 теперь не кратно 3
        $this->inCart(5);

        $response = $this->actingAs($this->client)->from('/order')
            ->post('/order/checkout', $this->checkoutData($this->client));

        $response->assertRedirect('/order');
        $this->assertSame(0, Order::count());
        $this->assertContains('Подводка: '.self::MESSAGE, $response->getSession()->get('errors')?->all() ?? []);

        // Корзина показывает позицию как требующую корректировки; поле — без кратности, чтобы исправить
        $html = $this->actingAs($this->client)->get('/order')->assertOk()->assertSee(self::MESSAGE)
            ->assertSee('Мин. заказ 3 шт. · кратность 3 шт.')->getContent();
        $this->assertMatchesRegularExpression('/name="quantity"[^>]*value="5"[^>]*min="1"[^>]*step="1"/s', $html);
    }

    public function test_cart_row_qty_starts_at_first_allowed(): void
    {
        $this->inCart(6);

        $html = $this->actingAs($this->client)->get('/order')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="quantity"[^>]*value="6"[^>]*min="3"[^>]*step="3"/s', $html);
    }

    public function test_reorder_rounds_to_rule(): void
    {
        // В старом заказе 2 (по прежнему правилу) — в корзину 3; остаток 4 — вниз некуда, кроме 3
        $this->product->update(['stock' => 4]);
        $order = Order::create(['number' => 'SM-2026-000011', 'user_id' => $this->client->id, 'organization_id' => $this->client->organization_id,
            'status' => 'completed', 'total' => 1, 'placed_at' => now()->subMonth()]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $this->product->id, 'product_name' => 'Подводка', 'product_sku' => 'FLX-1',
            'quantity' => 5, 'price' => 10, 'vat_rate' => 20, 'amount_without_vat' => 50, 'vat_amount' => 0, 'amount_with_vat' => 50]);

        $this->actingAs($this->client)->post("/order/{$order->id}/reorder")->assertSessionHas('success');

        // 5 → вверх 6 > остатка 4 → вниз 3
        $this->assertSame(3, CartItem::sole()->quantity);
    }
}
