<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\ExchangeJob;
use App\Models\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exchange\ExchangeJobs;
use App\Services\Vat\VatCalculator;
use App\Support\ExchangeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Поток 2 по типам цен (раздел 5 спецификации, правила 9–10): колонки price_<код>, price = price_opt,
 * колонки нет — цена не меняется, пусто — цены типа нет, цены с НДС → без НДС по ставке товара
 * (настройка «Цены из 1С с НДС»), неизвестный код — скрытый тип + предупреждение, предпросмотр по типам.
 */
class PriceTypesExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-11-10T18:30:00+03:00';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 100, 'vat_rate' => 20, 'stock' => 5, 'is_active' => true]);
    }

    private function import(string $csv): ExchangeJob
    {
        $job = new ExchangeJob(['type' => ExchangeJobType::PricesStock, 'direction' => 'import', 'mode' => 'delta',
            'status' => ExchangeJobStatus::Queued, 'received_at' => now()]);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        Storage::disk('exchange')->put($job->file_path, $csv);
        $job->save();

        app(ExchangeJobs::class)->process($job);

        return $job->fresh();
    }

    /** @return array<string, string> код типа => цена (2 знака) */
    private function prices(Product $product): array
    {
        return ProductPrice::query()->where('product_id', $product->id)->with('type')->get()
            ->mapWithKeys(fn ($p) => [$p->type->code => number_format((float) $p->price, 2, '.', '')])->sortKeys()->all();
    }

    private static function money(mixed $price): ?string
    {
        return $price === null ? null : number_format((float) $price, 2, '.', '');
    }

    public function test_net_from_gross(): void
    {
        $this->assertSame('832.26', VatCalculator::netFromGross('998.71', 20));
        $this->assertSame('832.26', VatCalculator::netFromGross('998.71', '20.00'));
        $this->assertSame('90.91', VatCalculator::netFromGross('100', 10));
        $this->assertSame('100.00', VatCalculator::netFromGross('100', null));
        $this->assertSame('100.00', VatCalculator::netFromGross('100', 0));
        $this->assertSame('0.01', VatCalculator::netFromGross('0.01', 20));
    }

    public function test_type_columns_with_vat_are_stored_without_vat(): void
    {
        $vat20 = $this->product('A-1');
        $noVat = $this->product('B-1', ['vat_rate' => null]);

        $job = $this->import("guid;sku;price_opt;price_retail;price_vip;stock;updated_at\n"
            .';A-1;998.71;1098.58;;7;'.self::AT."\n"
            .';B-1;50.00;;55.5;3;'.self::AT."\n");

        $this->assertSame(ExchangeJobStatus::Done, $job->status);
        $this->assertSame(['opt' => '832.26', 'retail' => '915.48'], $this->prices($vat20));
        $this->assertSame('832.26', self::money($vat20->fresh()->getRawOriginal('price')), 'products.price — цена по умолчанию');
        $this->assertSame(['opt' => '50.00', 'vip' => '55.50'], $this->prices($noVat));

        // Настройка выключена — цены как есть
        Setting::put([ExchangeSettings::PRICES_WITH_VAT => '0']);
        $this->import("guid;sku;price_opt;stock;updated_at\n;A-1;998.71;7;2026-11-11T10:00:00+03:00\n");
        $this->assertSame('998.71', $this->prices($vat20)['opt']);
    }

    public function test_missing_column_keeps_price_empty_value_removes_it(): void
    {
        $product = $this->product('A-1');
        ProductPrice::create(['product_id' => $product->id, 'price_type_id' => PriceType::where('code', 'vip')->value('id'), 'price' => 77]);
        Setting::put([ExchangeSettings::PRICES_WITH_VAT => '0']);

        // Только розничная: оптовая и VIP не меняются
        $this->import("guid;sku;price_retail;stock;updated_at\n;A-1;120;7;".self::AT."\n");
        $this->assertSame(['opt' => '100.00', 'retail' => '120.00', 'vip' => '77.00'], $this->prices($product));
        $this->assertSame('100.00', self::money($product->fresh()->getRawOriginal('price')));

        // Без колонок цен — цены и время цены не трогаются
        $before = $product->fresh()->price_synced_at;
        $this->import("guid;sku;stock;updated_at\n;A-1;9;2026-11-11T10:00:00+03:00\n");
        $this->assertSame(['opt' => '100.00', 'retail' => '120.00', 'vip' => '77.00'], $this->prices($product));
        $this->assertEquals($before, $product->fresh()->price_synced_at);
        $this->assertSame(9, $product->fresh()->stock_1c);

        // Пусто — цены типа нет; пустая оптовая — «Цена уточняется»
        $this->import("guid;sku;price_opt;price_vip;stock;updated_at\n;A-1;;;9;2026-11-12T10:00:00+03:00\n");
        $this->assertSame(['retail' => '120.00'], $this->prices($product));
        $this->assertNull($product->fresh()->getRawOriginal('price'));
    }

    public function test_legacy_price_column_is_opt_and_price_opt_wins(): void
    {
        $product = $this->product('A-1');
        Setting::put([ExchangeSettings::PRICES_WITH_VAT => '0']);

        $this->import("guid;sku;price;stock;updated_at\n;A-1;150;7;".self::AT."\n");
        $this->assertSame(['opt' => '150.00'], $this->prices($product));

        $job = $this->import("guid;sku;price;price_opt;stock;updated_at\n;A-1;1;160;7;2026-11-11T10:00:00+03:00\n");
        $this->assertSame(['opt' => '160.00'], $this->prices($product));
        $this->assertStringContainsString('берётся price_opt', json_encode($job->report['warnings'], JSON_UNESCAPED_UNICODE));
    }

    public function test_unknown_code_creates_hidden_type_with_warning(): void
    {
        $product = $this->product('A-1');
        Setting::put([ExchangeSettings::PRICES_WITH_VAT => '0']);

        $job = $this->import("guid;sku;price_Dealer;price_опт;stock;updated_at\n;A-1;95;1;7;".self::AT."\n");

        $dealer = PriceType::where('code', 'dealer')->firstOrFail();
        $this->assertTrue($dealer->is_hidden);
        $this->assertFalse($dealer->is_default);
        $this->assertSame('dealer', $dealer->name);
        $this->assertSame('95.00', $this->prices($product)['dealer']);
        $this->assertSame('100.00', self::money($product->fresh()->getRawOriginal('price')));
        $this->assertSame(1, $job->rows_ok);

        $warnings = implode(' | ', array_column($job->report['warnings'], 'message'));
        $this->assertStringContainsString('типа цены «dealer» нет — он создаётся скрытым', $warnings);
        $this->assertStringContainsString('Колонка «price_опт»: код типа цены', $warnings);

        // Повторно — тип уже есть, предупреждения о нём нет
        $again = $this->import("guid;sku;price_dealer;stock;updated_at\n;A-1;96;7;2026-11-11T10:00:00+03:00\n");
        $this->assertNull($again->report);
        $this->assertSame(1, PriceType::where('code', 'dealer')->count());
    }

    public function test_type_price_errors_name_the_column(): void
    {
        $this->product('A-1');

        $job = $this->import("guid;sku;price_opt;price_retail;stock;updated_at\n;A-1;10;12,50;7;".self::AT."\n");

        $this->assertSame(1, $job->rows_error);
        $this->assertSame('Цена price_retail «12,50»: ожидается точка', $job->errors()->value('message'));
    }

    public function test_manual_preview_shows_changes_per_type_and_new_types(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product('A-1');

        $csv = "guid;sku;price_opt;price_retail;price_dealer;stock;updated_at\n;A-1;120;132;110;5;".self::AT."\n";
        $this->actingAs($admin)->post('/admin/exchange/prices', ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv), 'mode' => 'delta'])
            ->assertRedirect('/admin/exchange/prices/preview');

        $this->actingAs($admin)->get('/admin/exchange/prices/preview')->assertOk()
            ->assertSee('Оптовая, без НДС')->assertSee('Розничная, без НДС')->assertSee('dealer, без НДС')
            ->assertSee('data-preview-warnings', false)
            ->assertSee('с НДС')
            // 120 с НДС 20 % = 100,00 без НДС — оптовая не меняется
            ->assertSeeInOrder(['data-preview-price="opt"', "100,00\u{00A0}BYN", 'data-preview-price="retail"'], false)
            ->assertSeeInOrder(['data-preview-price="retail"', '—', "110,00\u{00A0}BYN"], false);

        // Предпросмотр ничего не создаёт
        $this->assertFalse(PriceType::where('code', 'dealer')->exists());
        $this->assertSame(['opt' => '100.00'], $this->prices($product));
    }

    public function test_settings_toggle_prices_with_vat(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue(ExchangeSettings::pricesWithVat());

        $this->actingAs($admin)->get('/admin/exchange?tab=settings')->assertSee('data-prices-with-vat', false)->assertSee('Цены из 1С с НДС');

        $form = collect(ExchangeSettings::FIELDS)->map(fn ($f) => $f[1])->all();
        $this->actingAs($admin)->post('/admin/exchange/settings', $form + [ExchangeSettings::PRICES_WITH_VAT => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(ExchangeSettings::pricesWithVat());
        $this->actingAs($admin)->post('/admin/exchange/settings', $form + [ExchangeSettings::PRICES_WITH_VAT => '1']);
        $this->assertTrue(ExchangeSettings::pricesWithVat());
    }
}
