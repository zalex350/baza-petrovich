<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name'      => 'Grohe Start',
            'sku'       => 'GR-001',
            'price'     => 100,
            'stock'     => 10,
            'is_active' => true,
        ]);
    }

    private function fillCart(User $user, int $qty = 2): void
    {
        $this->actingAs($user)
            ->post("/order/add/{$this->product->id}", ['qty' => $qty])
            ->assertSessionHas('success');
    }

    public function test_checkout_writes_user_organization_to_order(): void
    {
        $organization = Organization::factory()->create();
        $client = User::factory()->create(['organization_id' => $organization->id]);

        $this->fillCart($client);

        $this->actingAs($client)->post('/order/checkout')->assertRedirect('/orders');

        $order = Order::where('user_id', $client->id)->first();

        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertSame($organization->id, $order->organization_id);
        $this->assertEquals(200, $order->total);
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_client_without_organization_cannot_checkout(): void
    {
        $client = User::factory()->create(['organization_id' => null]);

        $this->fillCart($client);

        $this->actingAs($client)
            ->from('/order')
            ->post('/order/checkout')
            ->assertRedirect('/order')
            ->assertSessionHas('error', 'Пользователь не привязан к организации');

        // Заказ не создан, корзина осталась нетронутой
        $this->assertSame(0, Order::count());
        $this->assertSame(1, $client->cart->items()->count());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_error_is_shown_in_cart(): void
    {
        $client = User::factory()->create(['organization_id' => null]);

        $this->fillCart($client);

        $this->actingAs($client)
            ->from('/order')
            ->followingRedirects()
            ->post('/order/checkout')
            ->assertSee('Пользователь не привязан к организации');
    }

    public function test_admin_without_organization_can_checkout(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => null]);

        $this->fillCart($admin);

        $this->actingAs($admin)->post('/order/checkout')->assertRedirect('/orders');

        $order = Order::where('user_id', $admin->id)->first();

        $this->assertSame(Order::STATUS_NEW, $order->status);
        $this->assertNull($order->organization_id);
    }

    public function test_organization_rules_depend_on_role(): void
    {
        $this->assertContains('required', User::organizationRules('client'));
        $this->assertContains('nullable', User::organizationRules('admin'));
        $this->assertContains('nullable', User::organizationRules(null));
    }
}
