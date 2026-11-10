<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Коды товаров клиентов в админке: блок «Коды клиентов» в карточке товара и вкладка
 * «Коды товаров» клиента — список, поиск, удаление, загрузка из Excel / CSV с предпросмотром.
 */
class ClientCodesAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $org;

    private Organization $other;

    private Product $a;

    private Product $b;

    private Product $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->org = Organization::factory()->create(['name' => 'ООО Сантехторг']);
        $this->other = Organization::factory()->create(['name' => 'ЧУП Вода']);
        $this->a = $this->product('GR-001', 'Смеситель Grohe');
        $this->b = $this->product('HG-002', 'Смеситель Hansgrohe');
        $this->c = $this->product('OR-003', 'Смеситель Oras');
    }

    private function product(string $sku, string $name): Product
    {
        return Product::create(['name' => $name, 'sku' => $sku, 'price' => 10, 'stock' => 1]);
    }

    private function code(Product $product, Organization $org, string $code): ProductClientCode
    {
        return ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $org->id, 'code' => $code]);
    }

    /** @return array<int, string> product_id → code у организации */
    private function codes(?Organization $org = null): array
    {
        $codes = ProductClientCode::where('organization_id', ($org ?? $this->org)->id)->pluck('code', 'product_id')->all();
        ksort($codes);

        return $codes;
    }

    private function csv(string $content, string $name = 'codes.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(UploadedFile $file, string $mode = 'add')
    {
        return $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import", ['file' => $file, 'mode' => $mode]);
    }

    // --- Карточка товара: блок «Коды клиентов» ---

    public function test_product_page_shows_codes_and_offers_only_clients_without_code(): void
    {
        $this->code($this->a, $this->org, 'КЛ-17');

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->a->id}")->assertOk()->getContent();

        $block = substr($html, strpos($html, 'data-admin-client-codes'));
        $this->assertStringContainsString('ООО Сантехторг', $block);
        $this->assertStringContainsString('value="КЛ-17"', $block);

        $add = substr($block, strpos($block, 'data-client-code-add'));
        $add = substr($add, 0, strpos($add, '</form>'));
        $this->assertStringContainsString('>ЧУП Вода</option>', $add);
        $this->assertStringNotContainsString('ООО Сантехторг', $add);
    }

    public function test_admin_adds_updates_and_deletes_code_on_product(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes", [
            'client_code_organization' => $this->org->id,
            'client_code' => '  КЛ-17 ',
        ])->assertRedirect("/admin/product/{$this->a->id}#client-codes")->assertSessionHasNoErrors();

        $code = ProductClientCode::firstOrFail();
        $this->assertSame('КЛ-17', $code->code);

        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes/{$code->id}", [
            'client_codes' => [$code->id => 'КЛ-18'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('КЛ-18', $code->fresh()->code);

        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes/{$code->id}/delete")->assertSessionHas('success');
        $this->assertSame(0, ProductClientCode::count());
    }

    public function test_code_taken_by_other_product_shows_error_under_field(): void
    {
        $this->code($this->b, $this->org, 'X-1');

        $this->actingAs($this->admin)
            ->from("/admin/product/{$this->a->id}")
            ->post("/admin/product/{$this->a->id}/client-codes", ['client_code_organization' => $this->org->id, 'client_code' => 'x-1'])
            ->assertSessionHasErrors(['client_code' => 'Код «x-1» у этого клиента уже занят товаром HG-002 — Смеситель Hansgrohe.']);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->a->id}")->getContent();
        $this->assertMatchesRegularExpression('#id="f-client_code-error"[^>]*data-field-error>\s*Код «x-1» у этого клиента уже занят#u', $html);
        $this->assertStringContainsString('Проверьте выделенные поля', $html);

        // У другого клиента тот же код — можно
        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes", ['client_code_organization' => $this->other->id, 'client_code' => 'X-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('X-1', $this->a->codeFor($this->other));
    }

    public function test_second_code_for_same_client_and_empty_fields_are_rejected(): void
    {
        $this->code($this->a, $this->org, 'A');

        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes", ['client_code_organization' => $this->org->id, 'client_code' => 'B'])
            ->assertSessionHasErrors(['client_code_organization' => 'У этого клиента уже есть код этого товара — измените его в таблице.']);

        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes", ['client_code_organization' => '', 'client_code' => ''])
            ->assertSessionHasErrors(['client_code_organization' => 'Выберите клиента.', 'client_code' => 'Введите код клиента.']);

        $this->assertSame(['A'], ProductClientCode::pluck('code')->all());
    }

    public function test_update_to_taken_code_is_rejected_under_row_field(): void
    {
        $this->code($this->b, $this->org, 'X-1');
        $code = $this->code($this->a, $this->org, 'A-1');

        $this->actingAs($this->admin)
            ->from("/admin/product/{$this->a->id}")
            ->post("/admin/product/{$this->a->id}/client-codes/{$code->id}", ['client_codes' => [$code->id => 'X-1']])
            ->assertSessionHasErrors("client_codes.{$code->id}");

        $this->assertSame('A-1', $code->fresh()->code);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->a->id}")->getContent();
        $this->assertStringContainsString('id="f-client_codes-'.$code->id.'-error"', $html);

        // Код другого товара через адрес этого товара не меняется
        $foreign = ProductClientCode::where('product_id', $this->b->id)->first();
        $this->actingAs($this->admin)->post("/admin/product/{$this->a->id}/client-codes/{$foreign->id}/delete")->assertNotFound();
    }

    public function test_only_admin_can_edit_codes(): void
    {
        $client = User::factory()->create(['organization_id' => $this->org->id]);
        $code = $this->code($this->a, $this->org, 'A');

        $this->actingAs($client)->post("/admin/product/{$this->a->id}/client-codes", ['client_code_organization' => $this->org->id, 'client_code' => 'Z'])->assertForbidden();
        $this->actingAs($client)->post("/admin/organization/{$this->org->id}/codes/{$code->id}/delete")->assertForbidden();
        $this->actingAs($client)->post("/admin/organization/{$this->org->id}/codes/import", ['mode' => 'add'])->assertForbidden();
        $this->actingAs($client)->get("/admin/organization/{$this->org->id}?tab=codes")->assertForbidden();

        $this->assertSame(['A'], ProductClientCode::pluck('code')->all());
    }

    // --- Клиент: вкладка «Коды товаров» ---

    public function test_codes_tab_lists_searches_and_deletes(): void
    {
        $this->code($this->a, $this->org, 'КЛ-1');
        $keep = $this->code($this->b, $this->org, 'KL-2');
        $this->code($this->c, $this->other, 'NOT-MINE');

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}")
            ->assertOk()
            ->assertSee('data-tab="codes"', false)
            ->assertSee('Реквизиты')
            ->assertDontSee('data-codes-upload', false);

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}?tab=codes")
            ->assertOk()
            ->assertSeeInOrder(['GR-001', 'Смеситель Grohe', 'КЛ-1', 'HG-002', 'KL-2'])
            ->assertDontSee('NOT-MINE')
            ->assertDontSee('Адреса доставки');

        // Поиск: по коду клиента, нашему артикулу, названию
        foreach (['KL-2' => 'HG-002', 'gr-001' => 'GR-001', 'Hansgrohe' => 'HG-002'] as $q => $sku) {
            $html = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}?tab=codes&q=".urlencode($q))->getContent();
            $this->assertSame(1, substr_count($html, 'data-client-code="'), $q);
            $this->assertStringContainsString($sku, $html);
        }

        $code = ProductClientCode::where('code', 'КЛ-1')->first();
        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/{$code->id}/delete?q=KL")
            ->assertRedirect("/admin/organization/{$this->org->id}?tab=codes&q=KL");

        $this->assertSame([$this->b->id => 'KL-2'], $this->codes());
        $this->assertNotNull($keep->fresh());

        // Чужой код через адрес этого клиента не удаляется
        $foreign = ProductClientCode::where('code', 'NOT-MINE')->first();
        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/{$foreign->id}/delete")->assertNotFound();
    }

    public function test_template_is_xlsx_with_two_text_columns(): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/template")->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('client-codes-template.xlsx', $response->headers->get('Content-Disposition'));

        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new XlsxReader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $this->assertSame(['Наш артикул', 'Код клиента'], $row->toArray());
                break;
            }
        }
        $reader->close();

        // Ячейки — текст (формат «@», numFmtId 49), чтобы введённые коды 00123 не стали числами
        $zip = new ZipArchive;
        $zip->open($path);
        $styles = $zip->getFromName('xl/styles.xml');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertMatchesRegularExpression('/<xf[^>]*numFmtId="49"/', $styles);
        $this->assertStringContainsString('<c r="B1000"', $sheet);
    }

    public function test_preview_shows_add_update_and_row_errors_without_changing_db(): void
    {
        $this->code($this->a, $this->org, 'OLD-A');
        $this->code($this->c, $this->org, 'BUSY');

        $csv = "Наш артикул;Код клиента\n"
            ."GR-001;NEW-A\n"          // 2: обновить
            ."HG-002;B-1\n"            // 3: добавить
            ."NOPE-1;Z-1\n"            // 4: артикул не найден
            ."OR-003;\n"               // 5: пустой код
            .";Q-1\n"                  // 6: пустой артикул
            ."\n"                      // 7: пустая строка — пропускается
            ."XX-1;DUP\n"              // 8: (артикул не найден — раньше повтора)
            ."ZZ-9;dup\n";             // 9

        $this->upload($this->csv($csv))->assertRedirect("/admin/organization/{$this->org->id}/codes/import");

        $html = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-count-add>1<#', $html);
        $this->assertMatchesRegularExpression('#data-count-update>1<#', $html);
        $this->assertMatchesRegularExpression('#data-count-errors>5<#', $html);

        $update = substr($html, strpos($html, 'data-section="update"'));
        $this->assertMatchesRegularExpression('#OLD-A.*NEW-A#s', substr($update, 0, strpos($update, '</section>')));

        $errors = substr($html, strpos($html, 'data-section="errors"'));
        $errors = substr($errors, 0, strpos($errors, '</section>'));
        $this->assertStringContainsString('Артикул не найден в каталоге.', $errors);
        $this->assertStringContainsString('Пустое поле «Код клиента».', $errors);
        $this->assertStringContainsString('Пустое поле «Наш артикул».', $errors);

        // База не менялась
        $this->assertSame([$this->a->id => 'OLD-A', $this->c->id => 'BUSY'], $this->codes());
    }

    public function test_duplicates_in_file_and_busy_code_are_errors(): void
    {
        $this->code($this->c, $this->org, 'BUSY');

        $csv = "Код клиента,Наш артикул\n"   // порядок столбцов — любой, разделитель — запятая
            ."D-1,GR-001\n"
            ."d-1,HG-002\n"                  // код повторяется (без учёта регистра)
            ."BUSY,GR-001\n"                 // GR-001 второй раз, код занят OR-003
            ."P-1,HG-002\n";

        $this->upload($this->csv($csv));
        $html = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")->getContent();

        $this->assertStringContainsString('Код повторяется в файле (строки 2, 3).', $html);
        $this->assertStringContainsString('Товар повторяется в файле (строки 2, 4)', $html);
        $this->assertStringContainsString('Товар повторяется в файле (строки 3, 5)', $html);
        $this->assertMatchesRegularExpression('#data-count-add>0<#', $html);

        // Занятый код — отдельной проверкой
        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;busy\n"));
        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSee('Код уже занят другим товаром: OR-003 — Смеситель Oras.');
    }

    public function test_apply_add_mode_skips_errors_and_shows_report(): void
    {
        $this->code($this->a, $this->org, 'OLD-A');
        $this->code($this->c, $this->org, 'KEEP-C');
        $this->code($this->b, $this->other, 'OTHER-ORG');

        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;NEW-A\nHG-002;B-1\nNOPE;Z\n"));

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")
            ->assertRedirect("/admin/organization/{$this->org->id}?tab=codes");

        $this->assertSame([$this->a->id => 'NEW-A', $this->b->id => 'B-1', $this->c->id => 'KEEP-C'], $this->codes());
        $this->assertSame([$this->b->id => 'OTHER-ORG'], $this->codes($this->other));

        $html = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}?tab=codes")->getContent();
        $this->assertStringContainsString('data-codes-report', $html);
        $this->assertMatchesRegularExpression('#data-report-added>1<#', $html);
        $this->assertMatchesRegularExpression('#data-report-updated>1<#', $html);
        $this->assertMatchesRegularExpression('#data-report-deleted>0<#', $html);
        $this->assertStringContainsString('Пропущено строк с ошибками: 1', $html);

        // Загрузка применена — повторно применить нельзя
        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertRedirect("/admin/organization/{$this->org->id}?tab=codes");
    }

    public function test_replace_mode_deletes_codes_missing_in_file(): void
    {
        $this->code($this->a, $this->org, 'A');
        $this->code($this->c, $this->org, 'C');

        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;A\nHG-002;B\n"), 'replace');

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSee('data-section="delete"', false)
            ->assertSeeInOrder(['Удалить', 'OR-003', 'C'])
            ->assertSee('Применить загрузку?');

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")->assertSessionHas('codes_report');

        $this->assertSame([$this->a->id => 'A', $this->b->id => 'B'], $this->codes());
    }

    public function test_replace_mode_with_errors_cannot_be_applied(): void
    {
        $this->code($this->c, $this->org, 'C');

        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;A\nNOPE;X\n"), 'replace');

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSee('data-replace-blocked', false)
            ->assertDontSee('data-import-apply', false)
            ->assertDontSee('Применить загрузку?');

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")
            ->assertRedirect("/admin/organization/{$this->org->id}/codes/import")
            ->assertSessionHas('error');

        $this->assertSame([$this->c->id => 'C'], $this->codes());
    }

    public function test_codes_can_be_swapped_between_products_in_one_file(): void
    {
        $this->code($this->a, $this->org, 'X');
        $this->code($this->b, $this->org, 'Y');

        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;Y\nHG-002;X\n"));
        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")->assertSessionHas('codes_report');

        $this->assertSame([$this->a->id => 'Y', $this->b->id => 'X'], $this->codes());
    }

    public function test_error_row_keeps_old_code_so_it_stays_busy(): void
    {
        $this->code($this->a, $this->org, 'X');
        $this->code($this->c, $this->org, 'BUSY');

        // GR-001 → BUSY — ошибка, и у GR-001 остаётся X; значит, HG-002 → X тоже нельзя
        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;BUSY\nHG-002;X\n"));
        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSee('Код уже занят другим товаром: OR-003 — Смеситель Oras.')
            ->assertSee('Код уже занят другим товаром: GR-001 — Смеситель Grohe.')
            ->assertDontSee('data-import-apply', false);

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")->assertSessionHas('error');
        $this->assertSame([$this->a->id => 'X', $this->c->id => 'BUSY'], $this->codes());
    }

    public function test_apply_rechecks_current_database(): void
    {
        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;A-1\nHG-002;B-1\n"));

        // После предпросмотра код B-1 заняли в карточке товара OR-003
        $this->code($this->c, $this->org, 'B-1');

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply");

        $this->assertSame([$this->a->id => 'A-1', $this->c->id => 'B-1'], $this->codes());
        $this->assertCount(1, session('codes_report')['errors']);
    }

    public function test_xlsx_upload_with_shared_strings_and_numbers(): void
    {
        $numeric = $this->product('12345', 'Сифон');

        $xlsx = $this->xlsx(
            ['Наш артикул', 'Код клиента', 'GR-001', '00777'],
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
            .'<row r="4"><c r="A4"><v>12345</v></c><c r="B4"><v>5.0</v></c></row>'
        );

        $this->upload(UploadedFile::fake()->createWithContent('codes.xlsx', $xlsx));
        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSeeInOrder(['Строка', '2', 'GR-001', '00777', '4', '12345', '5']);

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply");
        $this->assertSame([$this->a->id => '00777', $numeric->id => '5'], $this->codes());
    }

    /**
     * Файлы из LibreOffice 7.4 (сконвертированы из source.fods) и в виде экспорта Google Sheets
     * (make-google-sheets.php) — tests/Fixtures/client-codes/README.md. Одни данные: заголовок
     * в 3-й строке, текстовый код с ведущими нулями, числа, формула, пустая строка, пробелы
     * вокруг артикула, второй лист (не читается).
     */
    public static function savedFiles(): array
    {
        return [
            'LibreOffice .xlsx' => ['libreoffice.xlsx'],
            'LibreOffice .csv (UTF-8, «,»)' => ['libreoffice.csv'],
            'LibreOffice .csv (cp1251, «;»)' => ['libreoffice-cp1251.csv'],
            'Google Sheets .xlsx' => ['google-sheets.xlsx'],
            'Google Sheets .csv' => ['google-sheets.csv'],
        ];
    }

    #[DataProvider('savedFiles')]
    public function test_files_saved_in_libreoffice_and_google_sheets(string $file): void
    {
        $numeric = $this->product('12345', 'Сифон');
        $path = base_path('tests/Fixtures/client-codes/'.$file);

        $this->upload(new UploadedFile($path, $file, null, null, true))
            ->assertRedirect("/admin/organization/{$this->org->id}/codes/import");

        // Номера строк — как в файле; числа без «.0»; формула — значение; второй лист не читается
        $this->assertSame([
            ['row' => 4, 'sku' => 'GR-001', 'code' => '00777'],
            ['row' => 5, 'sku' => '12345', 'code' => '5'],
            ['row' => 7, 'sku' => 'HG-002', 'code' => 'HG-B1'],
            ['row' => 8, 'sku' => 'OR-003', 'code' => 'Код-Ё'],
        ], session("client_codes_import.{$this->org->id}.rows"));

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertOk()
            ->assertDontSee('ВТОРОЙ-ЛИСТ');

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply");

        $this->assertSame([
            $this->a->id => '00777',
            $this->b->id => 'HG-B1',
            $this->c->id => 'Код-Ё',
            $numeric->id => '5',
        ], $this->codes());
    }

    public function test_cp1251_csv_is_read(): void
    {
        $csv = mb_convert_encoding("Наш артикул;Код клиента\nGR-001;Код-1\n", 'Windows-1251', 'UTF-8');

        $this->upload($this->csv($csv));
        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply");

        $this->assertSame([$this->a->id => 'Код-1'], $this->codes());
    }

    public function test_bad_files_show_error_under_file_field(): void
    {
        $this->upload($this->csv("Артикул;Код\nGR-001;A\n"))
            ->assertRedirect("/admin/organization/{$this->org->id}?tab=codes")
            ->assertSessionHasErrors(['file' => 'В файле нет столбцов «Наш артикул» и «Код клиента» — скачайте шаблон и заполните его.']);

        $this->upload($this->csv("Наш артикул;Код клиента\n"))->assertSessionHasErrors('file');
        $this->upload($this->csv('x', 'codes.xls'))->assertSessionHasErrors(['file' => 'Нужен файл Excel (.xlsx) или CSV.']);
        $this->upload($this->csv('not a zip', 'codes.xlsx'))->assertSessionHasErrors('file');

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import", ['mode' => 'add'])
            ->assertSessionHasErrors(['file' => 'Выберите файл.']);

        $html = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}?tab=codes")->getContent();
        $this->assertStringContainsString('id="f-file-error"', $html);
        $this->assertStringContainsString('Проверьте выделенные поля', $html);
        $this->assertSame([], $this->codes());
    }

    public function test_cancel_clears_upload(): void
    {
        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;A\n"));

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/cancel")
            ->assertRedirect("/admin/organization/{$this->org->id}?tab=codes");

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply")->assertSessionHas('error');
        $this->assertSame([], $this->codes());
    }

    public function test_ambiguous_sku_is_error(): void
    {
        $this->product('GR-001', 'Дубль артикула');

        $this->upload($this->csv("Наш артикул;Код клиента\nGR-001;A\n"));
        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")
            ->assertSee('Артикул есть у нескольких товаров');
    }

    /**
     * .xlsx со сжатыми (deflate) файлами и общими строками — как сохраняет Excel.
     *
     * @param  list<string>  $strings
     */
    private function xlsx(array $strings, string $rows): string
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = '<sst xmlns="'.$main.'">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s).'</t></si>', $strings)).'</sst>';

        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $ct = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        $files = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="'.$ct.'.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/data.xml" ContentType="'.$ct.'.worksheet+xml"/>'
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="'.$ct.'.sharedStrings+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets><sheet name="Коды" sheetId="1" r:id="rId3"/></sheets></workbook>',
            // Лист — не sheet1.xml: путь берётся из связей книги
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId3" Type="'.$rel.'/worksheet" Target="worksheets/data.xml"/>'
                .'<Relationship Id="rId4" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/sharedStrings.xml' => $shared,
            'xl/worksheets/data.xml' => '<worksheet xmlns="'.$main.'"><sheetData>'.$rows.'</sheetData></worksheet>',
        ];

        $body = '';
        $directory = '';

        foreach ($files as $name => $data) {
            $packed = gzdeflate($data);
            $offset = strlen($body);
            $body .= "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 8, 0, 0, crc32($data), strlen($packed), strlen($data), strlen($name), 0).$name.$packed;
            $directory .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, 0, 0, crc32($data), strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }
}
