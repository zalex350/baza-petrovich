<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оформление: адрес доставки (BR-128, BR-129) и комментарий (BR-130).
 */
class CheckoutDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Product $product;

    private DeliveryAddress $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 10, 'is_active' => true,
        ]);

        $this->address = DeliveryAddress::createFor($this->client->organization, [
            'title'         => 'Склад на Промышленной',
            'address'       => 'г. Минск, ул. Промышленная, 10',
            'contact_name'  => 'Иван Кладовщик',
            'contact_phone' => '+375 29 111-22-33',
        ]);

        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 2]);
    }

    private function checkout(array $data)
    {
        return $this->actingAs($this->client)->from('/order')->post('/order/checkout', $data);
    }

    private function assertNothingPlaced(): void
    {
        $this->assertSame(0, Order::count());
        $this->assertSame(2, CartItem::sole()->quantity);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    // --- Адрес обязателен (BR-128) ---

    public function test_checkout_without_address_is_refused(): void
    {
        $this->checkout([])
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Не выбран адрес доставки');

        $this->assertNothingPlaced();
    }

    public function test_checkout_with_garbage_address_id_is_refused(): void
    {
        $this->checkout(['delivery_address_id' => 'abc'])
            ->assertRedirect('/order')
            ->assertSessionHasErrors('delivery_address_id');

        $this->checkout(['delivery_address_id' => 999999])
            ->assertRedirect('/order')
            ->assertSessionHas('error');

        $this->assertNothingPlaced();
    }

    // --- Адрес своей организации (BR-129) ---

    public function test_checkout_with_foreign_address_is_refused(): void
    {
        $foreign = DeliveryAddress::createFor(Organization::factory()->create(), [
            'title' => 'Чужой склад', 'address' => 'г. Брест',
        ]);

        $this->checkout(['delivery_address_id' => $foreign->id])
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Выбранный адрес доставки недоступен. Выберите адрес своей организации');

        $this->assertNothingPlaced();
    }

    // --- Снимок адреса ---

    public function test_order_stores_address_snapshot(): void
    {
        $this->checkout(['delivery_address_id' => $this->address->id])->assertRedirectContains('/placed');

        $order = Order::sole();

        $this->assertSame('Склад на Промышленной', $order->delivery_title);
        $this->assertSame('г. Минск, ул. Промышленная, 10', $order->delivery_address);
        $this->assertSame('Иван Кладовщик', $order->delivery_contact_name);
        $this->assertSame('+375 29 111-22-33', $order->delivery_contact_phone);
    }

    public function test_snapshot_does_not_change_after_address_is_edited_or_deleted(): void
    {
        $this->checkout(['delivery_address_id' => $this->address->id])->assertRedirectContains('/placed');
        $order = Order::sole();

        $this->actingAs($this->client)->post("/addresses/{$this->address->id}", [
            'title'         => 'Переименованный',
            'address'       => 'г. Гомель, ул. Новая, 5',
            'contact_name'  => 'Другой человек',
            'contact_phone' => '000',
        ])->assertRedirect('/addresses');

        $order->refresh();
        $this->assertSame('Склад на Промышленной', $order->delivery_title);
        $this->assertSame('г. Минск, ул. Промышленная, 10', $order->delivery_address);
        $this->assertSame('Иван Кладовщик', $order->delivery_contact_name);

        $this->actingAs($this->client)->post("/addresses/{$this->address->id}/delete");
        $this->assertSame(0, DeliveryAddress::count());

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('г. Минск, ул. Промышленная, 10')
            ->assertDontSee('г. Гомель, ул. Новая, 5');
    }

    public function test_non_default_address_can_be_chosen(): void
    {
        $second = DeliveryAddress::createFor($this->client->organization, [
            'title' => 'Магазин', 'address' => 'г. Минск, пр. Независимости, 50',
        ]);

        $this->checkout(['delivery_address_id' => $second->id])->assertRedirectContains('/placed');

        $this->assertSame('Магазин', Order::sole()->delivery_title);
    }

    // --- Комментарий (BR-130) ---

    public function test_comment_is_saved(): void
    {
        $this->checkout([
            'delivery_address_id' => $this->address->id,
            'comment'             => "  Позвонить за час до доставки.\nВъезд со двора.  ",
        ])->assertRedirectContains('/placed');

        $this->assertSame("Позвонить за час до доставки.\nВъезд со двора.", Order::sole()->comment);
    }

    public function test_comment_is_optional(): void
    {
        $this->checkout(['delivery_address_id' => $this->address->id, 'comment' => '   '])
            ->assertRedirectContains('/placed');

        $this->assertNull(Order::sole()->comment);
    }

    public function test_too_long_comment_is_refused_and_input_is_kept(): void
    {
        $this->checkout(['delivery_address_id' => $this->address->id, 'comment' => str_repeat('я', 1001)])
            ->assertRedirect('/order')
            ->assertSessionHasErrors(['comment' => 'Комментарий к заказу не длиннее 1000 символов']);

        $this->assertNothingPlaced();
    }

    public function test_comment_and_address_survive_failed_checkout(): void
    {
        $second = DeliveryAddress::createFor($this->client->organization, [
            'title' => 'Магазин', 'address' => 'г. Минск, пр. Независимости, 50',
        ]);

        $this->product->update(['price' => 120]);

        $response = $this->actingAs($this->client)
            ->from('/order')
            ->followingRedirects()
            ->post('/order/checkout', ['delivery_address_id' => $second->id, 'comment' => 'Въезд со двора']);

        $response->assertOk()
            ->assertSee('Оформление заказа невозможно до устранения выявленных ошибок')
            ->assertSee('Въезд со двора');

        $this->assertMatchesRegularExpression(
            '/value="' . $second->id . '"[^>]*checked/s',
            $response->getContent()
        );
    }

    // --- Форма в корзине ---

    public function test_cart_offers_addresses_of_own_organization_with_default_preselected(): void
    {
        $second = DeliveryAddress::createFor($this->client->organization, [
            'title' => 'Магазин', 'address' => 'г. Минск, пр. Независимости, 50',
        ]);
        DeliveryAddress::createFor(Organization::factory()->create(), [
            'title' => 'Чужой склад', 'address' => 'г. Брест',
        ]);

        $response = $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('Адрес доставки')
            ->assertSee('Склад на Промышленной')
            ->assertSee('Магазин')
            ->assertSee('Комментарий к заказу')
            ->assertDontSee('Чужой склад');

        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/value="' . $this->address->id . '"[^>]*checked/s', $html);
        $this->assertDoesNotMatchRegularExpression('/value="' . $second->id . '"[^>]*checked/s', $html);
    }

    public function test_cart_without_addresses_suggests_adding_one(): void
    {
        $this->address->remove();

        $response = $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('У организации нет адресов доставки')
            ->assertSee('href="/addresses/create"', false);

        $this->assertMatchesRegularExpression('/<button\s+disabled/', $response->getContent());
    }

    // --- Карточки заказа ---

    public function test_address_and_comment_are_shown_in_client_and_admin_cards(): void
    {
        $this->checkout(['delivery_address_id' => $this->address->id, 'comment' => 'Въезд со двора'])
            ->assertRedirectContains('/placed');

        $order = Order::sole();

        foreach ([
            [$this->client, "/order/{$order->id}"],
            [User::factory()->admin()->create(), "/admin/order/{$order->id}"],
        ] as [$user, $url]) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSee('Адрес доставки')
                ->assertSee('Склад на Промышленной')
                ->assertSee('г. Минск, ул. Промышленная, 10')
                ->assertSee('Иван Кладовщик')
                ->assertSee('+375 29 111-22-33')
                ->assertSee('Комментарий к заказу')
                ->assertSee('Въезд со двора');
        }
    }

    public function test_old_order_without_address_opens(): void
    {
        $order = Order::create([
            'number'          => 'SM-2026-000001',
            'user_id'         => $this->client->id,
            'organization_id' => $this->client->organization_id,
            'status'          => 'new',
            'total'           => 100,
            'placed_at'       => now(),
        ]);

        $this->actingAs($this->client)->get("/order/{$order->id}")->assertOk()->assertSee('Не указан');

        $this->actingAs(User::factory()->admin()->create())->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Не указан');
    }
}
