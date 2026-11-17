<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Подготовка данных к обмену с 1С (docs/1C_EXCHANGE_SPEC.md, разделы 4–5), этап 1:
 * поля номенклатуры, ключ сопоставления, название сайта в display_name,
 * удаление product_stocks.
 */
class Product1cDataTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/fill-articles-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 1, 'stock' => 0]);
    }

    // --- Поля и ключ ---

    public function test_match_key_normalizes_case_spaces_dashes_and_cyrillic_lookalikes(): void
    {
        $this->assertSame('LEDEME:L4755-2', Product::matchKey('Ledeme', 'L4755-2'));
        // Дефисы значимы: L4755-2 и L47552 — разные ключи
        $this->assertSame('LEDEME:L47552', Product::matchKey('Ledeme', 'L47552'));
        // Пробелы схлопываются и обрезаются, «–» / «—» → «-»
        $this->assertSame('LEDEME:L 4755-2', Product::matchKey(' LEDEME ', "l  4755\u{2013}2 "));
        $this->assertSame('LEDEME:L4755-2', Product::matchKey('LEDEME', "L4755\u{2014}2"));
        $this->assertSame('TIM THERMO:BL 5800', Product::matchKey('TIM  Thermo', "BL\u{00A0}5800"));
        // Кириллические двойники латиницы: «LЕDЕМЕ» и «А-1» набраны кириллицей
        $this->assertSame('LEDEME:A-1', Product::matchKey("L\u{0415}D\u{0415}\u{041C}\u{0415}", "\u{0430}-1"));
        $this->assertSame(Product::matchKey('TIM', 'XKCH-01'), Product::matchKey('TIM', 'ХКСН-01')); // все буквы — кириллица
        // Кириллица без двойника остаётся (без учёта регистра)
        $this->assertSame('CABOЛ:Ш-1', Product::matchKey('Савол', 'ш-1'));

        // Без бренда или артикула ключа нет: по одному артикулу бренды не смешиваются
        $this->assertNull(Product::matchKey(null, 'L4755-2'));
        $this->assertNull(Product::matchKey('LEDEME', ' - '));
    }

    public function test_new_fields_defaults_and_mass_assignment(): void
    {
        $product = $this->product('LEDEME-L1', [
            'brand' => 'LEDEME', 'article' => 'L1', 'unit' => 'м', 'barcode' => '481', 'group_1c' => 'Смесители',
        ]);
        $product->refresh();

        // Поля номенклатуры 1С заполняет только обмен — из формы не принимаются; артикул — принимается
        // (правится в админке у товара без GUID)
        $this->assertSame('шт', $product->unit);
        $this->assertSame('L1', $product->article);
        $this->assertNull($product->barcode);
        $this->assertNull($product->group_1c);
        $this->assertSame('LEDEME:L1', $product->match_key);
        $this->assertNull($product->synced_at);

        $product->forceFill(['article' => 'L1', 'price_synced_at' => '2026-11-10 18:30:00'])->save();
        $product->refresh();

        $this->assertSame('LEDEME:L1', $product->match_key);
        $this->assertSame('2026-11-10 18:30:00', $product->price_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_match_key_follows_brand_and_article_changes(): void
    {
        $product = $this->product('X-1', ['brand' => 'Old']);
        $product->forceFill(['article' => 'A-1'])->save();
        $this->assertSame('OLD:A-1', $product->refresh()->match_key);

        $product->update(['brand' => 'New Brand']);
        $this->assertSame('NEW BRAND:A-1', $product->refresh()->match_key);

        $product->update(['brand' => null]);
        $this->assertNull($product->refresh()->match_key);
    }

    public function test_product_stocks_table_is_dropped(): void
    {
        $this->assertFalse(Schema::hasTable('product_stocks'));
        $this->assertTrue(Schema::hasColumns('products', ['article', 'match_key', 'unit', 'barcode', 'group_1c', 'synced_at', 'price_synced_at', 'stock_synced_at']));
    }

    // --- name → display_name ---

    private function copyNames(): void
    {
        (require database_path('migrations/2026_11_11_100001_copy_product_names_to_display_name.php'))->up();
    }

    public function test_site_name_is_copied_to_display_name_and_title_is_unchanged(): void
    {
        $real = $this->product('LEDEME-A1', ['name' => 'Смеситель для кухни A1', 'brand' => 'LEDEME']);
        $skuAsName = $this->product('LEDEME-H01', ['brand' => 'LEDEME']);           // name = sku
        $skuOtherCase = $this->product('Ledeme-h02', ['name' => 'LEDEME-H02', 'brand' => 'LEDEME']);
        $own = $this->product('LEDEME-B1', ['name' => 'Смеситель B1', 'display_name' => 'Своё название']);
        $blank = $this->product('LEDEME-C1', ['name' => 'Смеситель C1', 'display_name' => '']);
        $trashed = $this->product('LEDEME-D1', ['name' => 'Смеситель D1']);
        $trashed->delete();

        $titles = Product::withTrashed()->orderBy('id')->get()->map->title()->all();

        $this->copyNames();
        $this->copyNames(); // повтор ничего не меняет

        $this->assertSame('Смеситель для кухни A1', $real->refresh()->display_name);
        $this->assertSame('Смеситель C1', $blank->refresh()->display_name);
        $this->assertSame('Смеситель D1', $trashed->refresh()->display_name);
        $this->assertSame('Своё название', $own->refresh()->display_name);
        // name = sku: display_name пуст — title() по-прежнему собирает «БРЕНД артикул»
        $this->assertNull($skuAsName->refresh()->display_name);
        $this->assertNull($skuOtherCase->refresh()->display_name);
        $this->assertSame('LEDEME H01', $skuAsName->title());

        $this->assertSame($titles, Product::withTrashed()->orderBy('id')->get()->map->title()->all());

        // Наименование 1С заменяет name — покупатель видит название сайта
        $real->update(['name' => 'Смес. д/кух. LEDEME A1 хром']);
        $this->assertSame('Смеситель для кухни A1', $real->refresh()->title());
    }

    // --- catalog:match-keys ---

    public function test_match_keys_command_recalculates_stale_keys_idempotently(): void
    {
        $product = $this->product('LEDEME-L4755-2', ['brand' => 'LEDEME', 'article' => 'L4755-2']);
        $trashed = $this->product('TIM-X', ['brand' => 'TIM', 'article' => 'Х-1']); // кириллическая «Х»
        $trashed->delete();
        // Ключи по старым правилам (без дефисов) — мимо модели
        DB::table('products')->update(['match_key' => 'OLD']);

        $this->artisan('catalog:match-keys', ['--dry-run' => true])->expectsOutputToContain('изменится ключей: 2')->assertSuccessful();
        $this->assertSame('OLD', $product->fresh()->match_key);

        $this->artisan('catalog:match-keys')->expectsOutputToContain('обновлено 2')->assertSuccessful();
        $this->assertSame('LEDEME:L4755-2', $product->fresh()->match_key);
        $this->assertSame('TIM:X-1', Product::withTrashed()->find($trashed->id)->match_key);

        $this->artisan('catalog:match-keys')->expectsOutputToContain('изменится ключей: 0')->assertSuccessful();
    }
}
