<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AO-10: пагинация списка заказов в админке, когда заказов больше страницы (50).
 */
class AdminOrderPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function page(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/orders'.$query)->assertOk();
    }

    public function test_orders_over_one_page_are_split_without_gaps_or_repeats(): void
    {
        Order::factory()->count(120)->create();

        $ids = [];
        foreach ([1 => 50, 2 => 50, 3 => 20] as $page => $count) {
            $orders = $this->page("?page={$page}")->viewData('orders');
            $this->assertCount($count, $orders, "страница {$page}");
            $ids = [...$ids, ...$orders->pluck('id')->all()];
        }

        // Все 120, без повторов, новые первыми (как на первой странице без пагинации)
        $this->assertSame(Order::orderByDesc('placed_at')->orderByDesc('id')->pluck('id')->all(), $ids);

        $this->page()
            ->assertSee('href="'.e(url('/admin/orders?page=2')).'"', false)
            ->assertSee('href="'.e(url('/admin/orders?page=3')).'"', false);
        // За последней страницей — пусто, без ошибки
        $this->assertCount(0, $this->page('?page=4')->viewData('orders'));
    }

    public function test_pagination_keeps_filters(): void
    {
        Order::factory()->count(55)->create(['status' => OrderStatus::Shipped]);
        Order::factory()->count(5)->create();

        $this->page('?status=shipped')
            ->assertSee(e(url('/admin/orders?status=shipped&page=2')), false);

        $second = $this->page('?status=shipped&page=2')->viewData('orders');
        $this->assertCount(5, $second);
        $this->assertTrue($second->every(fn (Order $o) => $o->status === OrderStatus::Shipped));
    }

    public function test_single_page_has_no_pagination(): void
    {
        Order::factory()->count(50)->create();

        $this->assertStringNotContainsString('page=2', $this->page()->getContent());
    }
}
