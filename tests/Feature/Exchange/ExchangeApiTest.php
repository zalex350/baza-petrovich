<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeToken;
use App\Support\ExchangeTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * API обмена с 1С v1 (docs/1C_EXCHANGE_SPEC.md, разделы 2 и 8): токен, белый список IP,
 * лимиты, gzip, идемпотентность X-Exchange-Id, приём в архив и очередь, GET /jobs/{id}.
 */
class ExchangeApiTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "guid;sku;article;brand;name;unit;vat_rate;min_qty;qty_step;barcode;group_1c;active\n"
        ."5f1c2b8a-3d4e-11ef-9a1b-0050569a1c01;LEDEME-L4755-2;L4755-2;LEDEME;Смеситель LEDEME L4755-2;шт;20;1;1;;Смесители;1\n";

    private const PRICES = "guid;sku;price;stock;stock_status;updated_at\n"
        ."5f1c2b8a-3d4e-11ef-9a1b-0050569a1c01;L4755-2;832.26;7;in_stock;2026-11-10T18:30:00+03:00\n";

    private ExchangeToken $token;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        ExchangeIp::create(['ip' => '127.0.0.1']);
        [$this->token, $this->plain] = ExchangeToken::issue('1С стенд', null);
    }

    private function api(string $method, string $uri, string $body = '', array $headers = [], ?string $ip = null): TestResponse
    {
        $server = ['REMOTE_ADDR' => $ip ?? '127.0.0.1', 'HTTP_ACCEPT' => 'application/json'];

        if (! array_key_exists('Authorization', $headers)) {
            $headers['Authorization'] = 'Bearer '.$this->plain;
        }

        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $this->call($method, '/api/1c/v1'.$uri, [], [], [], $server, $body);
    }

    private function postCsv(string $uri, string $body, array $headers = []): TestResponse
    {
        return $this->api('POST', $uri, $body, $headers + ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function assertError(TestResponse $response, int $status, string $error): TestResponse
    {
        return $response->assertStatus($status)->assertExactJson(['error' => $error, 'message' => $response->json('message')])
            ->assertJsonPath('error', $error);
    }

    // --- Доступ: токен ---

    public function test_ping_with_token_and_allowed_ip(): void
    {
        $this->api('GET', '/ping')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('token', '1С стенд')
            ->assertJsonPath('ip', '127.0.0.1');

        $this->assertSame('127.0.0.1', $this->token->fresh()->last_used_ip);
        $this->assertNotNull($this->token->fresh()->last_used_at);
    }

    public function test_missing_wrong_or_revoked_token_is_401(): void
    {
        $this->assertError($this->api('GET', '/ping', headers: ['Authorization' => null]), 401, 'unauthorized')
            ->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertError($this->api('GET', '/ping', headers: ['Authorization' => 'Bearer sm1c_wrong']), 401, 'unauthorized');

        $this->token->forceFill(['revoked_at' => now()])->save();
        $this->assertError($this->api('GET', '/ping'), 401, 'unauthorized');
    }

    public function test_only_hash_of_token_is_stored(): void
    {
        $this->assertSame(hash('sha256', $this->plain), $this->token->token_hash);
        $this->assertStringStartsWith('sm1c_', $this->plain);
        $this->assertSame(substr($this->plain, 0, 11), $this->token->prefix);
        $this->assertDatabaseMissing('exchange_tokens', ['token_hash' => $this->plain]);
    }

    // --- Доступ: IP ---

    public function test_ip_outside_list_is_403_and_empty_list_denies_all(): void
    {
        $this->assertError($this->api('GET', '/ping', ip: '198.51.100.7'), 403, 'ip_forbidden');

        ExchangeIp::query()->delete();
        $this->assertError($this->api('GET', '/ping'), 403, 'ip_forbidden');

        // IP проверяется раньше токена: без токена с чужого адреса — тоже 403
        $this->assertError($this->api('GET', '/ping', headers: ['Authorization' => null], ip: '198.51.100.7'), 403, 'ip_forbidden');
    }

    public function test_cidr_subnet_is_allowed(): void
    {
        ExchangeIp::create(['ip' => '203.0.113.0/24']);

        $this->api('GET', '/ping', ip: '203.0.113.77')->assertOk();
        $this->assertError($this->api('GET', '/ping', ip: '203.0.114.1'), 403, 'ip_forbidden');
    }

    public function test_client_ip_behind_proxy_is_the_one_added_by_proxy(): void
    {
        ExchangeIp::create(['ip' => '203.0.113.10']);

        // nginx сервера дописал реальный адрес в конец X-Forwarded-For; REMOTE_ADDR — прокси (TrustProxies)
        $this->api('GET', '/ping', headers: ['X-Forwarded-For' => '198.51.100.1, 203.0.113.10'], ip: '172.18.0.1')
            ->assertOk()->assertJsonPath('ip', '203.0.113.10');

        // Подставленный клиентом разрешённый адрес в начале цепочки не помогает
        $this->assertError($this->api('GET', '/ping', headers: ['X-Forwarded-For' => '203.0.113.10, 198.51.100.1'], ip: '172.18.0.1'), 403, 'ip_forbidden');
    }

    // --- Приём файла и задание ---

    public function test_csv_is_archived_queued_and_stub_processed(): void
    {
        $response = $this->postCsv('/catalog?mode=full', self::CSV)->assertStatus(202)->assertJsonPath('status', 'queued');

        $job = ExchangeJob::findOrFail($response->json('job_id'));
        $this->assertSame(ExchangeJobType::Catalog, $job->type);
        $this->assertSame(['import', 'full', $this->token->id, '127.0.0.1'], [$job->direction, $job->mode, $job->token_id, $job->ip]);
        $this->assertSame($job->received_at->format('Y/m').'/'.$job->id.'.csv', $job->file_path);
        $this->assertSame(self::CSV, Storage::disk('exchange')->get($job->file_path));

        // Заглушка обработчика (этапы 3–5): сразу done, 0 строк
        $this->assertSame(ExchangeJobStatus::Done, $job->status);
        $this->assertSame(0, $job->rows_total);
        $this->assertNotNull($job->finished_at);

        $this->api('GET', "/jobs/{$job->id}")->assertOk()->assertJson([
            'job_id' => $job->id, 'type' => 'catalog', 'status' => 'done',
            'rows_total' => 0, 'rows_ok' => 0, 'rows_skipped' => 0, 'rows_error' => 0, 'errors' => [],
        ])->assertJsonStructure(['received_at', 'finished_at']);
    }

    public function test_prices_stock_and_default_mode(): void
    {
        $id = $this->postCsv('/prices-stock', self::PRICES)->assertStatus(202)->json('job_id');

        $job = ExchangeJob::findOrFail($id);
        $this->assertSame([ExchangeJobType::PricesStock, 'delta'], [$job->type, $job->mode]);
    }

    public function test_bom_is_accepted_and_stripped(): void
    {
        $id = $this->postCsv('/catalog', "\u{FEFF}".self::CSV)->assertStatus(202)->json('job_id');

        $this->assertSame(self::CSV, Storage::disk('exchange')->get(ExchangeJob::find($id)->file_path));
    }

    public function test_catalog_requires_article_and_sku_column_is_optional(): void
    {
        // sku — код товара на сайте; в 1С может не быть поля под него. Артикул производителя обязателен
        $this->assertError($this->postCsv('/catalog', "guid;sku;name;unit;vat_rate;active\nx;LEDEME-A1;z;шт;20;1\n"), 400, 'missing_columns');
        $this->postCsv('/catalog', "guid;article;brand;name;unit;vat_rate;active\nx;A1;LEDEME;z;шт;20;1\n")->assertStatus(202);
    }

    public function test_unreadable_files_are_400_and_not_saved(): void
    {
        $this->assertError($this->api('POST', '/catalog', self::CSV, ['Content-Type' => 'application/xml']), 400, 'unsupported_content_type');
        $this->assertError($this->postCsv('/catalog', "guid;sku;name\nx;y;z\n"), 400, 'missing_columns');
        $this->assertError($this->postCsv('/catalog', mb_convert_encoding(self::CSV, 'Windows-1251', 'UTF-8')), 400, 'bad_encoding');
        $this->assertError($this->postCsv('/catalog', ''), 400, 'empty_body');
        $this->assertError($this->postCsv('/catalog?mode=all', self::CSV), 400, 'bad_mode');
        $this->assertError($this->postCsv('/catalog', self::CSV, ['X-Exchange-Id' => 'не guid']), 400, 'bad_exchange_id');
        $this->assertError($this->postCsv('/catalog', self::CSV, ['Content-Encoding' => 'br']), 400, 'unsupported_encoding');

        $this->assertSame(0, ExchangeJob::count());
        $this->assertSame([], Storage::disk('exchange')->allFiles());
    }

    // --- Идемпотентность ---

    public function test_same_exchange_id_returns_same_job_without_reprocessing(): void
    {
        $id = '0193f2a1-7c5e-7a10-9b2d-3e4f5a6b7c8d';

        $first = $this->postCsv('/catalog', self::CSV, ['X-Exchange-Id' => $id])->assertStatus(202)->json('job_id');
        // Повтор (потерялся ответ): тело даже не читается — тот же job_id
        $this->postCsv('/catalog', 'испорченное тело', ['X-Exchange-Id' => $id])
            ->assertStatus(202)->assertJsonPath('job_id', $first)->assertJsonPath('status', 'done');

        $this->assertSame(1, ExchangeJob::count());
        $this->assertCount(1, Storage::disk('exchange')->allFiles());

        // Тот же id для другого потока — конфликт
        $this->assertError($this->postCsv('/prices-stock', self::PRICES, ['X-Exchange-Id' => $id]), 409, 'exchange_id_conflict');
    }

    // --- gzip и лимиты ---

    public function test_gzip_body_is_unpacked(): void
    {
        $id = $this->postCsv('/catalog', gzencode(self::CSV), ['Content-Encoding' => 'gzip'])->assertStatus(202)->json('job_id');

        $job = ExchangeJob::find($id);
        $this->assertTrue($job->gzip);
        $this->assertSame(self::CSV, Storage::disk('exchange')->get($job->file_path));

        $this->assertError($this->postCsv('/catalog', self::CSV, ['Content-Encoding' => 'gzip']), 400, 'bad_gzip');
        $this->assertError($this->postCsv('/catalog', substr(gzencode(self::CSV), 0, 20), ['Content-Encoding' => 'gzip']), 400, 'bad_gzip');
    }

    public function test_body_over_limit_is_413_before_and_after_unpacking(): void
    {
        config(['shop.exchange_1c.max_body_mb' => 1]);
        $big = self::CSV.str_repeat("x;y;z;b;шт;20;1;1;;g;1\n", 50_000); // ~1,2 МБ

        $this->assertError($this->postCsv('/catalog', $big), 413, 'payload_too_large');
        // Без Content-Length (chunked) — тоже: предел проверяется при чтении
        $this->assertError($this->postCsv('/catalog', $big, ['Content-Length' => null]), 413, 'payload_too_large');
        // Сжатый — маленький, распакованный больше предела («zip-бомба»)
        $this->assertError($this->postCsv('/catalog', gzencode($big, 9), ['Content-Encoding' => 'gzip']), 413, 'payload_too_large');

        $this->assertSame(0, ExchangeJob::count());
    }

    public function test_rate_limit_is_429_with_retry_after(): void
    {
        config(['shop.exchange_1c.rate_limit_per_minute' => 2]);

        $this->api('GET', '/ping')->assertOk();
        $this->api('GET', '/ping')->assertOk();
        $this->assertError($this->api('GET', '/ping'), 429, 'too_many_requests')->assertHeader('Retry-After');
    }

    // --- Ошибки в JSON ---

    public function test_unknown_job_route_and_method_are_json(): void
    {
        $this->assertError($this->api('GET', '/jobs/0193f2a1-0000-7000-8000-000000000000'), 404, 'not_found');
        $this->assertError($this->api('GET', '/nope'), 404, 'not_found');
        $this->assertError($this->api('GET', '/catalog'), 405, 'method_not_allowed');
    }

    public function test_times_are_minsk_in_responses_and_any_offset_on_input(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 15:30:00', 'UTC'));

        $this->api('GET', '/ping')->assertOk()->assertJsonPath('server_time', '2026-11-10T18:30:00+03:00');

        $id = $this->postCsv('/catalog', self::CSV)->json('job_id');
        $this->api('GET', "/jobs/{$id}")->assertJsonPath('received_at', '2026-11-10T18:30:00+03:00');
        // В БД — UTC, как всё приложение
        $this->assertSame('2026-11-10 15:30:00', ExchangeJob::find($id)->getRawOriginal('received_at'));

        foreach (['2026-11-10T18:30:00+03:00', '2026-11-10T15:30:00Z', '2026-11-10T15:30:00+00:00', '2026-11-10T17:30:00+0200', '2026-11-10T18:30:00'] as $input) {
            $this->assertSame('2026-11-10 15:30:00', ExchangeTime::parse($input)?->format('Y-m-d H:i:s'), $input);
        }
        foreach (['10.11.2026 18:30', '2026-13-40T99:00:00+03:00', '', 'вчера'] as $bad) {
            $this->assertNull(ExchangeTime::parse($bad), $bad);
        }
    }

    public function test_job_errors_are_listed_in_api(): void
    {
        $id = $this->postCsv('/catalog', self::CSV)->json('job_id');
        $job = ExchangeJob::find($id);
        $job->errors()->create(['line' => 2, 'guid' => 'g-1', 'sku' => 'L4755-2', 'message' => 'Товар с таким GUID не найден']);
        $job->update(['rows_total' => 1, 'rows_error' => 1]);

        $this->api('GET', "/jobs/{$id}")->assertOk()
            ->assertJsonPath('rows_error', 1)
            ->assertJsonPath('errors.0', ['line' => 2, 'guid' => 'g-1', 'sku' => 'L4755-2', 'message' => 'Товар с таким GUID не найден'])
            ->assertJsonPath('errors_truncated', false);
    }
}
