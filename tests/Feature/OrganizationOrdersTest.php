<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR-113, BR-114: клиент видит заказы всей своей организации.
 */
class OrganizationOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $client;

    private User $colleague;

    private User $stranger;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $this->client = User::factory()->create(['name' => 'Иван Клиентов', 'organization_id' => $this->organization->id]);
        $this->colleague = User::factory()->create(['name' => 'Пётр Коллегин', 'organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create([
            'name'            => 'Олег Чужой',
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    private function order(User $user, OrderStatus $status = OrderStatus::New): Order
    {
        $this->sequence++;

        return Order::create([
            'number'          => sprintf('SM-2026-%06d', $this->sequence),
            'user_id'         => $user->id,
            'organization_id' => $user->organization_id,
            'status'          => $status,
            'total'           => 100,
            'placed_at'       => now()->subDays(10 - $this->sequence),
        ]);
    }

    public function test_client_sees_orders_of_colleagues_with_author_column(): void
    {
        $own = $this->order($this->client);
        $colleagues = $this->order($this->colleague);
        $foreign = $this->order($this->stranger);

        $this->actingAs($this->client)->get('/orders')
            ->assertOk()
            ->assertSee('Оформил')
            ->assertSee($own->number)
            ->assertSee($colleagues->number)
            ->assertSee('Пётр Коллегин')
            ->assertDontSee($foreign->number)
            ->assertDontSee('Олег Чужой');
    }

    public function test_client_opens_colleagues_order_but_not_foreign(): void
    {
        $colleagues = $this->order($this->colleague);
        $foreign = $this->order($this->stranger);

        $this->actingAs($this->client)->get("/order/{$colleagues->id}")
            ->assertOk()
            ->assertSee($colleagues->number)
            ->assertSee('Пётр Коллегин');

        $this->actingAs($this->client)->get("/order/{$foreign->id}")->assertNotFound();
    }

    public function test_reorder_is_allowed_for_colleagues_order_and_not_for_foreign(): void
    {
        $product = Product::create(['name' => 'Grohe', 'sku' => 'GR-1', 'price' => 100, 'stock' => 10, 'is_active' => true]);

        $colleagues = $this->order($this->colleague);
        $colleagues->items()->create(['product_id' => $product->id, 'product_name' => 'Grohe', 'quantity' => 2, 'price' => 100]);

        $foreign = $this->order($this->stranger);

        $this->actingAs($this->client)->post("/order/{$colleagues->id}/reorder")->assertRedirect('/order');
        $this->assertSame(2, $this->client->cart->items()->sole()->quantity);

        $this->actingAs($this->client)->post("/order/{$foreign->id}/reorder")->assertNotFound();
    }

    public function test_user_without_organization_sees_only_own_orders(): void
    {
        $loner = User::factory()->create(['organization_id' => null]);
        $other = User::factory()->create(['organization_id' => null]);

        $own = $this->order($loner);
        $others = $this->order($other);

        $this->actingAs($loner)->get('/orders')
            ->assertOk()
            ->assertSee($own->number)
            ->assertDontSee($others->number);

        $this->actingAs($loner)->get("/order/{$others->id}")->assertNotFound();
    }

    public function test_order_stays_with_organization_when_author_moves(): void
    {
        $order = $this->order($this->colleague);

        // Коллега перешёл в другую организацию: заказ остался за прежней
        $this->colleague->update(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->client)->get('/orders')->assertSee($order->number);
        $this->actingAs($this->colleague->fresh())->get('/orders')->assertDontSee($order->number);
    }
}
