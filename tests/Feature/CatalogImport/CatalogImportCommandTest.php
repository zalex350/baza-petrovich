<?php

namespace Tests\Feature\CatalogImport;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use App\Services\CatalogImport\CatalogImportPlanner;
use App\Services\CatalogImport\CatalogMapping;
use App\Services\CatalogImport\ColumnLimits;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PDO;
use Tests\TestCase;

/**
 * catalog:import-db на маленькой фикстуре catalog.db: dry-run ничего не меняет и пишет отчёт,
 * боевой прогон — только с бэкапом; бренды, категории, «Новинки», «Без категории»,
 * характеристики (логистика, ТН ВЭД), фильтры, дубли, тестовые товары; повторный прогон — пустой.
 */
class CatalogImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $catalog;

    private string $data;

    private string $backup;

    /** @var array<string, Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/catalog-import-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/data');
        File::ensureDirectoryExists($this->dir.'/report');

        // Маппинги — настоящие; дубли и тестовые — свои
        foreach (['brands', 'categories', 'attributes', 'filters'] as $file) {
            File::copy(database_path("data/catalog/{$file}.php"), "{$this->dir}/data/{$file}.php");
        }
        File::put("{$this->dir}/data/cleanup.php", "<?php return ['duplicates' => ['LEDEME-D1' => 'D1'], 'test_products' => ['GR-100', 'HC-240']];");
        $this->data = $this->dir.'/data';

        $this->backup = $this->dir.'/backup.sql';
        File::put($this->backup, "-- MySQL dump\n...\n-- Dump completed on 2026-10-25 12:00:00\n");

        $this->seedOurs();
        $this->catalog = $this->makeCatalog();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return $this->cat[($parent ? $parent->name.' / ' : '').$name] = Category::create([
            'name' => $name, 'slug' => 'c-'.count($this->cat), 'parent_id' => $parent?->id,
        ]);
    }

    private function product(string $sku, ?Category $category, array $data = []): Product
    {
        return Product::create($data + [
            'name' => "Товар {$sku}", 'sku' => $sku, 'price' => 100, 'stock' => 5,
            'category_id' => $category?->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    private function seedOurs(): void
    {
        $mixers = $this->category('Смесители');
        $kitchen = $this->category('на кухню', $mixers);
        $kitchen2 = $this->category('кухонные', $mixers);
        $sinks = $this->category('Мойки и умывальники');
        $basin = $this->category('для умывальника', $sinks);
        $misc = $this->category('Разное');
        $news = $this->category('Новинки', $misc);
        // Строчная буква в названии: «диверторы» → «Диверторы» (та же категория)
        $diverters = $this->category('диверторы', $mixers);
        $this->product('LEDEME-V1', $diverters, ['brand' => 'LEDEME']);
        $tools = $this->category('Крепёж и инструмент');
        $press = $this->category('ПРЕСС-ИНСТРУМЕНТ ЭЛЕКТРИЧЕСКИЙ', $tools);
        $this->product('TIM-P1', $press, ['brand' => 'TIM']);
        $electro = $this->category('Электротехнические компоненты');
        $cartridges = $this->category('Картриджи', $electro);

        $this->product('LEDEME-A1', $kitchen, ['brand' => 'Ledeme', 'description' => 'Сгенерированный текст']);
        $this->product('LEDEME-A2', $kitchen2, ['brand' => 'LEDEME']);
        $this->product('LEDEME-A3', $kitchen, ['brand' => 'LEDEME']);
        $this->product('LEDEME-B1', $basin, ['brand' => 'LEDEME']);
        $this->product('LEDEME-N1', $news, ['brand' => 'LEDEME']);
        $this->product('LEDEME-U1', null, ['brand' => 'LEDEME']);
        // Латиница: SQLite в тестах не приводит кириллицу к нижнему регистру (CLAUDE.md)
        $this->product('LEDEME-U2', null, ['brand' => 'LEDEME', 'name' => 'Mystery item']);
        // Без названия в каталоге (у нас name = sku) и не распознан — снимается с публикации
        $this->product('LEDEME-H01', null, ['brand' => 'LEDEME', 'name' => 'LEDEME-H01']);
        $this->product('TIM-C1', $cartridges, ['brand' => 'TIM Thermo']);
        $this->product('LEDEME-D1', $kitchen, ['brand' => 'LEDEME', 'name' => 'LEDEME D1']);
        $this->product('D1', $news, ['name' => 'Смеситель для кухни D1 с выходом для питьевой воды']);

        // Тестовые: GR-100 — в заказе (мягко), HC-240 — нет (совсем); своя категория вне маппинга
        $siphons = $this->category('Сифоны');
        $gr = $this->product('GR-100', $siphons, ['brand' => 'Grohe']);
        $this->product('HC-240', $siphons, ['brand' => 'Hansgrohe']);
        $order = Order::create(['user_id' => User::factory()->create(['organization_id' => Organization::factory()->create()->id])->id]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $gr->id, 'quantity' => 1, 'price' => 100]);
    }

    private function makeCatalog(): string
    {
        $path = $this->dir.'/catalog.db';
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT NOT NULL, article TEXT, brand TEXT, manufacturer TEXT,
            series TEXT, name TEXT, description TEXT, description_generated INTEGER, category TEXT, url TEXT, comment TEXT,
            source TEXT, attributes TEXT, created_at TEXT, updated_at TEXT)');

        $rows = [
            ['LEDEME-A1', 'A1', 'Ledeme', 'H32', 'Смеситель A1', 'Сгенерированный текст', 1, 'на кухню', [
                'Цвет' => 'Хром', 'Производитель' => 'Ledeme', 'Вес,кг' => '2.6', 'Код ТН ВЭД ЕАЭС товара' => '8481',
                'Размер картриджа смесителя' => '35 мм', 'Длина' => '150 см',
                'Ссылка на видео' => '<iframe src="https://vkvideo.ru/video_ext.php?oid=-1&id=2" width="853"></iframe>',
            ]],
            ['LEDEME-A2', 'A2', 'LEDEME', null, 'Смеситель A2', null, 0, 'кухонные', ['Цвет покрытия' => 'хром', 'Комплектация' => str_repeat('Шайба, ', 40)]],
            ['LEDEME-A3', 'A3', 'LEDEME', null, 'Смеситель A3', null, 0, 'кухонные', ['Цвет' => 'хром']],
            ['LEDEME-B1', 'B1', 'LEDEME', null, 'Смеситель B1', null, 0, 'для умывальника', []],
            ['LEDEME-N1', 'N1', 'LEDEME', null, 'Смеситель для кухни N1', null, 0, 'Новинки', []],
            ['LEDEME-U1', 'U1', 'LEDEME', null, 'Крючок двойной', null, 0, null, []],
            ['LEDEME-U2', 'U2', 'LEDEME', null, 'Mystery item', null, 0, null, []],
            ['LEDEME-H01', 'H01', 'LEDEME', 'Серия 035', null, null, 0, null, []],
            ['TIM-C1', 'C1', 'TIM Thermo', null, 'Картридж', null, 0, 'Картриджи', ['Рабочее давление (Ру)' => '10 бар', 'Длина' => '3,35 м']],
            ['TIM-P1', 'P1', 'TIM', null, 'Пресс-инструмент', null, 0, 'ПРЕСС-ИНСТРУМЕНТ ЭЛЕКТРИЧЕСКИЙ', []],
            ['LEDEME-V1', 'V1', 'LEDEME', null, 'Дивертор', null, 0, 'диверторы', []],
            ['LEDEME-D1', 'D1', 'LEDEME', null, 'LEDEME D1', null, 0, 'на кухню', ['Цвет' => 'хром']],
            ['D1', 'D1', null, null, 'Смеситель для кухни D1 с выходом для питьевой воды', null, 0, 'Новинки', ['Материал корпуса' => 'латунь']],
        ];

        $stmt = $pdo->prepare('INSERT INTO products (sku, article, brand, manufacturer, series, name, description, description_generated, category, attributes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as [$sku, $article, $brand, $series, $name, $desc, $gen, $category, $attrs]) {
            $stmt->execute([$sku, $article, $brand, $brand, $series, $name, $desc, $gen, $category, json_encode($attrs, JSON_UNESCAPED_UNICODE)]);
        }

        return $path;
    }

    private function import(array $options = []): PendingCommand
    {
        return $this->artisan('catalog:import-db', [
            'path' => $this->catalog, '--data' => $this->data, '--report-dir' => $this->dir.'/report',
        ] + $options);
    }

    private function snapshot(): array
    {
        return [
            Product::withTrashed()->orderBy('id')->get(['id', 'brand', 'category_id', 'is_new', 'description_generated', 'deleted_at'])->toArray(),
            Category::orderBy('id')->get(['id', 'name', 'parent_id', 'is_hidden'])->toArray(),
            ProductAttribute::count(),
            \DB::table('product_attribute_values')->count(),
            \DB::table('category_filters')->count(),
        ];
    }

    public function test_import_keeps_new_badge_removed_in_admin(): void
    {
        // A-6: «Новинку» сняли в админке — товар в «Новинках» catalog.db бейдж не получает
        $this->find('LEDEME-N1')->update(['is_new' => false, 'is_new_locked' => true]);

        $this->import(['--backup' => $this->backup])->assertSuccessful();

        // Без отметки админа товар из «Новинок» получает бейдж — test_real_run_applies_plan
        $this->assertFalse($this->find('LEDEME-N1')->is_new);
    }

    public function test_import_splits_shower_category_by_rules(): void
    {
        // fix/shower-category: «для душевых» — по split (название, серия, характеристика, описание)
        $root = $this->category('Душевые системы и трапы');
        $mixed = $this->category('Для душевых', $root);
        $stands = $this->category('Душевые стойки', $root);
        $builtInOld = $this->category('Душевая система (встраиваемая)', $root);
        $single = $this->category('Душевая система', $root);
        $bathMixers = $this->category('Для ванны и душа', $this->cat['Смесители']);
        foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $sku) {
            $this->product("LEDEME-{$sku}", $mixed, ['brand' => 'LEDEME']);
        }
        $this->product('LEDEME-B2', $builtInOld, ['brand' => 'LEDEME']);
        $this->product('LOFFREY-F1', $single, ['brand' => 'LOFFREY']);

        $pdo = new PDO('sqlite:'.$this->catalog);
        $stmt = $pdo->prepare('INSERT INTO products (sku, brand, series, name, description, category, attributes) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['LEDEME-S1', 'LEDEME', null, 'LEDEME S1', null, 'для душевых', ['Конструкция' => 'смеситель']],
            ['LEDEME-S2', 'LEDEME', null, 'Душевая система со смесителем', null, 'для душевых', []],
            ['LEDEME-S3', 'LEDEME', 'Душевые системы', 'LEDEME S3', null, 'для душевых', []],
            ['LEDEME-S4', 'LEDEME', null, 'LEDEME S4', 'Смеситель для душа
LEDEME S4', 'для душевых', []],
            ['LEDEME-S5', 'LEDEME', null, 'LEDEME S5', null, 'для душевых', []],  // ни одно правило — на месте
            ['LEDEME-B2', 'LEDEME', null, 'LEDEME B2', null, 'Душевая система (встраиваемая)', []],
            ['LOFFREY-F1', 'LOFFREY', null, 'LOFFREY F1', null, 'Душевая система', []],
        ] as [$sku, $brand, $series, $name, $desc, $category, $attrs]) {
            $stmt->execute([$sku, $brand, $series, $name, $desc, $category, json_encode($attrs, JSON_UNESCAPED_UNICODE)]);
        }

        $this->import(['--backup' => $this->backup])->assertSuccessful();

        $this->assertSame($bathMixers->id, $this->find('LEDEME-S1')->category_id);
        $this->assertSame($stands->id, $this->find('LEDEME-S2')->category_id);
        $this->assertSame($stands->id, $this->find('LEDEME-S3')->category_id);
        $this->assertSame($bathMixers->id, $this->find('LEDEME-S4')->category_id);
        $this->assertSame($mixed->id, $this->find('LEDEME-S5')->category_id);
        $this->assertSame($stands->id, $this->find('LOFFREY-F1')->category_id);
        $this->assertModelMissing($single);

        // «Душевая система (встраиваемая)» переименована, адрес тот же
        $this->assertSame($builtInOld->id, $this->find('LEDEME-B2')->category_id);
        $this->assertSame('Встраиваемые душевые системы', $builtInOld->fresh()->name);
        $this->assertSame($builtInOld->slug, $builtInOld->fresh()->slug);
    }

    private function find(string $sku): Product
    {
        return Product::withTrashed()->where('sku', $sku)->firstOrFail();
    }

    public function test_dry_run_writes_report_and_changes_nothing(): void
    {
        $before = $this->snapshot();

        $this->import(['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());

        $report = File::get($this->dir.'/report/import-report.md');
        $this->assertStringContainsString('База **не менялась**', $report);
        $this->assertStringContainsString('Ledeme → LEDEME', $report);
        $this->assertStringContainsString('TIM Thermo → TIM', $report);
        $this->assertStringContainsString('Смесители / на кухню | Смесители / Для кухни', $report);   // переименование
        $this->assertStringContainsString('Длина (единица не см / мм) | 1', $report);               // метры не загружаются
        $this->assertStringContainsString('**Названия капсом после импорта:** нет.', $report);
        $this->assertStringContainsString('## Проверка по схеме БД', $report);
        $this->assertStringContainsString('| Код ТН ВЭД | text |  | только админка |', $report);
        $this->assertStringContainsString('| Вес | number | кг | упаковка и логистика |', $report);

        $csv = File::get($this->dir.'/report/uncategorized.csv');
        $this->assertStringStartsWith("\u{FEFF}sku;", $csv);
        $this->assertStringContainsString('LEDEME-U1;"Крючок двойной";(пусто);"Аксессуары для ванной / Крючки"', $csv);
        $this->assertStringContainsString('LEDEME-U2;', $csv);
        $this->assertStringContainsString('"Без категории";"не распознано"', $csv);
    }

    public function test_real_run_requires_complete_backup(): void
    {
        $before = $this->snapshot();

        $this->import()->assertFailed();
        File::put($this->backup, "-- обрезанный дамп\n");
        $this->import(['--backup' => $this->backup])->assertFailed();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_real_run_applies_plan(): void
    {
        $kitchenSlug = $this->cat['Смесители / на кухню']->slug;

        $this->import(['--backup' => $this->backup])->assertSuccessful();

        // Бренды
        $this->assertSame('LEDEME', $this->find('LEDEME-A1')->brand);
        $this->assertSame('TIM', $this->find('TIM-C1')->brand);

        // Объединение: «на кухню» переименована (адрес сохранён), «кухонные» опустела и удалена
        $kitchen = Category::where('name', 'Для кухни')->sole();
        $this->assertSame($kitchenSlug, $kitchen->slug);
        foreach (['LEDEME-A1', 'LEDEME-A2', 'LEDEME-A3', 'LEDEME-N1', 'LEDEME-D1'] as $sku) {
            $this->assertSame($kitchen->id, $this->find($sku)->category_id, $sku);
        }
        $this->assertNull(Category::where('name', 'кухонные')->first());

        // Перенос в другой корень
        $basin = Category::where('name', 'Для умывальника')->sole();
        $this->assertSame($this->cat['Смесители']->id, $basin->parent_id);
        $this->assertSame($basin->id, $this->find('LEDEME-B1')->category_id);
        $this->assertNull(Category::where('name', 'для умывальника')->where('parent_id', $this->cat['Мойки и умывальники']->id)->first());

        // «Новинки» — не категория: is_new + категория по названию; «Новинки» удалена
        $this->assertTrue($this->find('LEDEME-N1')->is_new);
        $this->assertNull(Category::where('name', 'Новинки')->first());

        // Без категории: по словам — в «Крючки», нераспознанный — в служебную скрытую
        $this->assertSame('Крючки', $this->find('LEDEME-U1')->category->name);
        $hidden = $this->find('LEDEME-U2')->category;
        $this->assertSame('Без категории', $hidden->name);
        $this->assertTrue($hidden->is_hidden);
        // С названием — остаётся опубликованным; без названия и без категории — снят с публикации
        $this->assertTrue((bool) $this->find('LEDEME-U2')->is_published);
        $this->assertFalse((bool) $this->find('LEDEME-H01')->is_published);
        $this->assertSame('LEDEME H01', $this->find('LEDEME-H01')->title());

        // «Электротехнические компоненты» убраны, картридж — в «Смесители / Запчасти»
        $this->assertNull(Category::where('name', 'Электротехнические компоненты')->first());
        $this->assertSame('Запчасти', $this->find('TIM-C1')->category->name);

        // Характеристики: объединение ключей, нормализация, единицы, логистика, ТН ВЭД, «Серия»
        $values = fn (string $sku) => $this->find($sku)->attributeValues()->with('attribute')->get()
            ->mapWithKeys(fn ($v) => [$v->attribute->name => [$v->value, $v->value_number]])->all();

        $a1 = $values('LEDEME-A1');
        $this->assertSame(['хром', null], $a1['Цвет']);                 // «Хром» → самое частое «хром»
        $this->assertSame(['2,6', 2.6], $a1['Вес']);
        $this->assertSame(['35', 35.0], $a1['Размер картриджа']);
        $this->assertSame(['8481', 8481.0], $a1['Код ТН ВЭД']);
        $this->assertSame(['H32', null], $a1['Серия']);
        $this->assertArrayNotHasKey('Производитель', $a1);
        $this->assertArrayNotHasKey('Ссылка на видео', $a1);
        $this->assertSame(['150', 150.0], $a1['Длина, см']);
        $this->assertArrayNotHasKey('Длина, мм', $values('TIM-C1'));
        $this->assertSame('https://vkvideo.ru/video_ext.php?oid=-1&id=2', $this->find('LEDEME-A1')->video_url);

        // Капс → обычный регистр: та же категория, адрес сохранён
        $press = $this->find('TIM-P1')->category;
        $this->assertSame('Пресс-инструмент электрический', $press->name);
        $this->assertSame($this->cat['Крепёж и инструмент / ПРЕСС-ИНСТРУМЕНТ ЭЛЕКТРИЧЕСКИЙ']->id, $press->id);

        // Строчная буква → заглавная: та же категория, адрес сохранён
        $diverters = $this->find('LEDEME-V1')->category;
        $this->assertSame('Диверторы', $diverters->name);
        $this->assertSame($this->cat['Смесители / диверторы']->slug, $diverters->slug);
        $this->assertSame(['хром', null], $values('LEDEME-A2')['Цвет']);  // «Цвет покрытия» → «Цвет»
        // Длинное значение (279 символов после обрезки пробела) — загружается целиком: колонка value — 1000
        $this->assertSame(279, mb_strlen($values('LEDEME-A2')['Комплектация'][0]));
        $this->assertSame(['Thermo', null], $values('TIM-C1')['Серия']);
        $this->assertSame(['10', 10.0], $values('TIM-C1')['Рабочее давление']);

        $this->assertTrue(ProductAttribute::where('name', 'Вес')->sole()->is_logistics);
        $this->assertTrue(ProductAttribute::where('name', 'Код ТН ВЭД')->sole()->is_admin_only);
        $this->assertSame('мм', ProductAttribute::where('name', 'Размер картриджа')->sole()->unit);

        // Фильтры: «Смесители» — по таблице 5.3 (характеристики, которые есть)
        $filters = app(CatalogFilters::class)->own($this->cat['Смесители'])->map->key()->all();
        $this->assertSame('brand', $filters[0]);
        $this->assertContains('attribute:'.ProductAttribute::where('name', 'Цвет')->value('id'), $filters);
        $this->assertTrue(ProductAttribute::where('name', 'Цвет')->sole()->is_filterable);

        // Описание: совпадает со сгенерированным — признак перенесён
        $this->assertTrue($this->find('LEDEME-A1')->description_generated);

        // Дубль: оставлен LEDEME-D1, название и «Новинка» — из дубля, характеристика добавлена, дубль удалён
        $keep = $this->find('LEDEME-D1');
        $this->assertSame('Смеситель для кухни D1 с выходом для питьевой воды', $keep->name);
        $this->assertTrue($keep->is_new);
        $this->assertArrayHasKey('Материал корпуса', $values('LEDEME-D1'));
        $this->assertNull(Product::withTrashed()->where('sku', 'D1')->first());

        // Тестовые: в заказе — мягко, без заказов — совсем
        $this->assertSoftDeleted('products', ['sku' => 'GR-100']);
        $this->assertNull(Product::withTrashed()->where('sku', 'HC-240')->first());

        // Цены и остатки не тронуты
        $this->assertEquals(100, $this->find('LEDEME-A1')->price);
        $this->assertSame(5, (int) $this->find('LEDEME-A1')->stock);
    }

    public function test_import_keeps_article_and_1c_match_key(): void
    {
        // Ключ до импорта — по старому бренду «TIM Thermo»
        $this->find('TIM-C1')->forceFill(['article' => 'C1'])->save();
        $this->assertSame('TIMTHERMO:C1', $this->find('TIM-C1')->match_key);

        $pdo = new PDO('sqlite:'.$this->catalog);
        $pdo->exec("INSERT INTO products (sku, article, brand, name, category, attributes)
            VALUES ('LEDEME-NEW-1', 'NEW-1', 'LEDEME', 'Смеситель NEW-1', 'на кухню', '[]')");

        $this->import(['--backup' => $this->backup])->assertSuccessful();

        // Бренд сменился мимо модели — ключ пересчитан; новый товар — с артикулом и ключом
        $this->assertSame('TIM:C1', $this->find('TIM-C1')->match_key);
        $new = $this->find('LEDEME-NEW-1');
        $this->assertSame(['NEW-1', 'LEDEME:NEW1'], [$new->article, $new->match_key]);
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->import(['--backup' => $this->backup])->assertSuccessful();
        $after = $this->snapshot();

        $plan = (new CatalogImportPlanner(CatalogMapping::load($this->data)))->plan($this->catalog);

        $this->assertSame([], $plan->products);
        $this->assertSame([], $plan->deleteCategories);
        $this->assertSame([], $plan->duplicates);
        $this->assertSame(0, collect($plan->categories)->where('action', '!=', 'existing')->count());
        $this->assertSame(0, collect($plan->attributes)->where('action', '!=', 'same')->count());

        $this->import(['--backup' => $this->backup])->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_column_limits_are_errors_and_block_real_run(): void
    {
        // Схема в тестах (SQLite) длин не хранит — пределы как в MySQL, но value — 100 символов
        $this->app->bind(CatalogImportPlanner::class, fn ($app, $params) => new CatalogImportPlanner(
            $params['map'],
            new ColumnLimits(['product_attribute_values.value' => ['chars', 100], 'products.video_url' => ['chars', 500]]),
        ));
        $before = $this->snapshot();

        $this->import(['--dry-run' => true])->assertSuccessful();
        $report = File::get($this->dir.'/report/import-report.md');
        $this->assertStringContainsString('## Ошибки — боевой прогон не стартует', $report);
        $this->assertStringContainsString('| `product_attribute_values.value` | 100 символов | 1 | 279 | LEDEME-A2: Комплектация |', $report);

        $this->import(['--backup' => $this->backup])->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_hidden_category_is_not_in_catalog_but_products_are_searchable(): void
    {
        $this->import(['--backup' => $this->backup])->assertSuccessful();

        $hidden = Category::where('name', 'Без категории')->sole();
        CatalogMenu::flush();

        $this->assertNotContains('Без категории', collect(app(CatalogMenu::class)->tree())->pluck('name'));
        $this->get('/catalog/'.$hidden->slug)->assertNotFound();
        $this->get('/catalog')->assertOk()->assertDontSee('Без категории');
        $this->get('/search?q=mystery')->assertOk()->assertSee('Mystery item');

        // Админка: фильтр «Без категории»
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products?category=none')
            ->assertOk()->assertSee('LEDEME-U2')->assertDontSee('LEDEME-A1');
    }
}
