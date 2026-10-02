<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * order_items.product_id — nullOnDelete: физическое удаление товара не стирает
 * строки оформленных заказов, позиция живёт на снимке.
 */
class OrderItemWithoutProductTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Product $gone;

    private Product $kept;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->gone = Product::create(['name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 10, 'is_active' => true]);
        $this->kept = Product::create(['name' => 'Hansgrohe Logis', 'sku' => 'HG-002', 'price' => 50, 'stock' => 10, 'is_active' => true]);

        $this->actingAs($this->client)->post("/order/add/{$this->gone->id}", ['qty' => 2]);
        $this->actingAs($this->client)->post("/order/add/{$this->kept->id}", ['qty' => 1]);
        $this->actingAs($this->client)->post('/order/checkout')->assertRedirect('/orders');

        $this->order = Order::sole();

        $this->gone->forceDelete();
    }

    public function test_order_item_survives_physical_product_deletion(): void
    {
        $items = $this->order->items()->orderBy('id')->get();

        $this->assertCount(2, $items);

        $this->assertNull($items[0]->product_id);
        $this->assertSame('Grohe Start', $items[0]->product_name);
        $this->assertSame('GR-001', $items[0]->product_sku);
        $this->assertEquals(100, $items[0]->price);
        $this->assertSame(2, $items[0]->quantity);

        $this->assertEquals(250, $this->order->fresh()->total);
    }

    public function test_client_and_admin_cards_show_item_from_snapshot(): void
    {
        $this->actingAs($this->client)->get("/order/{$this->order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('GR-001')
            ->assertSee('Hansgrohe Logis')
            ->assertSee('История статусов');

        $this->actingAs($this->client)->get('/orders')->assertOk()->assertSee($this->order->number);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/admin/order/{$this->order->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertSee('GR-001')
            ->assertSee('История статусов');

        $this->actingAs($admin)->get('/admin/orders')->assertOk()->assertSee($this->order->number);
    }

    public function test_reorder_skips_item_without_product_with_warning(): void
    {
        $this->actingAs($this->client)
            ->post("/order/{$this->order->id}/reorder")
            ->assertRedirect('/order')
            ->assertSessionHas('warning', 'недоступен: Grohe Start');

        $item = CartItem::sole();
        $this->assertSame($this->kept->id, $item->product_id);
        $this->assertSame(1, $item->quantity);
    }

    public function test_status_change_and_cancel_work_for_order_with_missing_product(): void
    {
        $this->actingAs($this->client)
            ->post("/order/{$this->order->id}/cancel")
            ->assertRedirect('/orders');

        $this->assertSame(OrderStatus::Cancelled, $this->order->fresh()->status);
        // Оставшемуся товару остаток возвращён; удалённому возвращать некуда
        $this->assertSame(10, $this->kept->fresh()->stock);
        $this->assertSame(2, $this->order->statusHistory()->count());
    }
}
