<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\Category;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeJobError;
use App\Models\ExchangeToken;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exchange\ExchangeJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Поток 1 — номенклатура (docs/1C_EXCHANGE_SPEC.md, раздел 4): CatalogHandler.
 */
class CatalogExchangeTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "guid;sku;article;brand;name;unit;vat_rate;barcode;group_1c;active\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $sku, ?string $guid = null, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 100, 'stock' => 5, 'is_active' => true]);

        if ($guid !== null) {
            $product->forceFill(['guid' => $guid])->save();
        }

        return $product;
    }

    private function import(string $csv, string $mode = 'delta', bool $dryRun = false): ExchangeJob
    {
        $job = new ExchangeJob(['type' => ExchangeJobType::Catalog, 'direction' => 'import', 'mode' => $mode, 'dry_run' => $dryRun,
            'status' => ExchangeJobStatus::Queued, 'received_at' => now()]);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        Storage::disk('exchange')->put($job->file_path, $csv);
        $job->save();

        app(ExchangeJobs::class)->process($job);

        return $job->fresh();
    }

    /** @return array<int, string> строка => ошибка */
    private function errors(ExchangeJob $job): array
    {
        return ExchangeJobError::where('exchange_job_id', $job->id)->orderBy('line')->pluck('message', 'line')->all();
    }

    // --- Найден по GUID: какие поля пишутся ---

    public function test_by_guid_writes_managed_fields_and_keeps_site_fields(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $category = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
        $product = $this->product('LEDEME-L4755-2', 'G-1', [
            'display_name' => 'Смеситель для кухни', 'article' => 'L4755', 'brand' => 'LEDEME',
            'category_id' => $category->id, 'description' => 'Текст', 'min_qty' => 3,
        ]);

        $job = $this->import("guid;sku;article;brand;name;unit;vat_rate;min_qty;qty_step;barcode;group_1c;active\n"
            ."g-1;;L4755-2;Ledeme;Смеситель LEDEME L4755-2 (1С);компл;без НДС;5;2;4810000000017;Смесители;1\n");

        $this->assertSame([ExchangeJobStatus::Done, 1, 1, 0], [$job->status, $job->rows_total, $job->rows_ok, $job->rows_error]);

        $product->refresh();
        // Поля 1С (SHOP_1C_MANAGED_FIELDS по умолчанию) + штрихкод и группа
        $this->assertSame(['Смеситель LEDEME L4755-2 (1С)', 'L4755-2', 'Ledeme', 'компл', null, '4810000000017', 'Смесители'],
            [$product->name, $product->article, $product->brand, $product->unit, $product->vat_rate, $product->barcode, $product->group_1c]);
        $this->assertSame('LEDEME:L4755-2', $product->match_key);
        // Код сайта, отображаемое название, категория, описание, цена и остаток — не меняются
        $this->assertSame(['LEDEME-L4755-2', 'Смеситель для кухни', $category->id, 'Текст', 5],
            [$product->sku, $product->display_name, $product->category_id, $product->description, $product->stock]);
        $this->assertEquals(100, $product->price);
        // min_qty / qty_step нет в SHOP_1C_MANAGED_FIELDS — не пишутся
        $this->assertSame([3, 1], [$product->min_qty, $product->qty_step]);
        $this->assertSame('2026-11-12 10:00:00', $product->getRawOriginal('synced_at'));

        $summary = $job->report['summary'];
        $this->assertSame([1, 1, 0], [$summary['matched_by_guid'], $summary['updated'], $summary['created']]);
        $this->assertSame(['article' => 1, 'barcode' => 1, 'brand' => 1, 'group_1c' => 1, 'name' => 1, 'unit' => 1, 'vat_rate' => 1],
            $job->report['summary']['fields']);
    }

    public function test_only_managed_fields_are_written(): void
    {
        config(['shop.exchange_1c.managed_fields' => ['name', 'article']]);
        $product = $this->product('A-1', 'g-1', ['brand' => 'OLD', 'unit' => 'шт']);

        $this->import("guid;article;brand;name;unit;vat_rate;active\ng-1;A-1;NEW;Новое имя;м;20;1\n");

        $product->refresh();
        // brand и unit не в списке — не меняются
        $this->assertSame(['OLD', 'шт', 'Новое имя'], [$product->brand, $product->unit, $product->name]);
    }

    public function test_quantity_columns_are_ignored(): void
    {
        // Даже если поля в списке 1С: минимальное количество и кратность — только админка сайта
        config(['shop.exchange_1c.managed_fields' => ['name', 'article', 'brand', 'min_qty', 'qty_step']]);
        $product = $this->product('A-1', 'g-1', ['brand' => 'X', 'min_qty' => 3, 'qty_step' => 3]);

        // Любые значения, в том числе неверные, — без ошибок и проверок
        $job = $this->import("guid;sku;article;brand;name;unit;vat_rate;min_qty;qty_step;active\n"
            ."g-1;;A-1;X;Товар;шт;20;10;0,5;1\n"
            ."g-2;;B-2;NEW;Новый товар;шт;20;abc;-7;1\n");

        $this->assertSame([], $this->errors($job));
        $this->assertSame(2, $job->rows_ok);
        $this->assertArrayNotHasKey('min_qty', $job->report['summary']['fields']);

        $product->refresh();
        $this->assertSame([3, 3], [$product->min_qty, $product->qty_step]);

        // Новый товар — значения по умолчанию сайта
        $created = Product::where('guid', 'g-2')->sole();
        $this->assertSame([1, 1, 1], [$created->min_qty, $created->qty_step, $created->default_qty]);

        // И в админке поля не закрыты, хотя есть в SHOP_1C_MANAGED_FIELDS
        $this->assertNotContains('min_qty', $product->lockedFields());
        $this->assertNotContains('qty_step', $product->lockedFields());
    }

    public function test_unchanged_row_only_marks_sync_time(): void
    {
        $product = $this->product('LEDEME-A-1', 'g-1', ['article' => 'A-1', 'brand' => 'LEDEME', 'name' => 'Товар', 'vat_rate' => 20]);
        Product::whereKey($product->id)->update(['updated_at' => '2026-01-01 00:00:00']);

        $job = $this->import(self::HEAD."g-1;LEDEME-A-1;A-1;LEDEME;Товар;шт;20;;;1\n");

        $this->assertSame([1, 0], [$job->report['summary']['unchanged'], $job->report['summary']['updated']]);
        $this->assertSame('2026-01-01 00:00:00', $product->fresh()->getRawOriginal('updated_at'));
        $this->assertNotNull($product->fresh()->synced_at);
    }

    public function test_sku_differing_from_product_found_by_guid_is_a_warning(): void
    {
        $product = $this->product('LEDEME-A-1', 'g-1');

        $job = $this->import(self::HEAD."g-1;LEDEME-A-2;A-1;LEDEME;Товар;шт;20;;;1\n");

        $this->assertSame([1, 0], [$job->rows_ok, $job->rows_error]);
        $this->assertSame('LEDEME-A-1', $product->fresh()->sku);
        $this->assertSame([[
            'line' => 2, 'guid' => 'g-1', 'sku' => 'LEDEME-A-1',
            'message' => 'SKU «LEDEME-A-2» отличается от кода товара на сайте «LEDEME-A-1» (товар найден по GUID, код сайта не меняется)',
        ]], $job->report['warnings']);
    }

    // --- Найден без GUID: по SKU и по «бренд + артикул» ---

    public function test_by_sku_assigns_guid_and_errors_do_not_create(): void
    {
        $free = $this->product('LEDEME-L4755-2');
        $this->product('TIM-BL5800', 'other-guid');

        $job = $this->import(self::HEAD
            ."g-1; ledeme-l4755-2 ;L4755-2;LEDEME;Смеситель;шт;20;;;1\n"   // регистр и пробелы по краям
            ."g-2;TIM-BL5800;BL5800;TIM;Смеситель;шт;20;;;1\n"             // у товара другой GUID
            ."g-3;SAVOL-NOPE;NOPE;SAVOL;Сифон;шт;20;;;1\n");               // кода на сайте нет — не создаётся

        $this->assertSame('g-1', $free->fresh()->guid);
        $this->assertSame([
            3 => 'SKU «TIM-BL5800» — у товара сайта с другим GUID',
            4 => 'Товар с SKU «SAVOL-NOPE» на сайте не найден: заполненный sku — код существующего товара сайта; у новой позиции оставьте sku пустым',
        ], $this->errors($job));
        $this->assertSame(2, Product::count());
        $this->assertSame(1, $job->report['summary']['matched_by_sku']);
    }

    public function test_by_brand_and_article_key_with_lookalikes_and_dashes(): void
    {
        $product = $this->product('LEDEME-L2237-B', null, ['brand' => 'LEDEME', 'article' => 'L2237-B']);
        $nodash = $this->product('LEDEME-L22378', null, ['brand' => 'LEDEME', 'article' => 'L22378']);

        // Кириллическая «В», «–» вместо дефиса, регистр и двойной пробел в бренде
        $job = $this->import(self::HEAD."g-1;;L2237\u{2013}\u{0412};  ledeme ;Смеситель;шт;20;;;1\n");

        $this->assertSame([1, 0], [$job->rows_ok, $job->rows_error]);
        $this->assertSame('g-1', $product->fresh()->guid);
        $this->assertNull($nodash->fresh()->guid);
        $this->assertSame(1, $job->report['summary']['matched_by_key']);
        $this->assertSame(2, Product::count());
    }

    public function test_ambiguous_key_and_missing_brand_are_row_errors(): void
    {
        $this->product('LEDEME-A-1', null, ['brand' => 'LEDEME', 'article' => 'A-1']);
        $this->product('LEDEME-A-1-OLD', null, ['brand' => 'ledeme', 'article' => 'a-1']);
        // Товар с GUID в сопоставлении по ключу не участвует
        $this->product('TIM-B-1', 'g-tim', ['brand' => 'TIM', 'article' => 'B-1']);

        $job = $this->import(self::HEAD
            ."g-1;;A-1;LEDEME;Смеситель;шт;20;;;1\n"
            ."g-2;;A-1;;Смеситель;шт;20;;;1\n"
            ."g-3;;B-1;TIM;Смеситель;шт;20;;;1\n");

        $this->assertSame([
            2 => 'Неоднозначное сопоставление: «LEDEME» + «A-1» — у товаров сайта LEDEME-A-1, LEDEME-A-1-OLD; укажите sku нужного товара',
            3 => 'Нет бренда (brand): без sku товар сопоставляется и создаётся по «бренд + артикул»',
        ], $this->errors($job));
        // Третья строка — новый товар (у TIM-B-1 другой GUID), с суффиксом: код TIM-B-1 занят
        $this->assertSame([['line' => 4, 'guid' => 'g-3', 'sku' => 'TIM-B-1-2']], $job->report['created']);
        $this->assertSame(4, Product::count());
    }

    // --- Не найден: черновик ---

    public function test_not_found_creates_hidden_draft_with_generated_sku(): void
    {
        $this->product('TIM-BL 5810');
        $this->product('tim-bl 5810-2');

        $job = $this->import(self::HEAD
            ."8c4f5e1d;;BL 5810;Tim;Смеситель для ванны TIM BL5810;шт;10;;Смесители;1\n"
            ."9d5a6f2e;;FLX-050;TUBOFLEX;Подводка;шт;20;;;0\n");

        $this->assertSame([2, 0], [$job->rows_ok, $job->rows_error]);
        $this->assertSame([
            ['line' => 2, 'guid' => '8c4f5e1d', 'sku' => 'TIM-BL 5810-3'],
            ['line' => 3, 'guid' => '9d5a6f2e', 'sku' => 'TUBOFLEX-FLX-050'],
        ], $job->report['created']);
        $this->assertSame([['line' => 2, 'guid' => '8c4f5e1d', 'sku' => 'TIM-BL 5810-3', 'message' => 'Код TIM-BL 5810 занят, присвоен TIM-BL 5810-3']],
            $job->report['warnings']);

        $created = Product::where('guid', '8c4f5e1d')->sole();
        $this->assertSame(['TIM-BL 5810-3', 'Смеситель для ванны TIM BL5810', 'BL 5810', 'Tim', 'TIM:BL 5810', 'Смесители'],
            [$created->sku, $created->name, $created->article, $created->brand, $created->match_key, $created->group_1c]);
        // Черновик без категории, без цены: на витрине нет
        $this->assertSame([false, true, null, null, 0], [(bool) $created->is_published, (bool) $created->is_active, $created->category_id, $created->price, $created->stock]);
        $this->assertEquals(10, $created->vat_rate);
        $this->get('/search?q=BL+5810')->assertDontSee('Смеситель для ванны TIM BL5810');

        // active = 0 — ещё и скрыт как выведенный в 1С
        $this->assertSame([false, true], [(bool) Product::where('guid', '9d5a6f2e')->value('is_active'), (bool) Product::where('guid', '9d5a6f2e')->value('hidden_by_1c')]);

        // Повтор того же файла — товары уже найдены по GUID, новых нет
        $again = $this->import(self::HEAD."8c4f5e1d;;BL 5810;Tim;Смеситель для ванны TIM BL5810;шт;10;;Смесители;1\n");
        $this->assertSame([], $again->report['created'] ?? []);
        $this->assertSame(4, Product::count());
    }

    public function test_two_new_rows_with_same_code_get_suffixes(): void
    {
        $job = $this->import(self::HEAD."g-1;;A-1;SAVOL;Сифон;шт;20;;;1\ng-2;;a-1;Savol;Сифон 2;шт;20;;;1\n");

        $this->assertSame(['SAVOL-A-1', 'SAVOL-a-1-2'], array_column($job->report['created'], 'sku'));
    }

    // --- active ---

    public function test_active_zero_hides_and_active_one_shows_only_hidden_by_1c(): void
    {
        $admin = User::factory()->admin()->create();
        $byOneC = $this->product('A-1', 'g-1');
        $manual = $this->product('A-2', 'g-2', ['is_active' => false]);

        $job = $this->import(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;0\ng-2;;A-2;X;Товар;шт;20;;;0\n");
        $this->assertSame([false, true], [(bool) $byOneC->fresh()->is_active, $byOneC->fresh()->hidden_by_1c]);
        // Уже скрыт вручную — отметка 1С не ставится
        $this->assertSame([false, false], [(bool) $manual->fresh()->is_active, $manual->fresh()->hidden_by_1c]);
        $this->assertSame(1, $job->report['summary']['hidden']);
        $this->actingAs($admin)->get("/admin/product/{$byOneC->id}")->assertOk()->assertSee('data-hidden-by-1c', false);
        $this->actingAs($admin)->get("/admin/product/{$manual->id}")->assertOk()->assertDontSee('data-hidden-by-1c', false);

        $job = $this->import(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;1\ng-2;;A-2;X;Товар;шт;20;;;1\n");
        // Скрытый 1С — снова виден; скрытый вручную — остаётся скрытым
        $this->assertSame([true, false], [(bool) $byOneC->fresh()->is_active, $byOneC->fresh()->hidden_by_1c]);
        $this->assertFalse((bool) $manual->fresh()->is_active);
        $this->assertSame(1, $job->report['summary']['shown']);

        // Скрыт 1С, затем администратор скрыл/показал вручную — решение администратора
        $this->import(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;0\n");
        $this->actingAs($admin)->post("/admin/product/{$byOneC->id}/toggle-visibility");
        $this->actingAs($admin)->post("/admin/product/{$byOneC->id}/toggle-visibility");
        $this->assertSame([false, false], [(bool) $byOneC->fresh()->is_active, $byOneC->fresh()->hidden_by_1c]);
        $this->import(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;1\n");
        $this->assertFalse((bool) $byOneC->fresh()->is_active);
    }

    // --- Проверка строк ---

    public function test_row_validation_and_lengths(): void
    {
        $product = $this->product('A-1', 'g-1');
        $long = str_repeat('я', 300);

        $job = $this->import("guid;sku;article;brand;name;unit;vat_rate;min_qty;active\n"
            ."g-1;;A-1;X;Товар;шт;20,5;0;да\n"
            .";;A-2;X;Товар;шт;20;;1\n"
            ."g-3;;;X;;шт;;;1\n"
            ."g-4;;A-4;X;Товар;упаковка-из-двадцати-штук;20;;1\n"
            ."g-5;;A-5;X;{$long};шт;20;;1\n"
            ."g-1;;A-1;X;Товар;шт;20;;1\n");

        $this->assertSame([
            // min_qty «0» — не ошибка: колонка игнорируется
            2 => 'Ставка НДС «20,5»: допустимо 20, 10, 0 или «без НДС»; active «да»: ожидается 1 или 0',
            3 => 'Нет GUID',
            4 => 'Нет ставки НДС (vat_rate); Нет наименования (name); Нет артикула (article)',
            5 => 'Поле unit длиннее 20 символов',
            7 => 'GUID повторяется в файле (строка 2)',
        ], $this->errors($job));
        $this->assertSame('A-1', $product->fresh()->name);

        // Наименование длиннее колонки — обрезка с предупреждением, товар создан
        $created = Product::where('guid', 'g-5')->sole();
        $this->assertSame(255, mb_strlen($created->name));
        $this->assertSame('Поле name длиннее 255 символов — обрезано', $job->report['warnings'][0]['message']);
    }

    public function test_vat_rate_only_20_10_0_or_without_vat(): void
    {
        $job = $this->import(self::HEAD
            .'g-1;;A-1;X;Товар;шт;20;;;1
'
            .'g-2;;A-2;X;Товар;шт;10;;;1
'
            .'g-3;;A-3;X;Товар;шт;0;;;1
'
            .'g-4;;A-4;X;Товар;шт;20%;;;1
'
            .'g-5;;A-5;X;Товар;шт;20 %;;;1
'
            .'g-6;;A-6;X;Товар;шт;20.00;;;1
'
            .'g-7;;A-7;X;Товар;шт;Без  НДС;;;1
'
            .'g-8;;A-8;X;Товар;шт;БЕЗНДС;;;1
'
            .'g-9;;A-9;X;Товар;шт;15;;;1
'
            .'g-10;;A-10;X;Товар;шт;18%;;;1
'
            .'g-11;;A-11;X;Товар;шт;20.5;;;1
'
            .'g-12;;A-12;X;Товар;шт;100;;;1
'
            .'g-13;;A-13;X;Товар;шт;НДС 20;;;1
');

        $this->assertSame([
            10 => 'Ставка НДС «15»: допустимо 20, 10, 0 или «без НДС»',
            11 => 'Ставка НДС «18%»: допустимо 20, 10, 0 или «без НДС»',
            12 => 'Ставка НДС «20.5»: допустимо 20, 10, 0 или «без НДС»',
            13 => 'Ставка НДС «100»: допустимо 20, 10, 0 или «без НДС»',
            14 => 'Ставка НДС «НДС 20»: допустимо 20, 10, 0 или «без НДС»',
        ], $this->errors($job));

        // Строки с ошибкой не создают товар; остальные — со ставкой как в БД
        $rates = Product::whereNotNull('guid')->pluck('vat_rate', 'guid')->map(fn ($r) => $r === null ? null : (float) $r)->all();
        $this->assertEquals(['g-1' => 20, 'g-2' => 10, 'g-3' => 0, 'g-4' => 20, 'g-5' => 20, 'g-6' => 20, 'g-7' => null, 'g-8' => null], $rates);
        $this->assertSame(5, $job->rows_error);
    }

    public function test_invalid_vat_rate_does_not_change_existing_product(): void
    {
        $product = $this->product('A-1', 'g-1', ['article' => 'A-1', 'brand' => 'X', 'name' => 'Товар', 'vat_rate' => 20]);

        $job = $this->import(self::HEAD.'g-1;;A-1;X;Новое имя;шт;15;;;1
');

        $this->assertSame([2 => 'Ставка НДС «15»: допустимо 20, 10, 0 или «без НДС»'], $this->errors($job));
        $this->assertEquals([20, 'Товар'], [$product->fresh()->vat_rate, $product->fresh()->name]);
    }

    // --- Пробный прогон ---

    public function test_dry_run_reports_without_writing(): void
    {
        $byGuid = $this->product('A-1', 'g-1', ['name' => 'Старое']);
        $bySku = $this->product('LEDEME-L4755-2');
        $byKey = $this->product('TIM-OLD', null, ['brand' => 'TIM', 'article' => 'BL5800']);
        $hidden = $this->product('S-1', 'g-4');

        $job = $this->import(self::HEAD
            ."g-1;;A-1;X;Новое;шт;20;;;1\n"
            ."g-2;LEDEME-L4755-2;L4755-2;LEDEME;Смеситель;шт;20;;;1\n"
            ."g-3;;BL5800;TIM;Смеситель;шт;20;;;1\n"
            ."g-4;;S-1;SAVOL;Сифон;шт;20;;;0\n"
            ."g-5;;NEW-1;SAVOL;Новинка;шт;20;;;1\n"
            ."g-6;;A-1;;Без бренда;шт;20;;;1\n", dryRun: true);

        $this->assertSame([ExchangeJobStatus::Done, true, 5, 1], [$job->status, $job->dry_run, $job->rows_ok, $job->rows_error]);
        $this->assertStringStartsWith('Пробный прогон, данные сайта не изменены. Сопоставлено по GUID 2, по SKU 1, по бренду и артикулу 1; создано 1', $job->message);

        // Ничего не записано
        $this->assertSame('Старое', $byGuid->fresh()->name);
        $this->assertNull($bySku->fresh()->guid);
        $this->assertNull($byKey->fresh()->guid);
        $this->assertTrue((bool) $hidden->fresh()->is_active);
        $this->assertSame(4, Product::count());

        $summary = $job->report['summary'];
        $this->assertSame([2, 1, 1, 1, 1, 1], [$summary['matched_by_guid'], $summary['matched_by_sku'], $summary['matched_by_key'],
            $summary['created'], $summary['hidden'], $summary['errors']]);
        $this->assertSame(['guid' => 2, 'is_active' => 1, 'name' => 4], array_intersect_key($summary['fields'], ['guid' => 0, 'is_active' => 0, 'name' => 0]));
        $this->assertSame([['line' => 6, 'guid' => 'g-5', 'sku' => 'SAVOL-NEW-1']], $job->report['created']);
        // Все товары сайта нашли пару; новый товар без пары — в списке
        $this->assertArrayNotHasKey('unmatched', $job->report);

        $alone = $this->product('ALONE-1');
        $job = $this->import(self::HEAD.'g-1;;A-1;X;Новое;шт;20;;;1
', dryRun: true);
        $this->assertSame([
            ['guid' => null, 'sku' => 'LEDEME-L4755-2'], ['guid' => null, 'sku' => 'TIM-OLD'],
            ['guid' => 'g-4', 'sku' => 'S-1'], ['guid' => null, 'sku' => 'ALONE-1'],
        ], $job->report['unmatched']);
        $this->assertSame(4, $job->report['summary']['unmatched']);
    }

    public function test_dry_run_predicts_exactly_what_apply_does(): void
    {
        $this->product('A-1', 'g-1', ['name' => 'Старое', 'brand' => 'X', 'article' => 'A-1']);
        $this->product('LEDEME-L4755-2');
        $this->product('TIM-OLD', null, ['brand' => 'TIM', 'article' => 'BL5800']);
        $this->product('SAVOL-NEW-1');
        $csv = self::HEAD
            ."g-1;;A-1;X;Новое;шт;10;;Группа;0\n"
            ."g-2;LEDEME-L4755-2;L4755-2;LEDEME;Смеситель;компл;20;;;1\n"
            ."g-3;;BL5800;TIM;Смеситель;шт;20;123;;1\n"
            ."g-5;;NEW-1;SAVOL;Новинка;шт;20;;;1\n"
            ."g-6;;A-1;;Без бренда;шт;20;;;1\n";

        $dry = $this->import($csv, 'full', dryRun: true);
        $real = $this->import($csv, 'full');

        $summary = fn (ExchangeJob $job) => array_diff_key($job->report['summary'], ['unmatched' => 0]);
        $this->assertSame($summary($dry), $summary($real));
        foreach (['created', 'warnings'] as $part) {
            $this->assertSame($dry->report[$part], $real->report[$part], $part);
        }
        $this->assertSame([$dry->rows_ok, $dry->rows_error], [$real->rows_ok, $real->rows_error]);
        $this->assertSame(
            ExchangeJobError::where('exchange_job_id', $dry->id)->orderBy('line')->pluck('message', 'line')->all(),
            $this->errors($real),
        );
        // Без префикса пробного прогона сообщения совпадают
        $this->assertSame(str_replace('Пробный прогон, данные сайта не изменены. ', '', $dry->message), $real->message);

        // Предсказанное записано: GUID привязаны, поля изменены, создан товар с предсказанным кодом
        $this->assertSame(['g-1', 'g-2', 'g-3'], Product::whereIn('sku', ['A-1', 'LEDEME-L4755-2', 'TIM-OLD'])->orderBy('guid')->pluck('guid')->all());
        $this->assertSame(['Новое', false, 'Группа'], [Product::where('guid', 'g-1')->value('name'), (bool) Product::where('guid', 'g-1')->value('is_active'), Product::where('guid', 'g-1')->value('group_1c')]);
        $this->assertSame($dry->report['created'][0]['sku'], Product::where('guid', 'g-5')->value('sku'));
        $this->assertSame('SAVOL-NEW-1-2', Product::where('guid', 'g-5')->value('sku'));
    }

    // --- mode=full ---

    public function test_full_lists_missing_without_hiding(): void
    {
        foreach (range(1, 20) as $i) {
            $this->product("P-{$i}", "g-{$i}");
        }

        $rows = implode('', array_map(fn ($i) => "g-{$i};;P-{$i};X;P-{$i};шт;20;;;1\n", range(1, 19)));
        $job = $this->import(self::HEAD.$rows, 'full');

        $this->assertSame(ExchangeJobStatus::Done, $job->status);
        $this->assertSame([['guid' => 'g-20', 'sku' => 'P-20']], $job->report['missing']);
        $this->assertStringContainsString('mode=full: 1 товаров с GUID нет в файле — не скрыты, список в отчёте задания', $job->message);
        // Не скрыт и не удалён
        $this->assertTrue((bool) Product::where('guid', 'g-20')->sole()->is_active);
    }

    public function test_full_guard_fails_job_and_flags_dry_run(): void
    {
        foreach (range(1, 10) as $i) {
            $this->product("P-{$i}", "g-{$i}", ['name' => 'Было']);
        }
        $rows = implode('', array_map(fn ($i) => "g-{$i};;P-{$i};X;Стало;шт;20;;;1\n", range(1, 8)));

        $job = $this->import(self::HEAD.$rows, 'full');
        $this->assertSame(ExchangeJobStatus::Failed, $job->status);
        $this->assertSame('mode=full: в файле нет 2 из 10 товаров с GUID (20 % при пороге 10 %) — файл похож на неполный, данные сайта не изменены', $job->message);
        $this->assertSame(0, Product::where('name', 'Стало')->count());

        // Пробный прогон — done, с отметкой
        $dry = $this->import(self::HEAD.$rows, 'full', dryRun: true);
        $this->assertSame(ExchangeJobStatus::Done, $dry->status);
        $this->assertStringContainsString('предохранитель mode=full сработал бы: в файле нет 2 из 10 товаров с GUID', $dry->message);

        // Порог — из настроек
        Setting::put(['exchange_full_missing_percent' => '25']);
        $this->assertSame(ExchangeJobStatus::Done, $this->import(self::HEAD.$rows, 'full')->status);
        $this->assertSame(8, Product::where('name', 'Стало')->count());
    }

    // --- Админка ---

    public function test_admin_job_page_shows_report(): void
    {
        $admin = User::factory()->admin()->create();
        $this->product('TIM-A-1');
        $this->product('P-1', 'g-old');

        $job = $this->import(self::HEAD."g-1;;A-1;TIM;Смеситель;шт;20;;;1\n", 'full', dryRun: true);

        $html = $this->actingAs($admin)->get("/admin/exchange/job/{$job->id}")->assertOk()
            ->assertSee('Пробный прогон — данные не менялись')
            ->assertSee('Созданные товары (черновики) — 1')
            ->assertSee('Код TIM-A-1 занят, присвоен TIM-A-1-2')
            ->assertSee('Товары с GUID, которых нет в файле (не скрыты) — 1')
            ->assertSee('Предохранитель mode=full сработал бы')
            ->getContent();
        $this->assertSame(1, substr_count($html, 'data-exchange-created'));
        $this->assertSame(1, substr_count($html, 'data-exchange-missing'));
    }

    // --- API ---

    public function test_api_dry_run_and_report(): void
    {
        ExchangeIp::create(['ip' => '127.0.0.1']);
        [$token, $plain] = ExchangeToken::issue('1С', null);
        $this->product('TIM-BL5800');

        $id = $this->call('POST', '/api/1c/v1/catalog?dry_run=1', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain, 'CONTENT_TYPE' => 'text/csv', 'REMOTE_ADDR' => '127.0.0.1',
        ], self::HEAD."g-1;;BL5800;TIM;Смеситель;шт;20;;;1\n")->assertStatus(202)->json('job_id');

        $this->assertTrue(ExchangeJob::find($id)->dry_run);
        $this->call('GET', "/api/1c/v1/jobs/{$id}", [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$plain, 'REMOTE_ADDR' => '127.0.0.1'])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('created', [['line' => 2, 'guid' => 'g-1', 'sku' => 'TIM-BL5800-2']])
            ->assertJsonPath('warnings.0.message', 'Код TIM-BL5800 занят, присвоен TIM-BL5800-2')
            ->assertJsonPath('warnings_truncated', false)
            ->assertJsonPath('summary.created', 1);
        $this->assertSame(1, Product::count());
    }
}
