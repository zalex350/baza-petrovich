<?php

namespace Tests\Feature;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Корзина-оформление (макеты Main, CartMobile): галочка «в заказ» (cart_items.selected),
 * частичное оформление, автоснятие при нехватке остатка, кратность, «Быстро добавить»,
 * очистка, неактивная кнопка.
 */
class CartSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->cart = Cart::create(['user_id' => $this->client->id]);
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => "Смеситель {$sku}", 'sku' => $sku, 'price' => 100, 'stock' => 10,
            'is_active' => true, 'is_published' => true,
        ], $attributes));
    }

    private function inCart(Product $product, int $quantity = 1, bool $selected = true): CartItem
    {
        return CartItem::create([
            'cart_id' => $this->cart->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'price_at_add' => $product->price, 'selected' => $selected,
        ]);
    }

    // --- selected ---

    public function test_new_position_is_selected_by_default(): void
    {
        $this->actingAs($this->client)->post('/order/add/'.$this->product('A-1')->id, ['qty' => 1]);

        $this->assertTrue(CartItem::sole()->selected);
    }

    public function test_select_and_unselect_without_reload(): void
    {
        $item = $this->inCart($this->product('A-1'), 2);

        $this->actingAs($this->client)->postJson("/order/select/{$item->id}", ['selected' => 0])
            ->assertOk()
            ->assertJson(['ok' => true, 'selected' => false])
            // Сумма в шапке — по всем позициям корзины, галочка её не меняет
            ->assertJsonPath('cart.count', 1)
            ->assertJsonPath('cart.total_text', Money::format(240));

        $this->assertFalse($item->refresh()->selected);

        $this->actingAs($this->client)->postJson("/order/select/{$item->id}", ['selected' => 1])
            ->assertOk()
            ->assertJsonPath('cart.total_text', Money::format(240));

        $this->assertTrue($item->refresh()->selected);
    }

    public function test_cannot_select_position_with_shortage_or_wrong_multiplicity(): void
    {
        $low = $this->inCart($this->product('LOW', ['stock' => 2]), 5, false);
        $step = $this->inCart($this->product('STP', ['min_qty' => 2, 'qty_step' => 2]), 3, false);

        $this->actingAs($this->client)->postJson("/order/select/{$low->id}", ['selected' => 1])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Доступно 2 шт.']);

        $this->actingAs($this->client)->postJson("/order/select/{$step->id}", ['selected' => 1])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Количество должно быть не меньше 2 и кратно 2']);

        $this->assertFalse($low->refresh()->selected);
        $this->assertFalse($step->refresh()->selected);
    }

    public function test_select_all_selects_only_orderable(): void
    {
        $ok = $this->inCart($this->product('A-1'), 1, false);
        $low = $this->inCart($this->product('LOW', ['stock' => 1]), 3, false);

        $this->actingAs($this->client)->postJson('/order/select-all', ['selected' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'Отмечено позиций: 1. Нельзя заказать сейчас: 1');

        $this->assertTrue($ok->refresh()->selected);
        $this->assertFalse($low->refresh()->selected);

        $this->actingAs($this->client)->postJson('/order/select-all', ['selected' => 0])->assertOk();
        $this->assertFalse($ok->refresh()->selected);
    }

    public function test_others_cannot_touch_my_positions(): void
    {
        $item = $this->inCart($this->product('A-1'));
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($other)->postJson("/order/select/{$item->id}", ['selected' => 0])->assertForbidden();
        $this->actingAs($other)->postJson("/order/delete/{$item->id}")->assertForbidden();
    }

    // --- автоснятие при нехватке остатка ---

    public function test_shortage_unselects_position_with_banner_and_row_text(): void
    {
        $this->inCart($this->product('OK'));
        $low = $this->inCart($this->product('LOW', ['name' => 'Сифон', 'stock' => 2]), 3);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('data-shortage-banner', false)
            ->assertSee('У позиции «Сифон» на складе 2 шт. из 3.')
            ->assertSee('Доступно 2 шт.')
            ->assertSee('data-cart-state="ready"', false);

        $this->assertFalse($low->refresh()->selected);
    }

    // --- частичное оформление ---

    public function test_only_selected_positions_are_ordered_others_stay_in_cart(): void
    {
        $a = $this->product('A-1', ['stock' => 5]);
        $b = $this->product('B-1', ['stock' => 5]);
        $this->inCart($a, 2);
        $kept = $this->inCart($b, 3, false);

        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame([$a->id], $order->items()->pluck('product_id')->all());
        $this->assertEquals(240, $order->total_with_vat);

        // Неотмеченная — в корзине, её остаток не тронут
        $this->assertSame([$kept->id], CartItem::pluck('id')->all());
        $this->assertSame(3, $a->refresh()->stock);
        $this->assertSame(5, $b->refresh()->stock);
    }

    public function test_nothing_selected_cannot_be_ordered_and_button_is_disabled(): void
    {
        $this->inCart($this->product('A-1'), 1, false);
        $data = $this->checkoutData($this->client);

        $html = $this->actingAs($this->client)->get('/order')->assertOk()
            ->assertSee('data-cart-state="nothing_selected"', false)
            ->assertSee('Отметьте позиции, которые войдут в заказ.')
            ->getContent();

        $this->assertMatchesRegularExpression('#<button[^>]*\sdisabled="disabled"[^>]*data-checkout-submit#', $html);

        $this->actingAs($this->client)->from('/order')->post('/order/checkout', $data)
            ->assertRedirect('/order')
            ->assertSessionHas('error', CheckoutException::NOTHING_SELECTED);

        $this->assertSame(0, Order::count());
    }

    public function test_selected_position_with_wrong_multiplicity_blocks_checkout(): void
    {
        $this->inCart($this->product('STP', ['min_qty' => 2, 'qty_step' => 2]), 3);
        $data = $this->checkoutData($this->client);

        $this->actingAs($this->client)->get('/order')
            ->assertSee('data-cart-state="needs_correction"', false)
            ->assertSee('Количество должно быть не меньше 2 и кратно 2')
            ->assertSee('Исправьте отмеченные позиции.');

        $this->actingAs($this->client)->from('/order')->post('/order/checkout', $data)->assertRedirect('/order');
        $this->assertSame(0, Order::count());
    }

    // --- количество, удаление, очистка ---

    public function test_quantity_change_is_checked_and_not_applied_on_error(): void
    {
        $item = $this->inCart($this->product('STP', ['min_qty' => 2, 'qty_step' => 3, 'stock' => 6]), 2);

        // min 2, шаг 3 → 3, 6; 4 → вверх до 6; 8 → 9 больше остатка 6 — не применяется
        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 4])
            ->assertOk()->assertJson(['quantity' => 6, 'message' => 'Округлено до 6 — кратно 3']);
        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 8])
            ->assertStatus(422)->assertJson(['quantity' => 6]);
        $this->actingAs($this->client)->postJson("/order/update/{$item->id}", ['quantity' => 3])
            ->assertOk()->assertJson(['quantity' => 3]);

        $this->assertSame(3, $item->refresh()->quantity);
    }

    public function test_delete_answers_json_and_clear_empties_cart(): void
    {
        $a = $this->inCart($this->product('A-1'));
        $this->inCart($this->product('B-1'));

        $this->actingAs($this->client)->postJson("/order/delete/{$a->id}")
            ->assertOk()->assertJsonPath('cart.count', 1);

        $this->actingAs($this->client)->get('/order')->assertSee('action="/order/clear"', false);

        $this->actingAs($this->client)->post('/order/clear')
            ->assertRedirect('/order')->assertSessionHas('success', 'Корзина очищена');

        $this->assertSame(0, CartItem::count());
    }

    // --- «Быстро добавить» ---

    public function test_quick_add_by_sku_with_default_and_given_quantity(): void
    {
        $product = $this->product('FLX-050', ['min_qty' => 10, 'qty_step' => 10, 'default_qty' => 10, 'stock' => 100]);

        $this->actingAs($this->client)->post('/order/quick-add', ['sku' => 'flx-050'])
            ->assertRedirect('/order')->assertSessionHasNoErrors();
        $this->assertSame(10, CartItem::sole()->quantity);

        $this->actingAs($this->client)->post('/order/quick-add', ['sku' => 'FLX-050', 'qty' => 20])->assertSessionHasNoErrors();
        $this->assertSame(30, CartItem::sole()->quantity);
        $this->assertSame($product->id, CartItem::sole()->product_id);
    }

    public function test_quick_add_errors_are_under_fields(): void
    {
        $this->product('FLX-050', ['min_qty' => 10, 'qty_step' => 10, 'stock' => 100]);

        $this->actingAs($this->client)->from('/order')->post('/order/quick-add', ['sku' => ''])
            ->assertSessionHasErrors(['sku' => 'Укажите артикул']);

        $this->actingAs($this->client)->from('/order')->post('/order/quick-add', ['sku' => 'NOPE'])
            ->assertSessionHasErrors(['sku' => 'Товар с артикулом «NOPE» не найден']);

        // Остаток — у количества (5 → вверх до 10, 200 → больше остатка)
        $this->actingAs($this->client)->from('/order')->post('/order/quick-add', ['sku' => 'FLX-050', 'qty' => 200])
            ->assertSessionHasErrors(['qty']);

        $this->assertSame(0, CartItem::count());

        // Ошибка видна под полем
        $this->actingAs($this->client)->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['sku' => 'Товар с артикулом «NOPE» не найден']))])
            ->get('/order')
            ->assertSee('id="quick-sku-error"', false)
            ->assertSee('Товар с артикулом «NOPE» не найден');
    }

    public function test_quick_add_answers_json_with_item_and_header(): void
    {
        $product = $this->product('FLX-050', ['min_qty' => 10, 'qty_step' => 10, 'default_qty' => 10, 'stock' => 100]);

        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => 'FLX-050'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('item_id', CartItem::sole()->id)
            ->assertJsonPath('cart.count', 1)
            ->assertJsonPath('cart.total_text', Money::format(1200));

        $this->assertSame(10, CartItem::sole()->quantity);
        $this->assertSame($product->id, CartItem::sole()->product_id);
    }

    public function test_quick_add_json_errors_by_field(): void
    {
        $this->product('FLX-050', ['min_qty' => 10, 'qty_step' => 10, 'stock' => 100]);

        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => ''])
            ->assertStatus(422)->assertExactJson(['ok' => false, 'errors' => ['sku' => 'Укажите артикул']]);

        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => 'NOPE'])
            ->assertStatus(422)->assertJsonPath('errors.sku', 'Товар с артикулом «NOPE» не найден');

        $this->actingAs($this->client)->postJson('/order/quick-add', ['sku' => 'FLX-050', 'qty' => 200])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['qty']]);

        $this->assertSame(0, CartItem::count());
    }

    public function test_quick_add_strip_is_inside_table_card_above_headers(): void
    {
        $this->inCart($this->product('A-1'));

        $html = $this->actingAs($this->client)->get('/order')->assertOk()->getContent();

        // Полоса — в карточке таблицы, перед строкой заголовков; на телефоне — над карточками
        $this->assertMatchesRegularExpression('#xl:block">\s*(<!--.*?-->\s*)?<form[^>]*action="/order/quick-add".*?Добавить по артикулу.*?aria-label="Выбрать все"#s', $html);
        $this->assertSame(2, substr_count($html, 'data-quick-add'));
        $this->assertStringContainsString('id="quick-sku-m"', $html);
        $this->assertStringContainsString('skuSuggest(', $html);
        // Старой формы в шапке страницы нет
        $this->assertStringNotContainsString('Быстро добавить', $html);
    }

    public function test_quick_add_is_available_in_empty_cart(): void
    {
        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Корзина пуста')
            ->assertSee('Добавить по артикулу');
    }

    public function test_suggest_gives_default_qty_for_quick_add(): void
    {
        $this->product('FLX-050', ['default_qty' => 10]);

        $this->getJson('/search/suggest?q=FLX')
            ->assertOk()
            // Без артикула производителя — SKU в поле article; сам sku клиенту не отдаётся
            ->assertJsonPath('products.0.article', 'FLX-050')
            ->assertJsonMissingPath('products.0.sku')
            ->assertJsonPath('products.0.default_qty', 10);
    }

    // --- прочее ---

    public function test_old_checkout_page_redirects_to_cart(): void
    {
        $this->actingAs($this->client)->get('/order/checkout')->assertRedirect('/order');
    }

    public function test_panel_and_mobile_bar(): void
    {
        $this->inCart($this->product('A-1'), 2);
        $this->checkoutData($this->client);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSeeInOrder(['Ваш заказ', 'Позиций / штук', '1 / 2', 'Сумма без НДС', Money::format(200), 'НДС 20%', Money::format(40), 'Итого с НДС', Money::format(240)])
            ->assertSee('Менеджер проверит заказ и передаст его в 1С.')
            ->assertSee('data-refresh="mobilebar"', false)
            ->assertSee('Итого с НДС · 2 шт.')
            ->assertSee('form="checkout-form"', false)
            ->assertSee('Выбрать все');
    }
}
