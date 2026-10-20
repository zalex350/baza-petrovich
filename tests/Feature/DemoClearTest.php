<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Category;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * demo:clear — удаляет только то, что создал DemoSeeder.
 */
class DemoClearTest extends TestCase
{
    use RefreshDatabase;

    private Product $real;

    private User $realClient;

    private Order $realOrder;

    protected function setUp(): void
    {
        parent::setUp();

        // Реальные данные, которые не должны пострадать
        $this->real = Product::create(['name' => 'Реальный смеситель', 'sku' => 'REAL-1', 'price' => 50, 'stock' => 20,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true, 'is_published' => true]);

        $org = Organization::factory()->create();
        $this->realClient = User::factory()->create(['organization_id' => $org->id]);
        $this->realOrder = Order::create(['user_id' => $this->realClient->id, 'organization_id' => $org->id, 'status' => OrderStatus::New]);
        OrderItem::create(['order_id' => $this->realOrder->id, 'product_id' => $this->real->id, 'quantity' => 1, 'price' => 50]);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
    }

    public function test_removes_demo_data_and_keeps_the_rest(): void
    {
        $this->assertGreaterThan(0, Order::where('comment', 'like', DemoSeeder::MARK . '%')->count());

        $this->artisan('demo:clear', ['--force' => true])
            ->expectsOutputToContain('Демо-данные удалены')
            ->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', '%@demo.test')->count());
        $this->assertSame(0, Organization::whereIn('unp', DemoSeeder::ORGANIZATION_UNPS)->count());
        $this->assertSame(0, Order::where('comment', 'like', DemoSeeder::MARK . '%')->count());
        $this->assertSame(0, Product::withTrashed()->whereIn('sku', DemoSeeder::PRODUCT_SKUS)->count());
        $this->assertNull(Category::where('slug', DemoSeeder::CATEGORY_SLUG)->first());
        $this->assertSame(0, DeliveryAddress::count());
        $this->assertSame(0, Cart::whereNotIn('user_id', [$this->realClient->id])->count());

        // Реальное — на месте
        $this->assertNotNull($this->real->fresh());
        $this->assertNotNull($this->realClient->fresh());
        $this->assertNotNull($this->realOrder->fresh());
        $this->assertTrue(Category::where('slug', 'smesiteli')->exists());
    }

    public function test_returns_stock_of_real_products_from_demo_orders(): void
    {
        // Тестировщик под демо-клиентом заказал реальный товар
        $demoClient = User::where('email', 'ivanov@demo.test')->first();
        $order = Order::create(['user_id' => $demoClient->id, 'organization_id' => $demoClient->organization_id, 'status' => OrderStatus::Confirmed]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $this->real->id, 'quantity' => 3, 'price' => 50]);
        $this->real->decrement('stock', 3);

        $this->artisan('demo:clear', ['--force' => true])
            ->expectsOutputToContain('возвращено единиц реальных товаров: 3')
            ->assertSuccessful();

        $this->assertSame(20, (int) $this->real->fresh()->stock);
    }

    public function test_asks_for_confirmation(): void
    {
        $this->artisan('demo:clear')
            ->expectsConfirmation('Удалить эти данные? Отменить будет нельзя', 'no')
            ->expectsOutputToContain('Ничего не удалено')
            ->assertFailed();

        $this->assertSame(5, User::where('email', 'like', '%@demo.test')->count());
    }

    public function test_nothing_to_clear_second_time(): void
    {
        $this->artisan('demo:clear', ['--force' => true])->assertSuccessful();
        $this->artisan('demo:clear', ['--force' => true])->expectsOutputToContain('Демо-данных нет')->assertSuccessful();
    }

    public function test_does_not_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:clear', ['--force' => true])
            ->expectsOutputToContain('только на тестовом стенде')
            ->assertFailed();

        $this->assertSame(5, User::where('email', 'like', '%@demo.test')->count());
    }
}
