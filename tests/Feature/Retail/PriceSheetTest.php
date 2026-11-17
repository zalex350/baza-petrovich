<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * «Цены» в админке: выгрузка в Excel и загрузка изменений.
 */
class PriceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $truba;

    private Product $setka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Металлопрокат', 'slug' => 'metalloprokat']);
        $this->truba = Product::create(['name' => 'Труба 20x20', 'sku' => 'BP-00001', 'price' => 3.36, 'stock' => 1, 'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);
        $this->setka = Product::create(['name' => 'Сетка', 'sku' => 'BP-00002', 'price' => null, 'stock' => 1, 'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(PriceSheet::HEADERS));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'prices.xlsx', null, null, true);
    }

    public function test_export_lists_products(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/prices/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame('Цена с НДС', $rows[0][4]);
        $this->assertSame(['BP-00001', 'Труба 20x20', 'Металлопрокат', 'шт.', 3.36], array_slice($rows[1], 0, 5));
        $this->assertSame('', $rows[2][4]);
    }

    public function test_import_updates_prices_discounts_and_stock(): void
    {
        $this->actingAs($this->admin)->post('/admin/prices/import', ['file' => $this->xlsx([
            ['BP-00001', 'x', 'x', 'м.п.', '3,50', 10, '', '', 0],
            ['BP-00002', 'x', 'x', 'шт', 12, '', 2, '', 1],
            ['BP-404', 'x', 'x', 'шт', 1, '', '', '', 1],
            ['BP-00001', 'x', 'x', 'шт', 'abc', '', '', '', 1],
        ])])->assertRedirect('/admin/prices')->assertSessionHas('price_errors');

        $truba = $this->truba->fresh();
        $this->assertEquals(3.5, $truba->getAttributes()['price']);
        $this->assertEquals(10, $truba->discount_percent);
        $this->assertEquals(3.15, $truba->sale_price);
        $this->assertSame(0, $truba->stock);

        $setka = $this->setka->fresh();
        $this->assertEquals(12, $setka->getAttributes()['price']);
        $this->assertEquals(10, $setka->sale_price);

        $errors = session('price_errors');
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('BP-404', $errors[0]);
        $this->assertStringContainsString('цена', $errors[1]);
    }

    public function test_only_admin(): void
    {
        $this->get('/admin/prices')->assertRedirect();
        $this->actingAs($this->admin)->get('/admin/prices')->assertOk()->assertSee('Скачать файл цен');
    }
}
