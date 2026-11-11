<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Загрузка кодов клиента по артикулу (ClientCodeImport::match): SKU (точно) → бренд + артикул
 * (match_key) → артикул без бренда, если он у одного товара; несколько — ошибка с перечнем
 * «БРЕНД · Арт.». Файл выгрузки ассортимента клиента принимается как есть.
 */
class ClientCodesArticleMatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->org = Organization::factory()->create();
    }

    private function product(string $sku, ?string $brand, ?string $article, string $name = 'Товар'): Product
    {
        return Product::create([
            'name' => $name, 'sku' => $sku, 'brand' => $brand, 'article' => $article, 'price' => 10, 'stock' => 1,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    private function upload(string $csv)
    {
        return $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import", [
            'file' => UploadedFile::fake()->createWithContent('codes.csv', $csv), 'mode' => 'add',
        ]);
    }

    private function preview(): string
    {
        return $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/import")->assertOk()->getContent();
    }

    private function apply(): void
    {
        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import/apply");
    }

    /** @return array<string, string> sku → код */
    private function codes(): array
    {
        return ProductClientCode::where('organization_id', $this->org->id)->with('product')->get()
            ->mapWithKeys(fn ($c) => [$c->product->sku => $c->code])->sortKeys()->all();
    }

    public function test_match_order_sku_then_brand_article_then_unique_article(): void
    {
        $this->product('LEDEME-L4755-2', 'LEDEME', 'L4755-2');
        $this->product('TIM-L4755-2', 'TIM', 'L4755-2');          // тот же артикул у другого бренда
        $this->product('SAVOL-S-1', 'SAVOL', 'S-1');               // артикул единственный
        $this->product('OWN-1', null, 'O-1');                       // без бренда

        $this->upload("Ваш код;Артикул;Бренд\n"
            ."K-SKU;tim-l4755-2;\n"           // 2: SKU сайта — точно, бренд не нужен
            ."K-KEY;l4755-2;Ledeme\n"         // 3: бренд + артикул (регистр, match_key)
            ."K-ART;s-1;\n"                   // 4: артикул у одного товара
            ."K-OWN;O-1;\n"                   // 5: товар без бренда — по артикулу
            ."K-CYR;S-1;SAVOL\n");            // 6: SAVOL-S-1 второй раз — повтор товара

        $html = $this->preview();
        $this->assertStringContainsString('Товар повторяется в файле (строки 4, 6)', $html);
        $this->apply();

        $this->assertSame([
            'LEDEME-L4755-2' => 'K-KEY',
            'OWN-1' => 'K-OWN',
            'TIM-L4755-2' => 'K-SKU',
        ], $this->codes());
    }

    public function test_brand_and_article_with_dashes_spaces_and_cyrillic_lookalikes(): void
    {
        $this->product('LEDEME-L2237-B', 'LEDEME', 'L2237-B');

        // «В» — кириллица, двойной пробел в бренде, «–» вместо дефиса
        $this->upload("Ваш код;Артикул;Бренд\nK-1;L2237\u{2013}\u{0412};  ledeme \n");
        $this->apply();

        $this->assertSame(['LEDEME-L2237-B' => 'K-1'], $this->codes());
    }

    public function test_ambiguous_without_brand_and_with_brand_both_listed(): void
    {
        $this->product('LEDEME-A-100', 'LEDEME', 'A-100');
        $this->product('TIM-A-100', 'TIM', 'A-100');
        // Один бренд, два товара с одним артикулом (дубль в каталоге)
        $this->product('SAVOL-X-1', 'SAVOL', 'X-1', 'Сифон');
        $this->product('SAVOL-X-1-OLD', 'SAVOL', 'X-1', 'Сифон старый');

        $this->upload("Ваш код;Артикул;Бренд\nK-1;A-100;\nK-2;X-1;Savol\n");
        $html = $this->preview();

        $this->assertStringContainsString('Артикул «A-100» есть у нескольких товаров: LEDEME · Арт. A-100, TIM · Арт. A-100 — укажите бренд в столбце «Бренд» или задайте код в карточке нужного товара.', $html);
        $this->assertStringContainsString('Артикул «X-1» есть у нескольких товаров: SAVOL · Арт. X-1 («Сифон»), SAVOL · Арт. X-1 («Сифон старый») или задайте код в карточке нужного товара.', $html);
        $this->assertMatchesRegularExpression('#data-count-add>0<#', $html);
    }

    public function test_brand_mismatch_and_not_found(): void
    {
        $this->product('LEDEME-A-1', 'LEDEME', 'A-1');

        $this->upload("Ваш код;Артикул;Бренд\nK-1;A-1;TIM\nK-2;NOPE;LEDEME\nK-3;NOPE;\n");
        $html = $this->preview();

        // Артикул единственный, но бренд другой — код чужому товару не уходит
        $this->assertStringContainsString('Артикул «A-1» есть только у товара LEDEME · Арт. A-1, а в файле бренд «TIM».', $html);
        $this->assertStringContainsString('Товар с брендом «LEDEME» и артикулом «NOPE» не найден в каталоге.', $html);
        $this->assertStringContainsString('Артикул не найден в каталоге.', $html);
        $this->assertSame([], $this->codes());
    }

    public function test_assortment_export_is_accepted_as_is(): void
    {
        $client = User::factory()->create(['organization_id' => $this->org->id]);
        $grohe = $this->product('GR-001', 'Grohe', 'G-32559', 'Смеситель Grohe');
        $tim = $this->product('TIM-A-100', 'TIM', 'A-100', 'Смеситель Tim');
        $this->product('LEDEME-A-100', 'LEDEME', 'A-100', 'Смеситель Ledeme');
        ProductClientCode::create(['product_id' => $grohe->id, 'organization_id' => $this->org->id, 'code' => 'OLD-G']);

        // Клиент скачивает выгрузку со всеми товарами и вписывает свои коды
        $response = $this->actingAs($client)->get('/account/assortment/download?without_code=1')->assertOk();
        $source = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($source);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        $this->assertSame(['Ваш код', 'Артикул', 'Бренд'], array_slice($rows[0], 0, 3));
        foreach ($rows as $i => $row) {
            $rows[$i][0] = match ($row[1].'|'.$row[2]) {
                'G-32559|Grohe' => 'NEW-G',
                'A-100|TIM' => 'NEW-T',
                default => $row[0],
            };
        }

        $path = tempnam(sys_get_temp_dir(), 'assortment').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        $this->actingAs($this->admin)->post("/admin/organization/{$this->org->id}/codes/import", [
            'file' => new UploadedFile($path, 'assortment.xlsx', null, null, true), 'mode' => 'add',
        ])->assertSessionHasNoErrors();
        $this->apply();

        // Grohe — обновлён, TIM A-100 — по бренду (у LEDEME тот же артикул), остальные — без кода
        $this->assertSame(['GR-001' => 'NEW-G', 'TIM-A-100' => 'NEW-T'], $this->codes());
        $this->assertNotNull($tim->fresh());
    }

    public function test_template_has_export_columns(): void
    {
        $path = $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}/codes/template")
            ->assertOk()->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $this->assertSame(['Ваш код', 'Артикул', 'Бренд'], $row->toArray());
                break;
            }
            break;
        }
        $reader->close();
    }
}
