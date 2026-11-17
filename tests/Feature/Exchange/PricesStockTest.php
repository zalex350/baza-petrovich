<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeJobError;
use App\Models\ExchangeToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exchange\ExchangeJobs;
use App\Services\StockReserve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Поток 2 — цены и остатки (docs/1C_EXCHANGE_SPEC.md, раздел 5): PricesStockHandler.
 */
class PricesStockTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "guid;sku;price;stock;stock_status;updated_at\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
    }

    private function product(string $sku, ?string $guid = null, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 100, 'stock' => 5, 'is_active' => true]);

        if ($guid !== null) {
            $product->forceFill(['guid' => $guid])->save();
        }

        return $product;
    }

    private function import(string $csv, string $mode = 'delta'): ExchangeJob
    {
        $job = new ExchangeJob(['type' => ExchangeJobType::PricesStock, 'direction' => 'import', 'mode' => $mode,
            'status' => ExchangeJobStatus::Queued, 'received_at' => now()]);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        Storage::disk('exchange')->put($job->file_path, $csv);
        $job->save();

        app(ExchangeJobs::class)->process($job);

        return $job->fresh();
    }

    /** Цена как строка с 2 знаками: SQLite отдаёт decimal числом */
    private static function money(mixed $price): ?string
    {
        return $price === null ? null : number_format((float) $price, 2, '.', '');
    }

    /** @return array<int, string> строка => ошибка */
    private function errors(ExchangeJob $job): array
    {
        return ExchangeJobError::where('exchange_job_id', $job->id)->orderBy('line')->pluck('message', 'line')->all();
    }

    // --- Запись ---

    public function test_applies_price_stock_and_status_by_guid_in_any_column_order(): void
    {
        $a = $this->product('LEDEME-L4755-2', 'g-1');
        $b = $this->product('TIM-BL5800', 'g-2', ['on_order' => false]);

        $job = $this->import("updated_at;stock;guid;price;sku;stock_status\n"
            ."2026-11-10T18:30:00+03:00;7;g-1;832.26;L4755-2;in_stock\n"
            ."2026-11-10T18:30:00+03:00;0;G-2;;BL5800;on_order\n");

        $this->assertSame([ExchangeJobStatus::Done, 2, 2, 0, 0], [$job->status, $job->rows_total, $job->rows_ok, $job->rows_skipped, $job->rows_error]);

        $a->refresh();
        $this->assertSame(['832.26', 7, 7, false], [self::money($a->price), $a->stock_1c, $a->stock, $a->on_order]);
        $this->assertSame('2026-11-10 15:30:00', $a->getRawOriginal('price_synced_at'));
        $this->assertSame('2026-11-10 15:30:00', $a->getRawOriginal('stock_synced_at'));

        // Пустая цена — «Цена уточняется»; guid — без учёта регистра
        $b->refresh();
        $this->assertSame([null, 0, true], [self::money($b->price), $b->stock_1c, $b->on_order]);
    }

    public function test_stock_is_reduced_by_site_reserve(): void
    {
        $product = $this->product('A-1', 'g-1', ['stock' => 10]);
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'new']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 3, 'price' => 1]);
        app(StockReserve::class)->recalculate([$product->id]);

        $this->import(self::HEAD."g-1;A-1;10.00;20;;2026-11-10T18:30:00+03:00\n");

        $this->assertSame([20, 17], [$product->fresh()->stock_1c, $product->fresh()->stock]);
    }

    public function test_reserve_of_registered_order_holds_until_newer_stock(): void
    {
        $product = $this->product('A-1', 'g-1', ['stock' => 10]);
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'sent_to_1c']);
        $order->forceFill(['exchange_state' => 'sent', 'registered_at' => '2026-11-10 15:40:00'])->save();
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'price' => 1]);

        // Остаток снят в 18:30 МСК (15:30 UTC) — до регистрации заказа в 1С: резерв держится
        $this->import(self::HEAD.'g-1;A-1;1.00;10;;2026-11-10T18:30:00+03:00
');
        $this->assertSame([10, 8], [$product->fresh()->stock_1c, $product->fresh()->stock]);

        // Снят в 18:45 — после регистрации: 1С уже вычла заказ, резерв снят
        $this->import(self::HEAD.'g-1;A-1;1.00;8;;2026-11-10T18:45:00+03:00
');
        $this->assertSame([8, 8], [$product->fresh()->stock_1c, $product->fresh()->stock]);
    }

    public function test_status_column_absent_keeps_on_order(): void
    {
        $product = $this->product('A-1', 'g-1', ['on_order' => true]);

        $this->import("guid;sku;price;stock;updated_at\ng-1;A-1;1.00;0;2026-11-10T18:30:00Z\n");

        $this->assertTrue($product->fresh()->on_order);
    }

    // --- Поиск по артикулу ---

    public function test_falls_back_to_exact_sku_case_and_edge_spaces_insensitive(): void
    {
        $exact = $this->product('LEDEME-L4755-2');
        $exact->forceFill(['article' => 'L4755-2'])->save();
        $spaced = $this->product('TIM-AM-GVS 15-04');
        $this->product('TIM-AM-GVS15-04');
        $foreign = $this->product('SAVOL-S1', 'other-guid');

        $job = $this->import(self::HEAD
            ."new-guid; ledeme-l4755-2 ;10.00;1;;2026-11-10T18:30:00+03:00\n"   // регистр и пробелы по краям — не важны
            .";TIM-AM-GVS 15-04;20.00;2;;2026-11-10T18:30:00+03:00\n"           // внутренний пробел значим
            ."g-x;SAVOL-S1;30.00;3;;2026-11-10T18:30:00+03:00\n"
            ."g-y;L4755-2;40.00;4;;2026-11-10T18:30:00+03:00\n"                 // артикул — не ищется
            .";TIM-AM-GVS 1504;50.00;5;;2026-11-10T18:30:00+03:00\n");

        $this->assertSame(['10.00', 1], [self::money($exact->fresh()->price), $exact->fresh()->stock_1c]);
        $this->assertSame(['20.00', 2], [self::money($spaced->fresh()->price), $spaced->fresh()->stock_1c]);
        $this->assertSame(['100.00', 5], [self::money(Product::where('sku', 'TIM-AM-GVS15-04')->value('price')), Product::where('sku', 'TIM-AM-GVS15-04')->value('stock_1c')]);
        // GUID по ценам не присваивается — это делает поток номенклатуры
        $this->assertNull($exact->fresh()->guid);
        $this->assertSame('100.00', self::money($foreign->fresh()->price));

        $this->assertSame([
            4 => 'Товар с таким GUID не найден, SKU «SAVOL-S1» — у товара с другим GUID',
            5 => 'Товар с таким GUID не найден',
            6 => 'Товар с SKU «TIM-AM-GVS 1504» не найден',
        ], $this->errors($job));
        $this->assertSame([5, 2, 3], [$job->rows_total, $job->rows_ok, $job->rows_error]);
    }

    public function test_sku_differing_only_in_case_is_ambiguous(): void
    {
        $this->product('abc-1');
        $this->product('ABC-1');

        $job = $this->import(self::HEAD.";Abc-1;1.00;1;;2026-11-10T18:30:00+03:00\n");

        $this->assertSame([2 => 'Товар с таким GUID не найден, SKU «Abc-1» есть у нескольких товаров сайта'], $this->errors($job));
    }

    public function test_match_by_article_only_when_enabled_and_only_without_guid(): void
    {
        $free = $this->product('LEDEME-L4755-2');
        $free->forceFill(['article' => 'L4755-2'])->save();
        $linked = $this->product('SAVOL-S1', 'g-s1');
        $linked->forceFill(['article' => 'S1'])->save();
        $this->product('A-X1')->forceFill(['article' => 'X-1'])->save();
        $this->product('B-X1')->forceFill(['article' => 'X-1'])->save();

        $csv = self::HEAD
            .";l4755-2;10.00;1;;2026-11-10T18:30:00+03:00\n"
            .";S1;20.00;2;;2026-11-10T18:30:00+03:00\n"
            .";X-1;30.00;3;;2026-11-10T18:30:00+03:00\n";

        // Выключено по умолчанию: артикул не ищется
        $this->assertSame(3, $this->import($csv)->rows_error);
        $this->assertSame('100.00', self::money($free->fresh()->price));

        config(['shop.exchange_1c.match_by_article' => true]);
        $job = $this->import($csv);

        $this->assertSame('10.00', self::money($free->fresh()->price));
        // Товар с GUID по артикулу не находится; два товара с артикулом — ошибка
        $this->assertSame('100.00', self::money($linked->fresh()->price));
        $this->assertSame([
            3 => 'Товар с SKU «S1» не найден',
            4 => 'Товар с таким GUID не найден, артикул «X-1» есть у нескольких товаров сайта',
        ], $this->errors($job));
    }

    // --- Ошибки строк ---

    public function test_row_errors_with_file_line_numbers(): void
    {
        $this->product('A-1', 'g-1');

        $job = $this->import(self::HEAD
            ."g-1;A-1;12,50;1;;2026-11-10T18:30:00+03:00\n"
            ."\n"
            ."g-1;A-1;-5;1;;2026-11-10T18:30:00+03:00\n"
            ."g-1;A-1;1.234;1;;2026-11-10T18:30:00+03:00\n"
            ."g-1;A-1;1.00;-2;;2026-11-10T18:30:00+03:00\n"
            ."g-1;A-1;1.00;1.5;;2026-11-10T18:30:00+03:00\n"
            ."g-1;A-1;1.00;1;;10.11.2026 18:30\n"
            ."g-1;A-1;1.00;1;maybe;2026-11-10T18:30:00+03:00\n"
            .";;1.00;1;;2026-11-10T18:30:00+03:00\n"
            ."g-404;;abc;;;\n");

        $this->assertSame([
            2 => 'Цена «12,50»: ожидается точка',
            4 => 'Цена «-5»: отрицательная',
            5 => 'Цена «1.234»: ожидается число до 2 знаков после точки, без пробелов',
            6 => 'Остаток «-2»: отрицательный',
            7 => 'Остаток «1.5»: ожидается целое число ≥ 0',
            8 => 'Дата «10.11.2026 18:30»: ожидается ISO 8601, например 2026-11-10T18:30:00+03:00',
            9 => 'Признак наличия «maybe»: ожидается in_stock, on_order или none',
            10 => 'Нет GUID и SKU',
            11 => 'Товар с таким GUID не найден; Цена «abc»: ожидается число до 2 знаков после точки, без пробелов; Нет остатка; Дата «»: ожидается ISO 8601, например 2026-11-10T18:30:00+03:00',
        ], $this->errors($job));
        $this->assertSame([9, 0, 0, 9], [$job->rows_total, $job->rows_ok, $job->rows_skipped, $job->rows_error]);
        $this->assertSame('100.00', self::money(Product::sole()->price));
    }

    // --- Устаревшие строки ---

    public function test_older_rows_are_skipped_separately_for_price_and_stock(): void
    {
        $product = $this->product('A-1', 'g-1');
        $product->forceFill(['price_synced_at' => '2026-11-10 16:00:00', 'stock_synced_at' => '2026-11-10 14:00:00', 'stock_1c' => 5])->save();

        // 18:00 МСК = 15:00 UTC: цена старее (16:00), остаток новее (14:00)
        $job = $this->import(self::HEAD."g-1;A-1;999.00;8;;2026-11-10T18:00:00+03:00\n");
        $product->refresh();
        $this->assertSame(['100.00', 8, 1, 0], [self::money($product->price), $product->stock_1c, $job->rows_ok, $job->rows_skipped]);

        // Обе отметки новее строки — строка пропущена целиком
        $job = $this->import(self::HEAD."g-1;A-1;1.00;1;;2026-11-10T12:00:00Z\n");
        $this->assertSame([0, 1, 0], [$job->rows_ok, $job->rows_skipped, $job->rows_error]);
        $this->assertSame(8, $product->fresh()->stock_1c);

        // Дубль в одном файле: старая строка после новой — пропущена
        $job = $this->import(self::HEAD
            ."g-1;A-1;300.00;30;;2026-11-11T10:00:00+03:00\n"
            ."g-1;A-1;200.00;20;;2026-11-11T09:00:00+03:00\n");
        $this->assertSame(['300.00', 30, 1, 1], [self::money($product->fresh()->price), $product->fresh()->stock_1c, $job->rows_ok, $job->rows_skipped]);
    }

    // --- mode=full ---

    public function test_full_mode_zeroes_missing_guids(): void
    {
        $present = $this->product('A-1', 'g-1');
        $products = collect(range(2, 11))->map(fn ($i) => $this->product("A-{$i}", "g-{$i}", ['stock' => 4]));
        $noGuid = $this->product('LOCAL-1', null, ['stock' => 6]);

        // 10 из 11 в файле (нет одного: 9 % ≤ 10 %)
        $csv = self::HEAD;
        foreach (range(1, 10) as $i) {
            $csv .= "g-{$i};A-{$i};10.00;3;;2026-11-10T18:30:00+03:00\n";
        }

        $job = $this->import($csv, 'full');

        $missing = $products->last()->fresh();
        $this->assertSame([ExchangeJobStatus::Done, 0, 0], [$job->status, $missing->stock_1c, $missing->stock]);
        $this->assertSame('100.00', self::money($missing->price));
        $this->assertSame('2026-11-10 15:30:00', $missing->getRawOriginal('stock_synced_at'));
        $this->assertSame('mode=full: остаток 0 у 1 товаров с GUID, которых нет в файле', $job->message);
        $this->assertSame(3, $present->fresh()->stock_1c);
        $this->assertSame(6, $noGuid->fresh()->stock_1c);

        // delta — отсутствующие не трогаются
        $products->last()->fresh()->forceFill(['stock_1c' => 9, 'stock_synced_at' => null])->save();
        $this->import(self::HEAD."g-1;A-1;10.00;3;;2026-11-10T19:00:00+03:00\n");
        $this->assertSame(9, $products->last()->fresh()->stock_1c);
    }

    public function test_full_mode_safeguard_fails_without_changes(): void
    {
        $this->product('A-1', 'g-1');
        foreach (range(2, 10) as $i) {
            $this->product("A-{$i}", "g-{$i}");
        }

        // 8 из 10 в файле: не хватает 20 % > 10 %
        $csv = self::HEAD;
        foreach (range(1, 8) as $i) {
            $csv .= "g-{$i};A-{$i};1.00;1;;2026-11-10T18:30:00+03:00\n";
        }

        $job = $this->import($csv, 'full');

        $this->assertSame(ExchangeJobStatus::Failed, $job->status);
        $this->assertStringContainsString('в файле нет 2 из 10 товаров с GUID (20 % при пороге 10 %)', $job->message);
        $this->assertSame([5], Product::query()->pluck('stock_1c')->unique()->values()->all());
        $this->assertSame(['100.00'], Product::query()->pluck('price')->map(fn ($p) => self::money($p))->unique()->values()->all());

        // Порог из настроек: 25 % — тот же файл проходит
        Setting::put(['exchange_full_missing_percent' => '25']);
        $this->assertSame(ExchangeJobStatus::Done, $this->import($csv, 'full')->status);
        $this->assertSame(0, Product::where('sku', 'A-10')->value('stock_1c'));
    }

    // --- Через API ---

    public function test_end_to_end_through_api(): void
    {
        ExchangeIp::create(['ip' => '127.0.0.1']);
        [, $token] = ExchangeToken::issue('1С', null);
        $product = $this->product('A-1', 'g-1');

        $id = $this->call('POST', '/api/1c/v1/prices-stock?mode=delta', [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$token}", 'CONTENT_TYPE' => 'text/csv', 'REMOTE_ADDR' => '127.0.0.1',
        ], self::HEAD."g-1;A-1;55.50;12;in_stock;2026-11-10T18:30:00+03:00\n")->assertStatus(202)->json('job_id');

        $this->call('GET', "/api/1c/v1/jobs/{$id}", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'REMOTE_ADDR' => '127.0.0.1'])
            ->assertOk()->assertJson(['status' => 'done', 'rows_total' => 1, 'rows_ok' => 1, 'rows_error' => 0]);

        $this->assertSame(['55.50', 12, 12], [self::money($product->fresh()->price), $product->fresh()->stock_1c, $product->fresh()->stock]);
    }
}
