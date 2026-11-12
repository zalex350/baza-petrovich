<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Models\Category;
use App\Models\ExchangeJob;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\User;
use App\Services\Exchange\ExchangeJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Номенклатура в админке: ручная загрузка с предпросмотром (ManualCatalogUpload), «Новые из 1С»
 * (AdminNewFrom1cController), поля 1С в карточке созданного обменом товара.
 */
class CatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = "guid;sku;article;brand;name;unit;vat_rate;barcode;group_1c;active\n";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function product(string $sku, ?string $guid = null, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 100, 'stock' => 5, 'is_active' => true]);

        if ($guid !== null) {
            $product->forceFill(['guid' => $guid])->save();
        }

        return $product;
    }

    private function upload(string $content, string $name = 'catalog.csv', string $mode = 'delta')
    {
        return $this->actingAs($this->admin)->post('/admin/exchange/catalog', [
            'file' => UploadedFile::fake()->createWithContent($name, $content), 'mode' => $mode,
        ]);
    }

    /** Импорт обменом (как от 1С) — для «Новых из 1С» */
    private function import(string $csv): void
    {
        $job = new ExchangeJob(['type' => ExchangeJobType::Catalog, 'direction' => 'import', 'mode' => 'delta',
            'status' => ExchangeJobStatus::Queued, 'received_at' => now()]);
        $job->id = $job->newUniqueId();
        $job->file_path = "2026/11/{$job->id}.csv";
        Storage::disk('exchange')->put($job->file_path, $csv);
        $job->save();
        app(ExchangeJobs::class)->process($job);
    }

    // --- Ручная загрузка номенклатуры ---

    public function test_upload_preview_shows_binding_and_does_not_write(): void
    {
        $bySku = $this->product('LEDEME-L4755-2');
        $byKey = $this->product('TIM-OLD', null, ['brand' => 'TIM', 'article' => 'BL5800']);
        $withGuid = $this->product('S-1', 'g-4', ['name' => 'Сифон старый', 'article' => 'S-1', 'brand' => 'SAVOL']);
        $this->product('ALONE-1');

        $this->upload(self::HEAD
            ."g-1;LEDEME-L4755-2;L4755-2;LEDEME;Смеситель LEDEME;шт;20;;;1\n"
            ."g-2;;BL5800;TIM;Смеситель TIM;шт;20;;;1\n"
            ."g-4;;S-1;SAVOL;Сифон новый;шт;20;;;1\n"
            ."g-5;;NEW-1;SAVOL;Новинка;шт;20;;;1\n"
            ."g-6;;X-1;;Без бренда;шт;20;;;1\n")->assertRedirect('/admin/exchange/catalog/preview');

        $html = $this->actingAs($this->admin)->get('/admin/exchange/catalog/preview')->assertOk()
            ->assertSee('Привязка GUID к товарам сайта — 2')
            ->assertSee('по бренду и артикулу')
            ->assertSee('Изменения у товаров с GUID — 1')
            ->assertSee('Сифон старый')
            ->assertSee('Созданные товары (черновики) — 1')
            ->assertSee('SAVOL-NEW-1')
            ->assertSee('Товары сайта без пары в файле — 1')
            ->assertSee('ALONE-1')
            ->assertSee('Нет бренда (brand)')
            ->assertSee('data-catalog-apply', false)
            ->getContent();
        $this->assertStringContainsString("data-preview-binding=\"{$bySku->id}\"", $html);
        $this->assertStringContainsString("data-preview-binding=\"{$byKey->id}\"", $html);
        $this->assertStringContainsString("data-preview-changed=\"{$withGuid->id}\"", $html);
        $this->assertMatchesRegularExpression('#data-preview-count="Будет создано">1<#', $html);

        // Предпросмотр — без записи и без задания
        $this->assertNull($bySku->fresh()->guid);
        $this->assertSame(0, ExchangeJob::count());
        $this->assertSame(4, Product::count());
    }

    public function test_apply_equals_dry_run_and_lands_in_journal(): void
    {
        $bySku = $this->product('LEDEME-L4755-2');
        $csv = self::HEAD."g-1;LEDEME-L4755-2;L4755-2;LEDEME;Смеситель;шт;20;;;1\ng-2;;NEW-1;SAVOL;Новинка;шт;20;;;1\n";

        $this->upload($csv);
        $this->actingAs($this->admin)->get('/admin/exchange/catalog/preview')->assertSee('SAVOL-NEW-1');
        $response = $this->actingAs($this->admin)->post('/admin/exchange/catalog/apply');

        $job = ExchangeJob::sole();
        $response->assertRedirect("/admin/exchange/job/{$job->id}")->assertSessionHas('success');
        $this->assertSame([ExchangeJobType::Catalog, 'manual', $this->admin->id, false], [$job->type, $job->source, $job->user_id, $job->dry_run]);
        $this->assertSame('g-1', $bySku->fresh()->guid);
        $this->assertSame([['line' => 3, 'guid' => 'g-2', 'sku' => 'SAVOL-NEW-1']], $job->report['created']);
        $this->assertSame('SAVOL-NEW-1', Product::where('guid', 'g-2')->value('sku'));

        // Черновик применён — повторно не применяется
        $this->actingAs($this->admin)->post('/admin/exchange/catalog/apply')->assertRedirect('/admin/exchange?tab=catalog')->assertSessionHas('error');
    }

    public function test_xlsx_upload_with_numeric_cells(): void
    {
        $this->product('LEDEME-L4755-2');

        $path = tempnam(sys_get_temp_dir(), 'cat').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['GUID', 'Article', 'Brand', 'Name', 'Unit', 'VAT_rate', 'Barcode', 'Active', 'SKU']));
        $writer->addRow(Row::fromValues(['g-1', 'L4755-2', 'LEDEME', 'Смеситель', 'шт', 20, 4810000000017, 1, 'LEDEME-L4755-2']));
        $writer->close();

        $this->actingAs($this->admin)->post('/admin/exchange/catalog', [
            'file' => new UploadedFile($path, 'catalog.xlsx', null, null, true), 'mode' => 'delta',
        ])->assertRedirect('/admin/exchange/catalog/preview');
        $this->actingAs($this->admin)->post('/admin/exchange/catalog/apply');

        $product = Product::where('sku', 'LEDEME-L4755-2')->sole();
        $this->assertSame(['g-1', '4810000000017'], [$product->guid, $product->barcode]);
        $this->assertEquals(20, $product->vat_rate);
    }

    public function test_preview_refuses_missing_columns_and_guard(): void
    {
        $this->upload("guid;sku;name\ng-1;A;Б\n");
        $this->actingAs($this->admin)->get('/admin/exchange/catalog/preview')->assertOk()
            ->assertSee('Нет обязательных колонок в первой строке: article, unit, vat_rate, active')
            ->assertDontSee('data-catalog-apply', false);

        foreach (range(1, 10) as $i) {
            $this->product("P-{$i}", "g-{$i}");
        }
        $this->upload(self::HEAD."g-1;;P-1;X;P;шт;20;;;1\n", mode: 'full');
        $this->actingAs($this->admin)->get('/admin/exchange/catalog/preview')->assertOk()
            ->assertSee('сработает предохранитель mode=full')
            ->assertSee('в файле нет 9 из 10 товаров с GUID')
            ->assertDontSee('data-catalog-apply', false);
    }

    public function test_cancel_and_template(): void
    {
        $this->upload(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;1\n");
        $this->assertCount(1, Storage::disk('exchange')->files('uploads'));

        $this->actingAs($this->admin)->post('/admin/exchange/catalog/cancel')->assertRedirect('/admin/exchange?tab=catalog');
        $this->assertCount(0, Storage::disk('exchange')->files('uploads'));

        // Черновики цен и номенклатуры — отдельно
        $this->upload(self::HEAD."g-1;;A-1;X;Товар;шт;20;;;1\n");
        $this->actingAs($this->admin)->get('/admin/exchange/prices/preview')->assertRedirect('/admin/exchange?tab=upload');

        $this->actingAs($this->admin)->get('/admin/exchange?tab=catalog')->assertOk()->assertSee('data-catalog-upload', false);

        $file = $this->actingAs($this->admin)->get('/admin/exchange/catalog/template')->assertOk()
            ->assertDownload('catalog-template.xlsx')->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($file);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $this->assertSame(['guid', 'sku', 'article', 'brand', 'name', 'unit', 'vat_rate', 'min_qty', 'qty_step', 'barcode', 'group_1c', 'active'], $row->toArray());
                break;
            }
            break;
        }
        $reader->close();
    }

    // --- Новые из 1С ---

    public function test_new_from_1c_lists_drafts_and_assigns_category_and_publishes(): void
    {
        $category = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
        $hidden = Category::create(['name' => 'Без категории', 'slug' => 'none', 'is_hidden' => true]);
        $other = $this->product('OTHER-1', null, ['is_published' => false]);   // черновик не из 1С
        $this->import(self::HEAD
            ."g-1;;A-1;TIM;Смеситель TIM A-1;шт;20;;Смесители;1\n"
            ."g-2;;A-2;TIM;Смеситель TIM A-2;шт;20;;Смесители;1\n"
            ."g-3;;S-1;SAVOL;Сифон;шт;20;;Сифоны;0\n");
        [$a1, $a2, $s1] = Product::whereNotNull('guid')->orderBy('id')->get()->all();
        $s1->update(['category_id' => $hidden->id]);

        $html = $this->actingAs($this->admin)->get('/admin/products/new-1c')->assertOk()
            ->assertSee('Без категории (3)')->assertSee('С категорией (0)')->assertSee('выведен в 1С')
            ->getContent();
        foreach ([$a1, $a2, $s1] as $p) {
            $this->assertStringContainsString("data-new1c-row=\"{$p->id}\"", $html);
        }
        $this->assertStringNotContainsString("data-new1c-row=\"{$other->id}\"", $html);
        $this->actingAs($this->admin)->get('/admin/products')->assertSee('Новые из 1С (3)');

        // Группа 1С — фильтр
        $this->actingAs($this->admin)->get('/admin/products/new-1c?group=Сифоны')->assertOk()
            ->assertSee("data-new1c-row=\"{$s1->id}\"", false)->assertDontSee("data-new1c-row=\"{$a1->id}\"", false);

        // Назначить категорию — без публикации
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$a1->id], 'action' => 'category', 'category_id' => $category->id])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Категория назначена: 1');
        $this->assertSame([$category->id, false], [$a1->fresh()->category_id, (bool) $a1->fresh()->is_published]);

        // Опубликовать: с категорией — да; без категории (и со служебной) — нет; чужой черновик не трогается
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$a1->id, $a2->id, $s1->id, $other->id], 'action' => 'publish'])
            ->assertSessionHas('success', 'Опубликовано: 1; без категории не опубликовано: 2');
        $this->assertTrue((bool) $a1->fresh()->is_published);
        $this->assertFalse((bool) $a2->fresh()->is_published);
        $this->assertFalse((bool) $other->fresh()->is_published);

        // Назначить и опубликовать; выведенный в 1С (active = 0) остаётся скрытым
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$a2->id, $s1->id], 'action' => 'category_publish', 'category_id' => $category->id])
            ->assertSessionHas('success', 'Категория назначена: 2; Опубликовано: 2');
        $this->assertTrue((bool) $a2->fresh()->is_published);
        $this->assertSame([true, false], [(bool) $s1->fresh()->is_published, (bool) $s1->fresh()->is_active]);
        $this->actingAs($this->admin)->get('/admin/products/new-1c?state=all')->assertSee('Все (0)');

        // Опубликованный — на витрине
        $this->get('/search?q=A-1')->assertSee('Смеситель TIM A-1');
    }

    public function test_new_from_1c_validation_and_text_draft(): void
    {
        $category = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
        $hidden = Category::create(['name' => 'Без категории', 'slug' => 'none', 'is_hidden' => true]);
        $this->import(self::HEAD."g-1;;A-1;TIM;Смеситель;шт;20;;;1\n");
        $product = Product::where('guid', 'g-1')->sole();

        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['action' => 'publish'])->assertSessionHasErrors(['ids' => 'Отметьте товары']);
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$product->id], 'action' => 'category'])->assertSessionHasErrors(['category_id' => 'Выберите категорию']);
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$product->id], 'action' => 'category', 'category_id' => $hidden->id])->assertSessionHasErrors('category_id');

        // Черновик текстов — публикуется из карточки (там же применяются тексты)
        ProductDraft::create(['product_id' => $product->id, 'data' => ['description' => 'Текст']]);
        $this->actingAs($this->admin)->post('/admin/products/new-1c', ['ids' => [$product->id], 'action' => 'category_publish', 'category_id' => $category->id])
            ->assertSessionHas('success', 'Категория назначена: 1; Опубликовано: 0; с черновиком текстов — опубликуйте в карточке: 1');
        $this->assertFalse((bool) $product->fresh()->is_published);

        // Не администратор — нет доступа
        $status = $this->actingAs(User::factory()->create())->get('/admin/products/new-1c')->status();
        $this->assertContains($status, [302, 403]);
    }

    // --- Поля 1С в карточке созданного обменом товара ---

    public function test_created_product_has_1c_fields_locked(): void
    {
        $category = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
        $this->import(self::HEAD."g-1;;BL 5810;TIM;Смеситель TIM BL5810;компл;20;;Смесители;1\n");
        $product = Product::where('guid', 'g-1')->sole();

        $html = $this->actingAs($this->admin)->get("/admin/product/{$product->id}")->assertOk()->getContent();
        foreach (['f-name', 'f-brand', 'f-article', 'f-sku', 'f-unit'] as $id) {
            $this->assertMatchesRegularExpression('/<input[^>]*id="'.$id.'"[^>]*\sdisabled(?=[\s=>\/])/s', $html, $id);
        }
        $this->assertMatchesRegularExpression('/<select[^>]*id="f-vat"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        $this->assertStringContainsString('value="TIM-BL 5810"', $html);

        // Подмена полей 1С в запросе не проходит; сайт — категория и отображаемое название — сохраняются
        $this->actingAs($this->admin)->post("/admin/product/{$product->id}", [
            'action' => 'publish', 'name' => 'Подмена', 'brand' => 'X', 'article' => 'Y', 'sku' => 'Z', 'vat_rate' => 10,
            'category_id' => $category->id, 'display_name' => 'Смеситель для ванны', 'slug' => '',
            'description' => '', 'specifications' => '', 'package_contents' => '',
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(['Смеситель TIM BL5810', 'TIM', 'BL 5810', 'TIM-BL 5810', 'компл'], [$product->name, $product->brand, $product->article, $product->sku, $product->unit]);
        $this->assertEquals(20, $product->vat_rate);
        $this->assertSame([$category->id, 'Смеситель для ванны', true], [$product->category_id, $product->display_name, (bool) $product->is_published]);
    }
}
