<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Актуализация корзины и оформление заказа
 * (BR-101, BR-121, BR-123…BR-125, BR-134, BR-135, SRS §7.17).
 */
class CheckoutValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->client();
    }

    private function client(): User
    {
        return User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name'         => "Смеситель {$sku}",
            'sku'          => $sku,
            'price'        => 100,
            'stock'        => 10,
            'is_active'    => true,
            'is_published' => true,
        ], $attributes));
    }

    private function add(Product $product, int $qty = 1, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->client)
            ->from('/catalog')
            ->post("/order/add/{$product->id}", ['qty' => $qty]);
    }

    private function checkout(?User $user = null, ?string $token = null)
    {
        return $this->actingAs($user ?? $this->client)
            ->from('/order')
            ->post('/order/checkout', $this->checkoutData($user ?? $this->client, array_filter(['checkout_token' => $token])));
    }

    /**
     * @return list<string>
     */
    private function listedProblems($response): array
    {
        return $response->getSession()->get('errors')?->all() ?? [];
    }

    // --- Добавление в корзину ---

    public static function unavailableProducts(): array
    {
        return [
            'скрыт'               => [['is_active' => false], 'Товар больше недоступен для заказа'],
            'снят с публикации'   => [['is_published' => false], 'Товар больше недоступен для заказа'],
            'без цены'            => [['price' => null], 'Товар больше недоступен для заказа'],
            'нет остатка'         => [['stock' => 0], 'Недостаточный остаток товара: нет в наличии'],
        ];
    }

    #[DataProvider('unavailableProducts')]
    public function test_unavailable_product_is_not_added(array $attributes, string $message): void
    {
        $product = $this->product('A-1', $attributes);

        $this->add($product)
            ->assertRedirect('/catalog')
            ->assertSessionHas('error', $message);

        $this->assertSame(0, CartItem::count());
    }

    public function test_deleted_and_nonexistent_product_is_not_added(): void
    {
        $product = $this->product('A-1');
        $product->delete();

        $this->add($product)->assertSessionHas('error', 'Товар отсутствует в каталоге');

        $this->actingAs($this->client)
            ->post('/order/add/999999')
            ->assertSessionHas('error', 'Товар отсутствует в каталоге');

        $this->assertSame(0, CartItem::count());
    }

    public function test_new_position_above_stock_is_not_added(): void
    {
        $product = $this->product('A-1', ['stock' => 3]);

        $this->add($product, 4)
            ->assertSessionHas('error', 'Недостаточный остаток товара: доступно 3');

        $this->assertSame(0, CartItem::count());

        $this->add($product, 3)->assertSessionHas('success');
        $this->assertSame(3, CartItem::sole()->quantity);
    }

    public function test_existing_position_cannot_grow_above_stock(): void
    {
        $product = $this->product('A-1', ['stock' => 3]);

        $this->add($product, 2)->assertSessionHas('success');

        $this->add($product, 2)
            ->assertSessionHas('error', 'Недостаточный остаток товара: доступно 3, в корзине уже 2');

        $this->assertSame(2, CartItem::sole()->quantity);
    }

    public function test_quantity_of_hidden_product_cannot_be_changed(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $product->update(['is_active' => false]);
        $item = CartItem::sole();

        $this->actingAs($this->client)
            ->post("/order/update/{$item->id}", ['quantity' => 3])
            ->assertSessionHas('error', 'Смеситель A-1: Товар больше недоступен для заказа');

        $this->assertSame(2, $item->fresh()->quantity);
    }

    // --- Страница корзины: состояния и пометки ---

    public function test_ready_cart_shows_state_and_active_checkout_button(): void
    {
        // Без адреса доставки кнопка оформления неактивна — заводим адрес организации
        $this->checkoutData($this->client);

        $this->add($this->product('A-1'), 2);

        $response = $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('data-cart-state="ready"', false)
            ->assertSee('Готова к оформлению')
            ->assertSee('name="checkout_token"', false)
            ->assertDontSee('Принять новые цены');

        $this->assertDoesNotMatchRegularExpression('/<button\s+disabled/', $response->getContent());
    }

    public function test_cart_marks_problem_items_with_reasons(): void
    {
        $hidden      = $this->product('HID');
        $unpublished = $this->product('UNP');
        $deleted     = $this->product('DEL');
        $outOfStock  = $this->product('OUT');
        $low         = $this->product('LOW');
        $fine        = $this->product('OK', ['price' => 30]);

        foreach ([$hidden, $unpublished, $deleted, $outOfStock, $fine] as $product) {
            $this->add($product);
        }
        $this->add($low, 5);

        $hidden->update(['is_active' => false]);
        $unpublished->update(['is_published' => false]);
        $deleted->delete();
        $outOfStock->update(['stock' => 0]);
        $low->update(['stock' => 2]);

        $response = $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('data-cart-state="needs_correction"', false)
            ->assertSee('Требует корректировки')
            ->assertSee('Товар больше недоступен для заказа')
            ->assertSee('Товар отсутствует в каталоге')
            ->assertSee('Нет в наличии')
            ->assertSee('Недостаточный остаток товара')
            ->assertSee('Доступно 2, в корзине 5')
            // недоступные в итог не входят: OUT 100 + LOW 500 + OK 30
            ->assertSee('Итого: 630.00 BYN');

        // Оформление недоступно, пока корзина не исправлена
        $this->assertMatchesRegularExpression('/<button\s+disabled/', $response->getContent());
    }

    public function test_price_change_is_shown_with_accept_button(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $product->update(['price' => 120]);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('data-cart-state="needs_update"', false)
            ->assertSee('Требует обновления')
            ->assertSee('Цена товара изменилась')
            ->assertSee('Было 100.00 BYN, стало 120.00 BYN')
            ->assertSee('Принять новые цены')
            ->assertSee('action="/order/accept-prices"', false);
    }

    // --- Принятие новых цен (BR-125) ---

    public function test_accept_prices_updates_price_at_add_and_makes_cart_ready(): void
    {
        $changed   = $this->product('A-1');
        $unchanged = $this->product('B-1', ['price' => 50]);

        $this->add($changed, 2);
        $this->add($unchanged);

        $changed->update(['price' => 120]);

        $this->actingAs($this->client)
            ->post('/order/accept-prices')
            ->assertRedirect('/order')
            ->assertSessionHas('success', 'Новые цены приняты');

        $this->assertEquals(120, CartItem::where('product_id', $changed->id)->sole()->price_at_add);
        $this->assertEquals(50, CartItem::where('product_id', $unchanged->id)->sole()->price_at_add);

        $this->actingAs($this->client)->get('/order')
            ->assertSee('data-cart-state="ready"', false)
            ->assertDontSee('Цена товара изменилась')
            ->assertSee('Итого: 290.00 BYN');
    }

    public function test_accept_prices_does_not_touch_foreign_cart_or_unavailable_items(): void
    {
        $product = $this->product('A-1');
        $hidden  = $this->product('B-1');
        $other   = $this->client();

        $this->add($product, 1);
        $this->add($hidden, 1);
        $this->add($product, 1, $other);

        $product->update(['price' => 120]);
        $hidden->update(['price' => 120, 'is_active' => false]);

        $this->actingAs($this->client)->post('/order/accept-prices')->assertRedirect('/order');

        $this->assertEquals(120, $this->client->cart->items()->where('product_id', $product->id)->sole()->price_at_add);
        $this->assertEquals(100, $this->client->cart->items()->where('product_id', $hidden->id)->sole()->price_at_add);
        $this->assertEquals(100, $other->cart->items()->sole()->price_at_add);
    }

    public function test_accept_prices_with_empty_cart_is_harmless(): void
    {
        $this->actingAs($this->client)
            ->post('/order/accept-prices')
            ->assertRedirect('/order')
            ->assertSessionHas('success', 'Цены в корзине актуальны');
    }

    // --- Оформление: повторная проверка ---

    public static function blockingChanges(): array
    {
        return [
            'скрыт'              => [['is_active' => false], 'Смеситель A-1: Товар больше недоступен для заказа'],
            'снят с публикации'  => [['is_published' => false], 'Смеситель A-1: Товар больше недоступен для заказа'],
            'остаток 0'          => [['stock' => 0], 'Смеситель A-1: Недостаточный остаток товара (нет в наличии)'],
            'недостаток остатка' => [['stock' => 1], 'Смеситель A-1: Недостаточный остаток товара (доступно 1, в корзине 2)'],
            'изменение цены'     => [['price' => 120], 'Смеситель A-1: Цена товара изменилась (было 100.00 BYN, стало 120.00 BYN)'],
        ];
    }

    #[DataProvider('blockingChanges')]
    public function test_checkout_is_blocked_when_product_changed(array $change, string $problem): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $product->update($change);
        $stock = $product->fresh()->stock;

        $response = $this->checkout()
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Оформление заказа невозможно до устранения выявленных ошибок');

        $this->assertSame([$problem], $this->listedProblems($response));

        $this->assertSame(0, Order::count());
        $this->assertSame(2, CartItem::sole()->quantity);
        $this->assertSame($stock, $product->fresh()->stock);
    }

    public function test_checkout_is_blocked_when_product_deleted(): void
    {
        $soft = $this->product('A-1');
        $hard = $this->product('B-1');

        $this->add($soft);
        $this->add($hard);

        $soft->delete();
        $hard->forceDelete();

        $response = $this->checkout()->assertRedirect('/order');

        $this->assertSame(
            ['Смеситель A-1: Товар отсутствует в каталоге', 'Смеситель B-1: Товар отсутствует в каталоге'],
            $this->listedProblems($response)
        );
        $this->assertSame(0, Order::count());
        $this->assertSame(2, CartItem::count());
    }

    public function test_checkout_lists_all_problems_at_once_and_changes_nothing(): void
    {
        $hidden      = $this->product('HID');
        $unpublished = $this->product('UNP');
        $deleted     = $this->product('DEL');
        $low         = $this->product('LOW');
        $price       = $this->product('PRC');
        $fine        = $this->product('OK');

        foreach ([$hidden, $unpublished, $deleted, $price, $fine] as $product) {
            $this->add($product, 2);
        }
        $this->add($low, 5);

        $hidden->update(['is_active' => false]);
        $unpublished->update(['is_published' => false]);
        $deleted->delete();
        $low->update(['stock' => 3, 'price' => 90]);
        $price->update(['price' => 120]);

        $response = $this->checkout()->assertRedirect('/order');

        $this->assertSame([
            'Смеситель HID: Товар больше недоступен для заказа',
            'Смеситель UNP: Товар больше недоступен для заказа',
            'Смеситель DEL: Товар отсутствует в каталоге',
            'Смеситель PRC: Цена товара изменилась (было 100.00 BYN, стало 120.00 BYN)',
            'Смеситель LOW: Недостаточный остаток товара (доступно 3, в корзине 5)',
            'Смеситель LOW: Цена товара изменилась (было 100.00 BYN, стало 90.00 BYN)',
        ], $this->listedProblems($response));

        // Ничего не создано и не списано, корзина цела
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(6, CartItem::count());
        $this->assertSame(10, $fine->fresh()->stock);
        $this->assertSame(10, $price->fresh()->stock);

        // Перечень виден пользователю в корзине
        $this->actingAs($this->client)->get('/order')
            ->assertSee('Оформление заказа невозможно до устранения выявленных ошибок')
            ->assertSee('Смеситель HID: Товар больше недоступен для заказа')
            ->assertSee('Смеситель LOW: Недостаточный остаток товара (доступно 3, в корзине 5)');
    }

    public function test_order_is_placed_at_accepted_price(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $product->update(['price' => 120]);

        $this->checkout()->assertRedirect('/order');
        $this->assertSame(0, Order::count());

        $this->actingAs($this->client)->post('/order/accept-prices');

        $this->checkout()->assertRedirect('/orders');

        $order = Order::sole();
        $this->assertEquals(240, $order->total);
        $this->assertEquals(120, $order->items()->sole()->price);
        $this->assertSame(8, $product->fresh()->stock);
    }

    // --- Остаток ---

    public function test_last_unit_goes_to_single_order(): void
    {
        $product = $this->product('A-1', ['stock' => 1]);
        $rival = $this->client();

        // Корзина не резервирует (BR-099): последняя единица лежит в двух корзинах
        $this->add($product)->assertSessionHas('success');
        $this->add($product, 1, $rival)->assertSessionHas('success');

        $this->checkout()->assertRedirect('/orders');

        $response = $this->checkout($rival)->assertRedirect('/order');

        $this->assertSame(['Смеситель A-1: Недостаточный остаток товара (нет в наличии)'], $this->listedProblems($response));
        $this->assertSame(1, Order::count());
        $this->assertSame($this->client->id, Order::sole()->user_id);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, $rival->cart->items()->count());
    }

    public function test_stock_never_goes_negative(): void
    {
        $product = $this->product('A-1', ['stock' => 5]);
        $rival = $this->client();

        $this->add($product, 4);
        $this->add($product, 4, $rival);

        $this->checkout()->assertRedirect('/orders');
        $this->checkout($rival)->assertRedirect('/order');

        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, Order::count());
    }

    // --- Двойная отправка ---

    public function test_double_submit_of_same_form_creates_single_order(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $this->checkout(token: 'form-token-1')->assertRedirect('/orders')->assertSessionHas('success');

        // Повтор той же формы — тот же результат для пользователя, без второго заказа
        $this->checkout(token: 'form-token-1')->assertRedirect('/orders')->assertSessionHas('success');

        $this->assertSame(1, Order::count());
        $this->assertSame('form-token-1', Order::sole()->checkout_token);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_repeated_token_does_not_swallow_new_cart(): void
    {
        $product = $this->product('A-1');

        $this->add($product, 2);
        $this->checkout(token: 'form-token-1')->assertRedirect('/orders');

        // Новая корзина, отправленная со старым токеном (вкладка осталась открытой):
        // второго заказа нет, корзина не тронута
        $this->add($product, 1);
        $this->checkout(token: 'form-token-1')->assertRedirect('/orders');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, CartItem::count());
        $this->assertSame(8, $product->fresh()->stock);

        // С новым токеном — новый заказ
        $this->checkout(token: 'form-token-2')->assertRedirect('/orders');

        $this->assertSame(2, Order::count());
        $this->assertSame(7, $product->fresh()->stock);
    }

    public function test_foreign_token_is_not_treated_as_own_order(): void
    {
        $product = $this->product('A-1');
        $rival = $this->client();

        $this->add($product, 1, $rival);
        $this->checkout($rival, 'rival-token')->assertRedirect('/orders');

        $this->checkout(token: 'rival-token')
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Корзина пуста');

        $this->assertSame(1, Order::count());
    }

    public function test_cart_page_issues_new_token_each_time(): void
    {
        $this->add($this->product('A-1'));

        $tokens = [];

        foreach ([1, 2] as $_) {
            $html = $this->actingAs($this->client)->get('/order')->getContent();
            preg_match('/name="checkout_token" value="([^"]+)"/', $html, $m);
            $tokens[] = $m[1] ?? null;
        }

        $this->assertNotNull($tokens[0]);
        $this->assertNotSame($tokens[0], $tokens[1]);
    }

    // --- Тексты ошибок по SRS ---

    public function test_error_codes_are_not_shown_to_user(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);
        $product->update(['stock' => 1, 'price' => 120]);

        $this->checkout()->assertRedirect('/order');

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Оформление заказа невозможно до устранения выявленных ошибок')
            ->assertSee('Недостаточный остаток товара')
            ->assertSee('Цена товара изменилась')
            ->assertDontSee('ERR-');
    }

    public function test_unexpected_failure_reports_order_creation_error(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $this->mock(\App\Services\CheckoutService::class)
            ->shouldReceive('place')
            ->andThrow(new \RuntimeException('connection lost'));

        $this->checkout()
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Ошибка создания заказа');

        $this->assertSame(0, Order::count());
        $this->assertSame(2, CartItem::sole()->quantity);
        $this->assertSame(10, $product->fresh()->stock);
    }

    // --- Физическое удаление товара ---

    public function test_physically_deleted_product_stays_in_cart_as_unavailable(): void
    {
        $product = $this->product('A-1');
        $this->add($product, 2);

        $product->forceDelete();

        $item = CartItem::sole();
        $this->assertNull($item->product_id);
        $this->assertSame('Смеситель A-1', $item->product_name);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Смеситель A-1')
            ->assertSee('Товар отсутствует в каталоге');

        $this->actingAs($this->client)
            ->post("/order/delete/{$item->id}")
            ->assertSessionHas('success');

        $this->assertSame(0, CartItem::count());
    }
}
