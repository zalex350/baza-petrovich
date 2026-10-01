<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Перенос данных при разделении корзины и заказа
 * (2026_10_01_100003_migrate_draft_orders_to_carts).
 *
 * Схема к началу теста уже новая, поэтому «старое» состояние воспроизводим
 * вставкой строк напрямую: draft-заказы, заказы без number и позиции без снимка.
 */
class CartOrderSplitMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_100003_migrate_draft_orders_to_carts.php');
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name'      => "Смеситель {$sku}",
            'sku'       => $sku,
            'price'     => 100,
            'stock'     => 10,
            'is_active' => true,
        ], $attributes));
    }

    private function legacyOrder(?int $userId, string $status, string $createdAt, array $items): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id'    => $userId,
            'status'     => $status,
            'total'      => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        foreach ($items as [$product, $quantity, $price]) {
            DB::table('order_items')->insert([
                'order_id'   => $orderId,
                'product_id' => $product->id,
                'quantity'   => $quantity,
                'price'      => $price,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $orderId;
    }

    public function test_draft_orders_become_carts_and_leave_orders_table(): void
    {
        $user = User::factory()->create();
        $a = $this->product('A-1');
        $b = $this->product('B-1');

        $this->legacyOrder($user->id, 'draft', '2026-09-01 10:00:00', [[$a, 2, 100], [$b, 1, 55.5]]);

        $this->migration()->up();

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());

        $cart = DB::table('carts')->where('user_id', $user->id)->first();
        $this->assertNotNull($cart);
        $this->assertSame('2026-09-01 10:00:00', $cart->created_at);

        $items = DB::table('cart_items')->where('cart_id', $cart->id)->get()->keyBy('product_id');
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[$a->id]->quantity);
        $this->assertEquals(100, $items[$a->id]->price_at_add);
        $this->assertSame(1, (int) $items[$b->id]->quantity);
        $this->assertEquals(55.5, $items[$b->id]->price_at_add);
    }

    public function test_several_drafts_of_one_user_are_merged_into_single_cart(): void
    {
        $user = User::factory()->create();
        $a = $this->product('A-1');
        $b = $this->product('B-1');

        $this->legacyOrder($user->id, 'draft', '2026-09-01 10:00:00', [[$a, 2, 100]]);
        $this->legacyOrder($user->id, 'draft', '2026-09-02 10:00:00', [[$a, 3, 120], [$b, 1, 50]]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('carts')->count());

        $items = DB::table('cart_items')->get()->keyBy('product_id');
        $this->assertCount(2, $items);
        $this->assertSame(5, (int) $items[$a->id]->quantity);
        $this->assertEquals(120, $items[$a->id]->price_at_add);
    }

    public function test_draft_without_user_is_dropped(): void
    {
        $a = $this->product('A-1');

        $this->legacyOrder(null, 'draft', '2026-09-01 10:00:00', [[$a, 1, 100]]);
        $this->legacyOrder(999, 'draft', '2026-09-01 10:00:00', [[$a, 1, 100]]);

        $this->migration()->up();

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('carts')->count());
    }

    public function test_draft_with_deleted_product_is_kept_in_cart(): void
    {
        $user = User::factory()->create();
        $a = $this->product('A-1');

        $this->legacyOrder($user->id, 'draft', '2026-09-01 10:00:00', [[$a, 1, 100]]);
        $a->delete();

        $this->migration()->up();

        $this->assertSame(1, DB::table('cart_items')->where('product_id', $a->id)->count());
    }

    public function test_placed_orders_get_number_and_placed_at(): void
    {
        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $a = $this->product('A-1');

        $old       = $this->legacyOrder($user->id, 'shipped', '2025-12-30 09:00:00', [[$a, 1, 100]]);
        $first     = $this->legacyOrder($user->id, 'new', '2026-01-10 09:00:00', [[$a, 1, 100]]);
        $draft     = $this->legacyOrder($user->id, 'draft', '2026-02-01 09:00:00', [[$a, 1, 100]]);
        $deleted   = $this->legacyOrder($user->id, 'deleted', '2026-03-01 09:00:00', [[$a, 1, 100]]);
        $processed = $this->legacyOrder($user->id, 'processing', '2026-09-15 18:30:00', [[$a, 1, 100]]);

        $this->migration()->up();

        $orders = DB::table('orders')->get()->keyBy('id');

        // Нумерация сквозная в пределах года; корзина номер не занимает
        $this->assertSame('SM-2025-000001', $orders[$old]->number);
        $this->assertSame('SM-2026-000001', $orders[$first]->number);
        $this->assertSame('SM-2026-000002', $orders[$deleted]->number);
        $this->assertSame('SM-2026-000003', $orders[$processed]->number);
        $this->assertArrayNotHasKey($draft, $orders->all());

        $this->assertSame('2026-09-15 18:30:00', $orders[$processed]->placed_at);
        $this->assertSame('processing', $orders[$processed]->status);
    }

    public function test_order_items_get_snapshot_including_deleted_products(): void
    {
        $user = User::factory()->create();
        $plain   = $this->product('A-1', ['vat_rate' => 10]);
        $named   = $this->product('B-1', ['display_name' => 'Смеситель для кухни']);
        $trashed = $this->product('C-1');

        $orderId = $this->legacyOrder($user->id, 'new', '2026-09-01 10:00:00', [
            [$plain, 1, 100],
            [$named, 1, 100],
            [$trashed, 1, 100],
        ]);

        $trashed->delete();

        $this->migration()->up();

        $items = DB::table('order_items')->where('order_id', $orderId)->get()->keyBy('product_id');

        $this->assertSame('Смеситель A-1', $items[$plain->id]->product_name);
        $this->assertSame('A-1', $items[$plain->id]->product_sku);
        $this->assertEquals(10, $items[$plain->id]->vat_rate);

        $this->assertSame('Смеситель для кухни', $items[$named->id]->product_name);

        $this->assertSame('Смеситель C-1', $items[$trashed->id]->product_name);
        $this->assertSame('C-1', $items[$trashed->id]->product_sku);
    }

    public function test_migration_is_idempotent_for_already_numbered_orders(): void
    {
        $user = User::factory()->create();
        $a = $this->product('A-1');

        $this->legacyOrder($user->id, 'new', '2026-09-01 10:00:00', [[$a, 1, 100]]);
        $this->migration()->up();

        $late = $this->legacyOrder($user->id, 'new', '2026-09-02 10:00:00', [[$a, 1, 100]]);
        $this->migration()->up();

        $this->assertSame(
            ['SM-2026-000001', 'SM-2026-000002'],
            DB::table('orders')->orderBy('id')->pluck('number')->all()
        );
        $this->assertSame('SM-2026-000002', DB::table('orders')->where('id', $late)->value('number'));
    }

    public function test_down_returns_carts_to_draft_orders(): void
    {
        $user = User::factory()->create();
        $a = $this->product('A-1');
        $b = $this->product('B-1');

        $this->legacyOrder($user->id, 'draft', '2026-09-01 10:00:00', [[$a, 2, 100], [$b, 1, 55.5]]);
        $placed = $this->legacyOrder($user->id, 'new', '2026-09-02 10:00:00', [[$a, 1, 100]]);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame(0, DB::table('carts')->count());
        $this->assertSame(0, DB::table('cart_items')->count());

        $draft = DB::table('orders')->where('status', 'draft')->first();
        $this->assertNotNull($draft);
        $this->assertSame($user->id, (int) $draft->user_id);
        $this->assertNull($draft->number);

        $items = DB::table('order_items')->where('order_id', $draft->id)->get()->keyBy('product_id');
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[$a->id]->quantity);
        $this->assertEquals(55.5, $items[$b->id]->price);

        // Оформленный заказ откат не трогает
        $this->assertSame('new', DB::table('orders')->where('id', $placed)->value('status'));
    }
}
