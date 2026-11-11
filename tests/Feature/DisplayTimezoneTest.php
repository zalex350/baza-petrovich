<?php

namespace Tests\Feature;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeJob;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use App\Notifications\ExchangeAlertNotification;
use App\Services\Exchange\ExchangeAlerts;
use App\Support\ExchangeTime;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Время для людей — Europe/Minsk (app.display_timezone, LocalTime), в БД — UTC, как прежде:
 * существующие даты не сдвигаются. Обмен с 1С — ExchangeTime, явные пояса.
 */
class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        $this->admin = User::factory()->admin()->create(['notify_exchange' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(string $receivedAt, ExchangeJobStatus $status = ExchangeJobStatus::Done, int $errors = 0): ExchangeJob
    {
        $job = new ExchangeJob([
            'type' => ExchangeJobType::PricesStock, 'direction' => 'import', 'status' => $status, 'mode' => 'delta',
            'received_at' => $receivedAt, 'finished_at' => $receivedAt, 'rows_total' => 10, 'rows_ok' => 10 - $errors, 'rows_error' => $errors,
        ]);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        Storage::disk('exchange')->put($job->file_path, "guid;sku\n");
        $job->save();

        return $job;
    }

    public function test_storage_stays_utc_and_display_is_minsk(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Europe/Minsk', LocalTime::zone());

        Carbon::setTestNow('2026-11-11 16:43:00');
        $job = $this->job(now()->toDateTimeString());

        // В БД — UTC как есть, без сдвига
        $this->assertSame('2026-11-11 16:43:00', DB::table('exchange_jobs')->where('id', $job->id)->value('received_at'));

        // Журнал и карточка задания — 19:43 по Минску
        $this->actingAs($this->admin)->get('/admin/exchange')->assertOk()->assertSee('11.11.2026 19:43')->assertDontSee('16:43');
        $this->actingAs($this->admin)->get("/admin/exchange/job/{$job->id}")->assertOk()->assertSee('11.11.2026 19:43')->assertSee('19:43:00');

        // API обмена — по-прежнему ExchangeTime: +03:00 явно
        $this->assertSame('2026-11-11T19:43:00+03:00', ExchangeTime::format($job->received_at));
    }

    public function test_job_with_row_errors_is_done_with_errors_in_yellow(): void
    {
        $clean = $this->job('2026-11-11 10:00:00');
        $withErrors = $this->job('2026-11-11 11:00:00', errors: 2);
        $failed = $this->job('2026-11-11 12:00:00', ExchangeJobStatus::Failed);

        $this->assertSame(['Готово', 'success'], [$clean->statusLabel(), $clean->statusTone()]);
        $this->assertSame(['Готово с ошибками', 'warning'], [$withErrors->statusLabel(), $withErrors->statusTone()]);
        $this->assertSame(['Ошибка', 'danger'], [$failed->statusLabel(), $failed->statusTone()]);

        $html = $this->actingAs($this->admin)->get('/admin/exchange')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<span(?=[^>]*data-job-status)(?=[^>]*bg-tone-warning-bg)[^>]*>Готово с ошибками</span>#u', $html);

        $this->actingAs($this->admin)->get("/admin/exchange/job/{$withErrors->id}")->assertOk()->assertSee('Готово с ошибками');
        // В API статус — как в спецификации: done
        $this->assertSame('done', $withErrors->status->value);
    }

    public function test_journal_date_filter_uses_minsk_days(): void
    {
        // 21:30 UTC 10.11 — уже 11.11 00:30 по Минску
        $late = $this->job('2026-11-10 21:30:00');

        $this->actingAs($this->admin)->get('/admin/exchange?from=2026-11-11&to=2026-11-11')->assertOk()
            ->assertSee("data-exchange-job=\"{$late->id}\"", false);
        $this->actingAs($this->admin)->get('/admin/exchange?from=2026-11-10&to=2026-11-10')->assertOk()
            ->assertDontSee("data-exchange-job=\"{$late->id}\"", false);
    }

    public function test_exchange_alert_mail_shows_minsk_time(): void
    {
        Notification::fake();

        app(ExchangeAlerts::class)->afterJob($this->job('2026-11-11 16:43:00', ExchangeJobStatus::Failed));

        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class, function ($n) {
            $lines = implode("\n", $n->toMail($this->admin)->introLines);

            return str_contains($lines, '(11.11.2026 19:43)') && ! str_contains($lines, '16:43');
        });
    }

    public function test_assortment_file_name_and_footer_year_use_minsk_date(): void
    {
        // 31.12 22:00 UTC — уже 01.01 по Минску
        Carbon::setTestNow('2026-12-31 22:00:00');
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        ProductClientCode::create([
            'product_id' => Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 1, 'stock' => 1, 'is_active' => true, 'is_published' => true])->id,
            'organization_id' => $client->organization_id, 'code' => 'K-1',
        ]);

        $this->actingAs($client)->get('/account/assortment/download')->assertOk()->assertDownload('assortment-2027-01-01.xlsx');
        $this->get('/')->assertSee('© 2027');
    }

    public function test_order_dates_for_client_are_minsk(): void
    {
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $order = Order::create([
            'number' => 'SM-2026-000001', 'user_id' => $client->id, 'organization_id' => $client->organization_id,
            'status' => 'new', 'total' => 1, 'placed_at' => '2026-11-10 21:30:00',
        ]);

        // Список — день по Минску (11.11), карточка — со временем
        $this->actingAs($client)->get('/orders')->assertOk()->assertSee('11.11.2026')->assertDontSee('10.11.2026');
        $this->actingAs($client)->get("/order/{$order->id}")->assertOk()->assertSee('11.11.2026 00:30');
    }
}
