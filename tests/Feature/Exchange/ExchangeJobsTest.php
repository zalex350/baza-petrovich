<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Enums\OrderStatus;
use App\Models\ExchangeJob;
use App\Models\ExchangeJobError;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ExchangeAlertNotification;
use App\Services\Exchange\ExchangeAlerts;
use App\Services\Exchange\ExchangeHandler;
use App\Services\Exchange\ExchangeJobs;
use App\Services\Exchange\ExchangeResult;
use App\Services\Exchange\StubHandler;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Обработка заданий обмена, ошибки по строкам, оповещения администраторам,
 * очистка архива и расписание (docs/1C_EXCHANGE_SPEC.md, раздел 8).
 */
class ExchangeJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
        Cache::flush();

        $this->admin = User::factory()->admin()->create();
        User::factory()->admin()->create(['notify_exchange' => false]);
    }

    private function job(array $attributes = [], ?string $content = "guid;sku\n"): ExchangeJob
    {
        $job = new ExchangeJob($attributes + [
            'type' => ExchangeJobType::Catalog, 'direction' => 'import', 'mode' => 'delta',
            'status' => ExchangeJobStatus::Queued, 'received_at' => now(),
        ]);
        $job->id = $job->newUniqueId();

        if ($content !== null) {
            $job->file_path = $job->received_at->format('Y/m')."/{$job->id}.csv";
            Storage::disk('exchange')->put($job->file_path, $content);
        }

        $job->save();

        return $job;
    }

    /** Обработчик-подмена: $rows строк, $errors из них с ошибкой */
    private function handler(int $rows, int $errors): void
    {
        $this->app->bind(StubHandler::class, fn () => new class($rows, $errors) implements ExchangeHandler
        {
            public function __construct(private int $rows, private int $errors) {}

            public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void
            {
                $result->total = $this->rows;
                $result->ok = $this->rows - $this->errors;

                for ($i = 0; $i < $this->errors; $i++) {
                    $result->error($i + 2, "g-{$i}", "SKU-{$i}", 'Товар с таким GUID не найден');
                }
            }
        });
    }

    private function process(ExchangeJob $job): ExchangeJob
    {
        app(ExchangeJobs::class)->process($job);

        return $job->fresh();
    }

    public function test_done_job_stores_counters_and_row_errors(): void
    {
        $this->handler(10, 1);

        $job = $this->process($this->job());

        $this->assertSame(ExchangeJobStatus::Done, $job->status);
        $this->assertSame([10, 9, 0, 1], [$job->rows_total, $job->rows_ok, $job->rows_skipped, $job->rows_error]);
        $this->assertSame(['line' => 2, 'guid' => 'g-0', 'sku' => 'SKU-0'], ExchangeJobError::sole()->only(['line', 'guid', 'sku']));
        $this->assertNotNull($job->started_at);

        // 10 % ошибок > 5 % — письмо
        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class, fn ($n) => str_contains($n->subject, 'много ошибок'));
        Notification::assertCount(1);
    }

    public function test_error_rate_threshold_comes_from_settings(): void
    {
        Setting::put(['exchange_error_rate_percent' => '20']);
        $this->handler(10, 1);

        $this->process($this->job());

        Notification::assertNothingSent();
    }

    public function test_failed_job_alerts_once_per_interval(): void
    {
        $job = $this->process($this->job(content: null));

        $this->assertSame(ExchangeJobStatus::Failed, $job->status);
        $this->assertSame('Файл задания не найден в архиве', $job->message);
        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class, fn ($n) => str_contains($n->subject, 'не обработано'));

        // Второй сбой в пределах интервала — без письма
        $this->process($this->job(content: null));
        Notification::assertCount(1);
    }

    public function test_handler_exception_fails_job(): void
    {
        $this->app->bind(StubHandler::class, fn () => new class implements ExchangeHandler
        {
            public function handle(ExchangeJob $job, string $path, ExchangeResult $result): void
            {
                throw new \RuntimeException('Не хватает колонки price');
            }
        });

        $job = $this->process($this->job());

        $this->assertSame([ExchangeJobStatus::Failed, 'Не хватает колонки price'], [$job->status, $job->message]);
        $this->assertSame(0, ExchangeJobError::count());
    }

    public function test_only_queued_job_is_processed(): void
    {
        $job = $this->job(['status' => ExchangeJobStatus::Done]);
        $this->handler(5, 0);

        $this->assertSame(0, $this->process($job)->rows_total);
    }

    public function test_stale_prices_alert_only_after_first_prices_job(): void
    {
        app(ExchangeAlerts::class)->check();
        Notification::assertNothingSent();

        $this->job(['type' => ExchangeJobType::PricesStock, 'received_at' => now()->subMinutes(90), 'status' => ExchangeJobStatus::Done]);
        app(ExchangeAlerts::class)->check();
        Notification::assertNothingSent();

        $this->travel(31)->minutes();
        app(ExchangeAlerts::class)->check();
        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class, fn ($n) => str_contains($n->subject, 'не приходили больше 2 ч'));
    }

    public function test_pending_orders_alert_is_off_until_enabled(): void
    {
        $order = Order::create(['user_id' => $this->admin->id, 'status' => OrderStatus::New, 'number' => 'SM-2026-000001', 'placed_at' => now()->subHour()]);

        app(ExchangeAlerts::class)->check();
        Notification::assertNothingSent();

        Setting::put(['exchange_alert_pending_orders' => '1']);
        app(ExchangeAlerts::class)->check();
        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class,
            fn ($n) => str_contains($n->subject, 'не переданы в 1С') && str_contains(implode(' ', $n->lines), $order->number));
    }

    public function test_prune_deletes_old_files_and_keeps_journal(): void
    {
        $old = $this->job(['received_at' => now()->subDays(91)]);
        $fresh = $this->job(['received_at' => now()->subDays(10)]);

        $this->artisan('exchange:prune')->assertSuccessful()->expectsOutputToContain('Удалено файлов: 1');

        $old->refresh();
        $this->assertNull($old->file_path);
        $this->assertNotNull($old->file_pruned_at);
        $this->assertFalse($old->hasFile());
        $this->assertTrue($fresh->fresh()->hasFile());
        $this->assertSame(2, ExchangeJob::count());
    }

    public function test_scheduler_runs_prune_and_check(): void
    {
        $commands = collect(app(Schedule::class)->events())->map->command->implode("\n");

        $this->assertStringContainsString('exchange:prune', $commands);
        $this->assertStringContainsString('exchange:check', $commands);
    }
}
