<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поиск и фильтры в админке заказов (SRS §10.7.4).
 */
class AdminOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $alpha;

    private Order $beta;

    private Order $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $santeh = Organization::factory()->create(['name' => 'ООО Сантехторг', 'unp' => '190000001']);
        $akva = Organization::factory()->create(['name' => 'ЧУП Аквамир', 'unp' => '290000002']);

        $ivan = User::factory()->create(['name' => 'Иван Клиентов', 'email' => 'ivan@santeh.by', 'organization_id' => $santeh->id]);
        $petr = User::factory()->create(['name' => 'Пётр Закупкин', 'email' => 'petr@akva.by', 'organization_id' => $akva->id]);

        $this->alpha = $this->order('SM-2026-000001', $ivan, OrderStatus::New, '2026-09-01 10:00:00');
        $this->beta = $this->order('SM-2026-000002', $petr, OrderStatus::Shipped, '2026-09-15 20:30:00'); // 23:30 по Минску
        $this->gamma = $this->order('SM-2026-000013', $ivan, OrderStatus::Cancelled, '2026-10-01 09:00:00');
    }

    private function order(string $number, User $user, OrderStatus $status, string $placedAt): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'status' => $status,
            'total' => 100,
            'placed_at' => $placedAt,
        ]);
    }

    /**
     * @return list<int>
     */
    private function found(array $query): array
    {
        $response = $this->actingAs($this->admin)->get('/admin/orders?'.http_build_query($query))->assertOk();

        return $response->viewData('orders')->pluck('id')->sort()->values()->all();
    }

    public function test_without_filters_all_orders_are_listed_newest_first(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/orders')->assertOk();

        $this->assertSame(
            [$this->gamma->id, $this->beta->id, $this->alpha->id],
            $response->viewData('orders')->pluck('id')->all()
        );

        $response->assertSee('ООО Сантехторг')->assertSee('ЧУП Аквамир')->assertSee('Клиент');
    }

    public function test_search_by_number(): void
    {
        $this->assertSame([$this->gamma->id], $this->found(['q' => 'SM-2026-000013']));
        $this->assertSame([$this->gamma->id], $this->found(['q' => '000013']));
        $this->assertSame([$this->alpha->id, $this->beta->id, $this->gamma->id], $this->found(['q' => 'SM-2026']));
    }

    public function test_search_by_organization_name_and_unp(): void
    {
        $this->assertSame([$this->beta->id], $this->found(['q' => 'Аквамир']));
        $this->assertSame([$this->alpha->id, $this->gamma->id], $this->found(['q' => '190000001']));
    }

    public function test_search_by_user_name_and_email(): void
    {
        $this->assertSame([$this->beta->id], $this->found(['q' => 'Закупкин']));
        $this->assertSame([$this->alpha->id, $this->gamma->id], $this->found(['q' => 'ivan@santeh.by']));
    }

    public function test_search_without_matches_shows_message(): void
    {
        $this->actingAs($this->admin)->get('/admin/orders?q=несуществующий')
            ->assertOk()
            ->assertSee('Заказы не найдены')
            ->assertSee('Сбросить');
    }

    public function test_filter_by_status(): void
    {
        $this->assertSame([$this->beta->id], $this->found(['status' => 'shipped']));
        $this->assertSame([$this->gamma->id], $this->found(['status' => 'cancelled']));
        $this->assertSame([], $this->found(['status' => 'completed']));
    }

    public function test_filter_by_period_uses_minsk_days(): void
    {
        // 21:30 UTC 30.09 — 00:30 01.10 по Минску: в фильтре это 01.10, не 30.09
        $late = $this->order('SM-2026-000020', $this->beta->user, OrderStatus::New, '2026-09-30 21:30:00');

        $this->assertContains($late->id, $this->found(['date_from' => '2026-10-01', 'date_to' => '2026-10-01']));
        $this->assertNotContains($late->id, $this->found(['date_from' => '2026-09-30', 'date_to' => '2026-09-30']));
    }

    public function test_filter_by_period_is_inclusive(): void
    {
        $this->assertSame([$this->beta->id, $this->gamma->id], $this->found(['date_from' => '2026-09-15']));
        $this->assertSame([$this->alpha->id, $this->beta->id], $this->found(['date_to' => '2026-09-15']));
        $this->assertSame([$this->beta->id], $this->found(['date_from' => '2026-09-02', 'date_to' => '2026-09-30']));
    }

    public function test_filters_combine(): void
    {
        $this->assertSame([$this->gamma->id], $this->found(['q' => 'Сантехторг', 'status' => 'cancelled']));
        $this->assertSame([], $this->found(['q' => 'Аквамир', 'status' => 'new']));
        $this->assertSame([$this->alpha->id], $this->found(['q' => 'Иван', 'date_to' => '2026-09-10']));
    }

    public function test_form_keeps_entered_filters(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/orders?q=Аквамир&status=shipped&date_from=2026-09-01')
            ->assertOk()
            ->assertSee('value="Аквамир"', false)
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('<option value="shipped" selected', false);
    }

    public function test_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/orders')
            ->get('/admin/orders?status=draft')
            ->assertRedirect('/admin/orders')
            ->assertSessionHasErrors('status');
    }

    public function test_search_is_admin_only(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/admin/orders?q=SM')->assertForbidden();
    }
}
