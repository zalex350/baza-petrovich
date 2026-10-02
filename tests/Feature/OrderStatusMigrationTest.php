<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Перевод строковых статусов в OrderStatus (2026_10_03_100002_migrate_order_statuses).
 * «Старое» состояние воспроизводится вставкой строк напрямую.
 */
class OrderStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_03_100002_migrate_order_statuses.php');
    }

    private function legacyOrder(string $status, ?int $userId = null): int
    {
        return DB::table('orders')->insertGetId([
            'user_id'    => $userId ?? $this->user->id,
            'status'     => $status,
            'total'      => 100,
            'placed_at'  => '2026-09-01 10:00:00',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-05 12:00:00',
        ]);
    }

    public function test_old_statuses_are_mapped_to_enum_values(): void
    {
        $new = $this->legacyOrder('new');
        $processing = $this->legacyOrder('processing');
        $shipped = $this->legacyOrder('shipped');
        $deleted = $this->legacyOrder('deleted');

        $this->migration()->up();

        $statuses = DB::table('orders')->pluck('status', 'id');

        $this->assertSame('new', $statuses[$new]);
        $this->assertSame('in_progress', $statuses[$processing]);
        $this->assertSame('shipped', $statuses[$shipped]);
        $this->assertSame('cancelled', $statuses[$deleted]);
    }

    public function test_deleted_orders_are_marked_as_stock_returned(): void
    {
        $new = $this->legacyOrder('new');
        $deleted = $this->legacyOrder('deleted');

        $this->migration()->up();

        // Остаток вернули ещё при удалении заказа — повторная отмена не должна вернуть его снова
        $this->assertSame('2026-09-05 12:00:00', DB::table('orders')->where('id', $deleted)->value('stock_returned_at'));
        $this->assertNull(DB::table('orders')->where('id', $new)->value('stock_returned_at'));
    }

    public function test_every_order_gets_first_history_record(): void
    {
        $new = $this->legacyOrder('new');
        $deleted = $this->legacyOrder('deleted');
        $orphan = $this->legacyOrder('new', 999);

        $this->migration()->up();

        $history = DB::table('order_status_history')->get()->keyBy('order_id');

        $this->assertCount(3, $history);

        $this->assertNull($history[$new]->from_status);
        $this->assertSame('new', $history[$new]->to_status);
        $this->assertSame($this->user->id, (int) $history[$new]->user_id);
        $this->assertNull($history[$new]->comment);
        $this->assertSame('2026-09-01 10:00:00', $history[$new]->created_at);

        // Кто и когда отменил — неизвестно: автор пуст, дата — последнее изменение заказа
        $this->assertSame('cancelled', $history[$deleted]->to_status);
        $this->assertNull($history[$deleted]->user_id);
        $this->assertSame('2026-09-05 12:00:00', $history[$deleted]->created_at);
        $this->assertNotNull($history[$deleted]->comment);

        // Автор заказа удалён — запись без пользователя, а не ошибка внешнего ключа
        $this->assertNull($history[$orphan]->user_id);
    }

    public function test_up_is_idempotent(): void
    {
        $this->legacyOrder('deleted');
        $this->legacyOrder('new');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(2, DB::table('order_status_history')->count());
        $this->assertSame(['cancelled', 'new'], DB::table('orders')->orderBy('id')->pluck('status')->all());
    }

    public function test_down_returns_old_statuses(): void
    {
        $ids = [];

        foreach (['new', 'processing', 'shipped', 'deleted'] as $status) {
            $ids[$status] = $this->legacyOrder($status);
        }

        $migration = $this->migration();
        $migration->up();

        // Появившиеся после миграции статусы сворачиваются в ближайшие старые
        $confirmed = $this->legacyOrder('confirmed');
        $sent = $this->legacyOrder('sent_to_1c');
        $completed = $this->legacyOrder('completed');

        $migration->down();

        $statuses = DB::table('orders')->pluck('status', 'id');

        foreach ($ids as $status => $id) {
            $this->assertSame($status, $statuses[$id]);
        }

        $this->assertSame('new', $statuses[$confirmed]);
        $this->assertSame('processing', $statuses[$sent]);
        $this->assertSame('shipped', $statuses[$completed]);

        // История остаётся как есть — её убирает down() миграции, создавшей таблицу
        $this->assertSame(4, DB::table('order_status_history')->count());
    }
}
