<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Кабинет → «Выгрузка ассортимента» (docs/MODULES.md §11): только у организации с кодами;
 * .xlsx — ваш код, наш артикул, название, цены без НДС и с НДС, остаток (точное число до
 * shop.export_stock_cap, больше — «более N»); опубликованные товары с кодом своей
 * организации, с опцией — и без кода.
 */
class AssortmentExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $client;

    private User $otherClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.export_stock_cap' => 20]);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->client = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherClient = User::factory()->create(['organization_id' => $this->other->id]);

        // Артикул производителя — вместо SKU сайта (GR-001 в файл не попадает)
        $grohe = $this->product('GR-001', 'Смеситель Grohe', ['price' => 100, 'vat_rate' => 20, 'stock' => 7, 'article' => 'G-32559', 'brand' => 'Grohe']);
        $hans = $this->product('HG-002', 'Смеситель Hansgrohe', ['price' => 50.5, 'vat_rate' => null, 'stock' => 250]);
        $tbd = $this->product('AA-000', 'Сифон без цены', ['price' => null, 'stock' => 0]);
        $hidden = $this->product('ZZ-009', 'Снят с публикации', ['is_published' => false]);
        $inactive = $this->product('ZZ-010', 'Неактивный', ['is_active' => false]);
        $this->product('OR-003', 'Смеситель Oras без кода', ['price' => 10, 'vat_rate' => 20, 'stock' => 21]);

        $this->code($grohe, $this->org, '00777');
        $this->code($hans, $this->org, 'Код-Б');
        $this->code($tbd, $this->org, 'T-1');
        $this->code($hidden, $this->org, 'HIDDEN');
        $this->code($inactive, $this->org, 'INACTIVE');
        $this->code($grohe, $this->other, 'CHUZHOY-1');
    }

    private function product(string $sku, string $name, array $extra = []): Product
    {
        return Product::create($extra + ['name' => $name, 'sku' => $sku, 'price' => 1, 'stock' => 1, 'is_active' => true, 'is_published' => true]);
    }

    private function code(Product $product, Organization $org, string $code): void
    {
        ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $org->id, 'code' => $code]);
    }

    /** @return list<list<mixed>> строки первого листа */
    private function download(User $user, array $query = []): array
    {
        $response = $this->actingAs($user)->get('/account/assortment/download?'.http_build_query($query))->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/assortment-\d{4}-\d{2}-\d{2}\.xlsx/', $response->headers->get('Content-Disposition'));

        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_menu_and_page_only_for_organization_with_codes(): void
    {
        $this->actingAs($this->client)->get('/account/organization')->assertSee('href="/account/assortment"', false);
        $this->actingAs($this->client)->get('/account/assortment')
            ->assertOk()
            ->assertSee('Выгрузка ассортимента')
            ->assertSee('Включить товары без кода');

        $noCodes = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($noCodes)->get('/account/organization')->assertDontSee('href="/account/assortment"', false);
        $this->actingAs($noCodes)->get('/account/assortment')->assertNotFound();
        $this->actingAs($noCodes)->get('/account/assortment/download')->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())->get('/account/assortment/download')->assertForbidden();

        auth()->logout();
        $this->get('/account/assortment/download')->assertRedirect('/login');
    }

    public function test_export_contains_only_published_products_with_own_codes(): void
    {
        $this->assertSame([
            ['Ваш код', 'Артикул', 'Бренд', 'Название', 'Цена без НДС', 'Цена с НДС', 'Остаток'],
            // Порядок — по артикулу (без артикула — SKU); цены нет — «Цена уточняется»
            ['T-1', 'AA-000', '', 'Сифон без цены', 'Цена уточняется', 'Цена уточняется', 0],
            // Код с ведущими нулями — текстом; артикул производителя, не SKU; НДС 20 %; остаток — точным числом
            ['00777', 'G-32559', 'Grohe', 'Смеситель Grohe', 100, 120, 7],
            // Без НДС — цена с НДС та же; остаток больше порога — «более 20»
            ['Код-Б', 'HG-002', '', 'Смеситель Hansgrohe', 50.5, 50.5, 'более 20'],
        ], $this->download($this->client));
    }

    public function test_option_adds_products_without_code_after_coded_ones(): void
    {
        $rows = $this->download($this->client, ['without_code' => 1]);

        $this->assertSame(['T-1', '00777', 'Код-Б', ''], array_column(array_slice($rows, 1), 0));
        $this->assertSame(['', 'OR-003', '', 'Смеситель Oras без кода', 10, 12, 'более 20'], $rows[4]);
        $this->assertNotContains('GR-001', array_merge(...$rows));

        // Снятые с публикации и неактивные — нет и с опцией
        $skus = array_column($rows, 1);
        $this->assertNotContains('ZZ-009', $skus);
        $this->assertNotContains('ZZ-010', $skus);
    }

    public function test_other_organization_codes_are_not_visible(): void
    {
        // У второй организации — только её код; коды первой не попадают ни в каком режиме
        $this->assertSame([['CHUZHOY-1', 'G-32559']], array_map(fn ($r) => array_slice($r, 0, 2), array_slice($this->download($this->otherClient), 1)));

        $all = $this->download($this->otherClient, ['without_code' => 1]);
        $codes = array_filter(array_column($all, 0));
        $this->assertSame(['Ваш код', 'CHUZHOY-1'], array_values($codes));

        foreach ($this->download($this->client, ['without_code' => 1]) as $row) {
            $this->assertNotContains('CHUZHOY-1', $row);
        }
    }

    public function test_stock_cap_is_configurable(): void
    {
        config(['shop.export_stock_cap' => 5]);

        $rows = collect($this->download($this->client))->keyBy(1);
        $this->assertSame('более 5', $rows['G-32559'][6]);
        $this->assertSame(0, $rows['AA-000'][6]);
    }
}
