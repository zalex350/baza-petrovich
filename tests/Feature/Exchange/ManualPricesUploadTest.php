<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeToken;
use App\Models\Product;
use App\Models\User;
use App\Notifications\ExchangeAlertNotification;
use App\Services\Exchange\ExchangeAlerts;
use App\Services\Exchange\ManualPricesUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Ручная загрузка цен и остатков в админке: CSV / .xlsx → предпросмотр → применить;
 * журнал с источником «вручную»; шаблон; оповещение «давно нет цен»; CORS для API выключен.
 */
class ManualPricesUploadTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "guid;sku;price;stock;stock_status;updated_at\n";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
        $this->admin = User::factory()->admin()->create(['name' => 'Мария Менеджер']);
    }

    private function product(string $sku, ?string $guid = null, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => "Товар {$sku}", 'sku' => $sku, 'price' => 100, 'stock' => 5, 'is_active' => true]);

        if ($guid) {
            $product->forceFill(['guid' => $guid])->save();
        }

        return $product;
    }

    private function csv(string $content, string $name = 'prices.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** @param  list<list<mixed>>  $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        // Дата — с форматом даты, как сохраняет Excel (без формата openspout пишет число)
        $date = (new Style)->setFormat('dd.mm.yyyy hh:mm');
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v, $v instanceof \DateTimeInterface ? $date : null), $row)));
        }
        $writer->close();

        return new UploadedFile($path, 'prices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(UploadedFile $file, string $mode = 'delta')
    {
        return $this->actingAs($this->admin)->post('/admin/exchange/prices', ['file' => $file, 'mode' => $mode]);
    }

    private static function money(mixed $price): ?string
    {
        return $price === null ? null : number_format((float) $price, 2, '.', '');
    }

    public function test_csv_preview_then_apply_writes_manual_job(): void
    {
        $a = $this->product('A-1', 'g-1');
        $b = $this->product('B-1', 'g-2', ['price' => 50]);
        $b->forceFill(['stock_1c' => 5])->save();

        $this->upload($this->csv(self::HEAD
            ."g-1;A-1;120.00;9;;2026-11-10T18:30:00+03:00\n"
            ."g-2;B-1;50.00;5;;2026-11-10T18:30:00+03:00\n"
            ."g-404;Z-1;1.00;1;;2026-11-10T18:30:00+03:00\n"
            ."g-1;A-1;12,5;1;;2026-11-10T18:30:00+03:00\n"))
            ->assertRedirect('/admin/exchange/prices/preview');

        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertOk()
            ->assertSee('data-preview-count="Строк в файле">4<', false)
            ->assertSee('data-preview-count="Будет обновлено">1<', false)
            ->assertSee('data-preview-count="Без изменений">1<', false)
            ->assertSee('data-preview-count="С ошибкой">2<', false)
            ->assertSee("data-preview-updated=\"{$a->id}\"", false)
            ->assertSee('Товар A-1')
            ->assertSee('Товар с таким GUID не найден')
            ->assertSee('Цена «12,5»: ожидается точка')
            ->assertSee('data-prices-apply', false);

        // Предпросмотр базу не меняет
        $this->assertSame(['100.00', 5], [self::money($a->fresh()->price), $a->fresh()->stock_1c]);
        $this->assertSame(0, ExchangeJob::count());

        $response = $this->actingAs($this->admin)->post('/admin/exchange/prices/apply');

        $job = ExchangeJob::sole();
        $response->assertRedirect("/admin/exchange/job/{$job->id}")->assertSessionHas('success');
        $this->assertSame([ExchangeJobType::PricesStock, ExchangeJobStatus::Done, 'manual', $this->admin->id, 'delta'],
            [$job->type, $job->status, $job->source, $job->user_id, $job->mode]);
        $this->assertSame([4, 2, 2], [$job->rows_total, $job->rows_ok, $job->rows_error]);
        $this->assertTrue($job->hasFile());
        $this->assertSame(['120.00', 9, 9], [self::money($a->fresh()->price), $a->fresh()->stock_1c, $a->fresh()->stock]);

        // Черновик ушёл в архив; повторное применение — нечего применять
        $this->assertSame([], Storage::disk('exchange')->files(ManualPricesUpload::DIR));
        $this->actingAs($this->admin)->post('/admin/exchange/prices/apply')->assertRedirect('/admin/exchange?tab=upload')->assertSessionHas('error');

        // Журнал: «Вручную» и автор
        $this->actingAs($this->admin)->get('/admin/exchange')->assertOk()->assertSee('Вручную')->assertSee('Мария Менеджер');
        $this->actingAs($this->admin)->get("/admin/exchange/job/{$job->id}")->assertOk()->assertSee('Вручную — Мария Менеджер');
    }

    public function test_xlsx_with_numbers_and_excel_dates(): void
    {
        $product = $this->product('L4755-2', 'g-1');

        $this->upload($this->xlsx([
            ['sku', 'guid', 'price', 'stock', 'updated_at'],
            ['L4755-2', 'g-1', 832.26, 7, new \DateTimeImmutable('2026-11-10 18:30:00')],
        ]));
        $this->actingAs($this->admin)->post('/admin/exchange/prices/apply');

        $product->refresh();
        $this->assertSame(['832.26', 7], [self::money($product->price), $product->stock_1c]);
        // Дата Excel без пояса — по Минску (18:30 МСК = 15:30 UTC)
        $this->assertSame('2026-11-10 15:30:00', $product->getRawOriginal('stock_synced_at'));
        $this->assertStringEndsWith('.xlsx', ExchangeJob::sole()->file_path);
    }

    public function test_cp1251_csv_from_excel_is_converted(): void
    {
        $product = $this->product('Смеситель-1', 'g-1');

        $this->upload($this->csv(mb_convert_encoding(self::HEAD."g-1;Смеситель-1;10.00;3;;2026-11-10T18:30:00+03:00\n", 'Windows-1251', 'UTF-8')));
        $this->actingAs($this->admin)->post('/admin/exchange/prices/apply');

        $this->assertSame(3, $product->fresh()->stock_1c);
    }

    public function test_full_mode_preview_lists_zeroed_and_safeguard_blocks(): void
    {
        foreach (range(1, 10) as $i) {
            $this->product("A-{$i}", "g-{$i}");
        }

        $nine = self::HEAD.implode('', array_map(fn ($i) => "g-{$i};A-{$i};1.00;1;;2026-11-10T18:30:00+03:00\n", range(1, 9)));
        $this->upload($this->csv($nine), 'full');
        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertOk()
            ->assertSee('data-preview-count="Остаток → 0 (full)">1<', false)
            ->assertSee('data-preview-zeroed="'.Product::where('sku', 'A-10')->value('id').'"', false);

        $eight = self::HEAD.implode('', array_map(fn ($i) => "g-{$i};A-{$i};1.00;1;;2026-11-10T18:30:00+03:00\n", range(1, 8)));
        $this->upload($this->csv($eight), 'full');
        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertOk()
            ->assertSee('data-preview-failed', false)
            ->assertSee('в файле нет 2 из 10 товаров с GUID')
            ->assertDontSee('data-prices-apply', false);

        // Новый файл заменил прежний черновик
        $this->assertCount(1, Storage::disk('exchange')->files(ManualPricesUpload::DIR));
    }

    public function test_missing_columns_and_bad_file_type(): void
    {
        $this->upload($this->csv("guid,sku,price\n1,2,3\n"));
        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertOk()
            ->assertSee('Нет обязательных колонок в первой строке: guid, sku, stock, updated_at (разделитель CSV — «;»)');

        $this->upload($this->csv('x', 'prices.pdf'))->assertSessionHasErrors('file');
        $this->actingAs($this->admin)->post('/admin/exchange/prices', ['mode' => 'delta'])->assertSessionHasErrors('file');
        $this->actingAs($this->admin)->post('/admin/exchange/prices', ['file' => $this->csv(self::HEAD), 'mode' => 'all'])->assertSessionHasErrors('mode');
    }

    public function test_cancel_discards_draft(): void
    {
        $this->upload($this->csv(self::HEAD."g-1;A-1;1.00;1;;2026-11-10T18:30:00+03:00\n"));
        $this->assertCount(1, Storage::disk('exchange')->files(ManualPricesUpload::DIR));

        $this->actingAs($this->admin)->post('/admin/exchange/prices/cancel')->assertRedirect('/admin/exchange?tab=upload');

        $this->assertSame([], Storage::disk('exchange')->files(ManualPricesUpload::DIR));
        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertRedirect('/admin/exchange?tab=upload');
        $this->assertSame(0, ExchangeJob::count());
    }

    public function test_old_drafts_are_pruned(): void
    {
        $this->upload($this->csv(self::HEAD));
        $this->travel(25)->hours();

        $this->artisan('exchange:prune')->expectsOutputToContain('черновиков загрузки: 1')->assertSuccessful();
        $this->assertSame([], Storage::disk('exchange')->files(ManualPricesUpload::DIR));
    }

    public function test_template_has_columns_and_example(): void
    {
        $this->actingAs($this->admin)->get('/admin/exchange?tab=upload')->assertOk()
            ->assertSee('data-prices-upload', false)->assertSee('/admin/exchange/prices/template', false);

        $response = $this->actingAs($this->admin)->get('/admin/exchange/prices/template')->assertOk()
            ->assertDownload('prices-stock-template.xlsx');

        $reader = new XlsxReader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['guid', 'sku', 'price', 'stock', 'stock_status', 'updated_at'], $rows[0]);
        $this->assertSame('L4755-2', $rows[1][1]);
        $this->assertStringEndsWith('+03:00', $rows[1][5]);
    }

    public function test_upload_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/exchange/prices', ['file' => $this->csv(self::HEAD), 'mode' => 'delta'])->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/exchange/prices/template')->assertForbidden();
    }

    // --- «Давно нет цен» — после первой успешной загрузки любого источника ---

    public function test_stale_prices_alert_starts_after_first_successful_load_of_any_source(): void
    {
        $failed = new ExchangeJob(['type' => ExchangeJobType::PricesStock, 'direction' => 'import', 'status' => ExchangeJobStatus::Failed, 'received_at' => now()->subHours(5)]);
        $failed->save();

        app(ExchangeAlerts::class)->check();
        Notification::assertNothingSent();

        $this->product('A-1', 'g-1');
        $this->upload($this->csv(self::HEAD."g-1;A-1;1.00;1;;2026-11-10T18:30:00+03:00\n"));
        $this->actingAs($this->admin)->post('/admin/exchange/prices/apply');
        $this->assertSame('manual', ExchangeJob::where('status', 'done')->sole()->source);

        $this->travel(3)->hours();
        app(ExchangeAlerts::class)->check();
        Notification::assertSentTo($this->admin, ExchangeAlertNotification::class, fn ($n) => str_contains($n->subject, 'не приходили'));
    }

    // --- CORS ---

    public function test_api_has_no_cors_headers(): void
    {
        ExchangeIp::create(['ip' => '127.0.0.1']);
        [, $token] = ExchangeToken::issue('1С', null);
        $server = ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'REMOTE_ADDR' => '127.0.0.1'];

        $this->call('GET', '/api/1c/v1/ping', [], [], [], $server)->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/1c/v1/prices-stock', [], [], [], $server + ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Methods');
    }
}
