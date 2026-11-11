<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Подготовка данных к обмену с 1С (docs/1C_EXCHANGE_SPEC.md, разделы 4–5), этап 1:
 * поля номенклатуры, ключ сопоставления, название сайта в display_name,
 * catalog:fill-articles, удаление product_stocks.
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

    // --- catalog:fill-articles ---

    private function catalog(array $rows): string
    {
        $path = $this->dir.'/catalog.db';
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT NOT NULL, article TEXT, brand TEXT)');
        $stmt = $pdo->prepare('INSERT INTO products (sku, article, brand) VALUES (?, ?, ?)');

        foreach ($rows as $row) {
            $stmt->execute($row);
        }

        return $path;
    }

    private function fill(string $path, array $options = [])
    {
        return $this->artisan('catalog:fill-articles', ['path' => $path, '--report-dir' => $this->dir] + $options);
    }

    private function report(): string
    {
        return File::get($this->dir.'/articles-report.md');
    }

    public function test_fills_articles_and_keys_idempotently_with_report(): void
    {
        $path = $this->catalog([
            ['LEDEME-L4755-2', 'L4755-2', 'LEDEME'],
            ['tim-bl5800', 'BL 5800', 'TIM'],       // sku — без учёта регистра; нестандартная: пробел в артикуле
            ['L2124', 'L2124', null],               // нестандартная: нет бренда
            ['SAVOL-S1', 'S1', 'SAVOL'],
            ['SAVOL-S-1', 'S-1', 'SAVOL'],          // дефис значим — другой ключ
            ['SAVOL-А1', 'А1', 'SAVOL'],            // кириллица «А» — тот же ключ, что SAVOL-A1
            ['SAVOL-A1', 'A1', 'SAVOL'],
            ['GHOST-1', '1', 'GHOST'],              // нет на сайте
        ]);

        $l4755 = $this->product('LEDEME-L4755-2', ['brand' => 'LEDEME']);
        $tim = $this->product('TIM-BL5800', ['brand' => 'TIM']);
        $noBrand = $this->product('L2124');
        $s1 = $this->product('SAVOL-S1', ['brand' => 'SAVOL']);
        $s1dash = $this->product('SAVOL-S-1', ['brand' => 'SAVOL']);
        $a1cyr = $this->product('SAVOL-А1', ['brand' => 'SAVOL']);
        $a1 = $this->product('SAVOL-A1', ['brand' => 'SAVOL']);
        $own = $this->product('OWN-1', ['brand' => 'OWN']);           // нет в каталоге
        $own->forceFill(['article' => 'O-1'])->save();

        $this->fill($path, ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($l4755->refresh()->article);
        $this->assertStringContainsString('| Изменится / изменено (article, match_key) | 7 |', $this->report());

        $this->fill($path)->assertSuccessful();

        $this->assertSame(['L4755-2', 'LEDEME:L4755-2'], [$l4755->refresh()->article, $l4755->match_key]);
        $this->assertSame(['BL 5800', 'TIM:BL 5800'], [$tim->refresh()->article, $tim->match_key]);
        $this->assertSame(['L2124', null], [$noBrand->refresh()->article, $noBrand->match_key]);
        $this->assertSame(['O-1', 'OWN:O-1'], [$own->refresh()->article, $own->match_key]);
        $this->assertNotSame($s1->refresh()->match_key, $s1dash->refresh()->match_key);
        $this->assertSame('SAVOL:A1', $a1->refresh()->match_key);
        $this->assertSame('SAVOL:A1', $a1cyr->refresh()->match_key);

        $report = $this->report();
        $this->assertStringContainsString('| Нестандартных строк каталога (sku ≠ «БРЕНД-артикул») | 2 |', $report);
        $this->assertStringContainsString('| L2124 | — | L2124 | — | — |', $report);
        $this->assertStringContainsString('| SAVOL:A1 | SAVOL-А1, SAVOL-A1 |', $report);
        $this->assertStringNotContainsString('| SAVOL:S1 |', $report);
        $this->assertStringContainsString("| {$own->id} | OWN-1 | OWN | O-1 |", $report);
        $this->assertStringContainsString('| Строк catalog.db без товара на сайте | 1 |', $report);

        // Одинаковые ключи — CSV для заказчика
        $mixers = Category::create(['name' => 'Смесители', 'slug' => 'm']);
        $a1->update(['display_name' => 'Смеситель A1', 'category_id' => Category::create(['name' => 'Для кухни', 'slug' => 'k', 'parent_id' => $mixers->id])->id]);
        $a1cyr->update(['is_published' => false]);
        // sku отличается от OWN-1 только пробелами и регистром
        $this->product('own - 1');
        $this->fill($path, ['--dry-run' => true])->assertSuccessful();

        $csv = File::get($this->dir.'/duplicate-keys.csv');
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $rows = array_map(fn ($line) => str_getcsv($line, ';'), array_filter(explode("\n", substr($csv, 3))));
        $this->assertSame([
            ['группа', 'ключ', 'sku', 'название', 'бренд', 'категория', 'опубликован'],
            ['бренд + артикул', 'SAVOL:A1', 'SAVOL-A1', 'Смеситель A1', 'SAVOL', 'Смесители / Для кухни', 'да'],
            ['бренд + артикул', 'SAVOL:A1', 'SAVOL-А1', 'SAVOL А1', 'SAVOL', '', 'нет'],
            ['различие в пробелах', 'OWN-1', 'OWN-1', 'OWN 1', 'OWN', '', 'да'],
            ['различие в пробелах', 'OWN-1', 'own - 1', 'own - 1', '', '', 'да'],
        ], array_values($rows));

        // Повтор — ничего не меняет
        $this->fill($path)->assertSuccessful();
        $this->assertStringContainsString('| Изменится / изменено (article, match_key) | 0 |', $this->report());
    }

    public function test_fill_articles_repairs_stale_key_after_bulk_brand_update(): void
    {
        $path = $this->catalog([['LEDEME-A1', 'A1', 'LEDEME']]);
        $product = $this->product('LEDEME-A1', ['brand' => 'LEDEME']);
        $this->fill($path)->assertSuccessful();

        // Массовое обновление мимо модели оставило старый ключ
        DB::table('products')->where('id', $product->id)->update(['brand' => 'Ledeme Pro']);

        $this->fill($path)->assertSuccessful();
        $this->assertSame('LEDEME PRO:A1', $product->refresh()->match_key);
    }

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

    public function test_fill_articles_fails_on_missing_file(): void
    {
        $this->fill($this->dir.'/nope.db')->assertFailed();
    }
}
