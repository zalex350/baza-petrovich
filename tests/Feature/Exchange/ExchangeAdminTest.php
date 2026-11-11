<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeToken;
use App\Models\User;
use App\Support\ExchangeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Админка «Обмен с 1С»: журнал (фильтры, ошибки, файл), токены, IP, настройки.
 */
class ExchangeAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        $this->admin = User::factory()->admin()->create();
    }

    private function job(ExchangeJobType $type, ExchangeJobStatus $status, string $receivedAt): ExchangeJob
    {
        $job = new ExchangeJob(['type' => $type, 'direction' => 'import', 'status' => $status, 'received_at' => $receivedAt, 'mode' => 'delta']);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        $job->file_size = 9;
        Storage::disk('exchange')->put($job->file_path, "guid;sku\n");
        $job->save();

        return $job;
    }

    public function test_journal_filters_by_type_status_and_date(): void
    {
        $catalog = $this->job(ExchangeJobType::Catalog, ExchangeJobStatus::Done, '2026-11-10 10:00:00');
        $prices = $this->job(ExchangeJobType::PricesStock, ExchangeJobStatus::Failed, '2026-11-11 10:00:00');

        $this->actingAs($this->admin)->get('/admin/exchange')->assertOk()
            ->assertSee("data-exchange-job=\"{$catalog->id}\"", false)
            ->assertSee("data-exchange-job=\"{$prices->id}\"", false);

        foreach (['?type=prices-stock', '?status=failed', '?from=2026-11-11', '?to=2026-11-11&from=2026-11-11'] as $query) {
            $this->actingAs($this->admin)->get('/admin/exchange'.$query)->assertOk()
                ->assertSee("data-exchange-job=\"{$prices->id}\"", false)
                ->assertDontSee("data-exchange-job=\"{$catalog->id}\"", false);
        }

        $this->actingAs($this->admin)->get('/admin/exchange?type=bogus&from=вчера')->assertOk()
            ->assertSee("data-exchange-job=\"{$catalog->id}\"", false);
    }

    public function test_job_page_lists_row_errors_and_downloads_file(): void
    {
        $job = $this->job(ExchangeJobType::Catalog, ExchangeJobStatus::Done, '2026-11-10 10:00:00');
        $job->errors()->create(['line' => 418, 'guid' => '8c4f5e1d', 'sku' => 'TIM-BL5800', 'message' => 'Товар с таким GUID не найден']);

        $this->actingAs($this->admin)->get("/admin/exchange/job/{$job->id}")->assertOk()
            ->assertSee('Товар с таким GUID не найден')
            ->assertSee('TIM-BL5800')
            ->assertSee("/admin/exchange/job/{$job->id}/file", false);

        $this->actingAs($this->admin)->get("/admin/exchange/job/{$job->id}/file")->assertOk()
            ->assertDownload('catalog_2026-11-10_130000.csv'); // 10:00 UTC — 13:00 по Минску

        Storage::disk('exchange')->delete($job->file_path);
        $this->actingAs($this->admin)->get("/admin/exchange/job/{$job->id}/file")->assertNotFound();
    }

    public function test_token_is_shown_once_and_can_be_revoked(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/exchange/tokens', ['name' => '1С стенд'])->assertOk()
            ->assertSee('data-new-token', false);

        $token = ExchangeToken::sole();
        preg_match('/sm1c_[A-Za-z0-9]{48}/', $response->getContent(), $m);
        $this->assertSame($token->token_hash, hash('sha256', $m[0]));
        $this->assertSame($this->admin->id, $token->created_by);

        // Повторно — только начало токена
        $this->actingAs($this->admin)->get('/admin/exchange?tab=tokens')->assertOk()
            ->assertDontSee($m[0])->assertSee($token->prefix.'…')->assertDontSee('data-new-token', false);

        $this->actingAs($this->admin)->post("/admin/exchange/token/{$token->id}/revoke")->assertRedirect('/admin/exchange?tab=tokens');
        $this->assertTrue($token->fresh()->isRevoked());
        $this->assertNull(ExchangeToken::findActive($m[0]));

        $this->actingAs($this->admin)->post('/admin/exchange/tokens', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_ip_list_add_validate_delete(): void
    {
        $this->actingAs($this->admin)->get('/admin/exchange?tab=ips')->assertOk()->assertSee('Список пуст — API закрыт');

        $this->actingAs($this->admin)->post('/admin/exchange/ips', ['ip' => ' 203.0.113.0/24 ', 'comment' => 'Сервер 1С'])
            ->assertRedirect('/admin/exchange?tab=ips');
        $this->actingAs($this->admin)->post('/admin/exchange/ips', ['ip' => '2001:db8::1']);

        foreach (['300.1.1.1', '203.0.113.0/33', 'сервер', '10.0.0.1/'] as $bad) {
            $this->actingAs($this->admin)->post('/admin/exchange/ips', ['ip' => $bad])->assertSessionHasErrors('ip');
        }
        $this->actingAs($this->admin)->post('/admin/exchange/ips', ['ip' => '2001:db8::1'])->assertSessionHasErrors('ip');

        $this->assertSame(['2001:db8::1', '203.0.113.0/24'], ExchangeIp::orderBy('ip')->pluck('ip')->all());
        $this->assertTrue(ExchangeIp::allows('203.0.113.5'));

        $ip = ExchangeIp::where('ip', '203.0.113.0/24')->sole();
        $this->actingAs($this->admin)->post("/admin/exchange/ip/{$ip->id}/delete")->assertRedirect('/admin/exchange?tab=ips');
        $this->assertFalse(ExchangeIp::allows('203.0.113.5'));
    }

    public function test_settings_save_and_validate(): void
    {
        $this->actingAs($this->admin)->get('/admin/exchange?tab=settings')->assertOk()->assertSee('data-exchange-settings', false);

        $values = ['exchange_full_missing_percent' => 15, 'exchange_error_rate_percent' => 3, 'exchange_stale_prices_hours' => 4,
            'exchange_pending_orders_minutes' => 45, 'exchange_alert_interval_minutes' => 120];

        $this->actingAs($this->admin)->post('/admin/exchange/settings', $values + ['exchange_alert_pending_orders' => '1'])
            ->assertRedirect('/admin/exchange?tab=settings');

        $this->assertSame(0.15, ExchangeSettings::fullMissingShare());
        $this->assertSame(120, ExchangeSettings::int('exchange_alert_interval_minutes'));
        $this->assertTrue(ExchangeSettings::pendingOrdersAlert());

        $this->actingAs($this->admin)->post('/admin/exchange/settings', ['exchange_full_missing_percent' => 0] + $values)
            ->assertSessionHasErrors('exchange_full_missing_percent');
    }

    public function test_settings_defaults(): void
    {
        $this->assertSame(0.10, ExchangeSettings::fullMissingShare());
        $this->assertSame(0.05, ExchangeSettings::errorRateShare());
        $this->assertSame(60, ExchangeSettings::int('exchange_alert_interval_minutes'));
        $this->assertFalse(ExchangeSettings::pendingOrdersAlert());
    }

    public function test_exchange_admin_is_for_admins_only(): void
    {
        $this->get('/admin/exchange')->assertRedirect(route('login'));

        $client = User::factory()->create();

        $this->actingAs($client)->get('/admin/exchange')->assertForbidden();
        $this->actingAs($client)->post('/admin/exchange/tokens', ['name' => 'x'])->assertForbidden();
    }

    public function test_menu_has_exchange_item_and_user_card_has_notify_flag(): void
    {
        $this->actingAs($this->admin)->get('/admin/exchange')->assertSee('Обмен с 1С');
        $this->actingAs($this->admin)->get("/admin/user/{$this->admin->id}")->assertSee('Сбои обмена с 1С');
    }
}
