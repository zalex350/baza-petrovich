<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\CatalogMenu;
use App\Services\CategoryIcons;
use App\Services\CategoryTiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Иконки категорий (CategoryIcons, x-category-icon, x-category-icon-picker) — набор сантехники plumb
 * (двухцветный, CSS-переменные), очистка загруженной SVG, админка (выбор, загрузка, превью только админу),
 * витрина (мегаменю, чипы), стартовые соответствия и их замена; плитки разделов (CategoryTiles,
 * x-category-tile, x-tile-product-picker): своя картинка → выбранный товар → популярный с фото и в наличии → иконка.
 */
class CategoryIconsTest extends TestCase
{
    use RefreshDatabase;

    private CategoryIcons $icons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->icons = app(CategoryIcons::class);
    }

    private function category(string $name, array $attributes = []): Category
    {
        static $n = 0;
        $n++;

        return Category::create($attributes + ['name' => $name, 'slug' => "c-{$n}"]);
    }

    private function product(Category $category, array $attributes = [], ?string $photo = null): Product
    {
        static $n = 0;
        $n++;

        $product = Product::create($attributes + ['name' => "Товар {$n}", 'sku' => "S-{$n}", 'price' => 1, 'stock' => 1,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);

        if ($photo) {
            ProductImage::create(['product_id' => $product->id, 'path' => $photo, 'is_main' => true]);
        }

        return $product;
    }

    /** Категория с товаром — иначе её нет в меню и на главной */
    private function filled(string $name, array $attributes = []): Category
    {
        $category = $this->category($name, $attributes);
        $this->product($category);

        return $category;
    }

    private function sold(Product $product, int $quantity, OrderStatus $status = OrderStatus::Completed): void
    {
        OrderItem::create([
            'order_id' => Order::create(['user_id' => User::factory()->create()->id, 'status' => $status])->id,
            'product_id' => $product->id, 'quantity' => $quantity, 'price' => 1,
        ]);
    }

    // --- Набор ---

    public function test_library_is_the_plumbing_set_only(): void
    {
        $words = json_decode(file_get_contents(resource_path('icons/plumb.json')), true);
        $library = $this->icons->library();

        $this->assertSame(array_map(fn ($n) => "plumb:{$n}", array_keys($words)), array_keys($library));
        $this->assertGreaterThanOrEqual(90, count($library));

        // Товары из задания — есть
        foreach (['faucet', 'cartridge', 'aerator', 'diverter', 'shower_head', 'shower_column', 'drain', 'siphon', 'corrugated',
            'flex_hose', 'ball_valve', 'gate_valve', 'radiator', 'towel_rail', 'manifold', 'expansion_tank', 'pressure_gauge',
            'water_meter', 'filter_housing', 'pump', 'pipe', 'tee', 'press_fitting', 'toilet', 'installation', 'washbasin',
            'kitchen_sink', 'bathtub', 'shower_holder', 'hook', 'soap_dish', 'dispenser', 'toilet_brush', 'mirror',
            'thermostat', 'thermo_head', 'gas_cylinder'] as $name) {
            $this->assertArrayHasKey("plumb:{$name}", $library, $name);
        }

        // Общих иконок и прежних наборов нет
        foreach (['wrench', 'bolt', 'category'] as $generic) {
            $this->assertArrayNotHasKey("plumb:{$generic}", $library);
        }
        $this->assertDirectoryDoesNotExist(resource_path('icons/material'));
        $this->assertDirectoryDoesNotExist(resource_path('icons/custom'));
        $this->assertNull($this->icons->render('material:faucet'));
        $this->assertNull($this->icons->render('custom:siphon'));
    }

    public function test_icons_follow_the_two_tone_style(): void
    {
        foreach (glob(resource_path('icons/plumb/*.svg')) as $file) {
            $svg = file_get_contents($file);
            $name = basename($file);

            $this->assertStringContainsString('viewBox="0 0 48 48"', $svg, $name);
            $this->assertStringContainsString('stroke-width="2"', $svg, $name);
            $this->assertStringContainsString('stroke-linecap="round"', $svg, $name);
            $this->assertStringContainsString('stroke-linejoin="round"', $svg, $name);
            $this->assertStringContainsString('stroke:var(--ic-stroke,#1E2B45)', $svg, $name);
            $this->assertStringContainsString('var(--ic-fill,#DCE8FB)', $svg, $name);

            // Цвета — только через переменные (значение по умолчанию — внутри var())
            $colors = preg_replace('/var\(--ic-(stroke|fill|water|accent),#[0-9A-F]{6}\)/', '', $svg);
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,6}\b|rgb\(/i', $colors, $name);
            $this->assertNotNull(simplexml_load_string($svg), $name);
        }
    }

    public function test_theme_variables_are_defined_in_css(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['--ic-stroke: #1E2B45', '--ic-fill: #DCE8FB', '--ic-water: #1D5FD1', '--ic-accent: #F59E0B'] as $var) {
            $this->assertStringContainsString($var, $css);
        }
    }

    public function test_render_keeps_colors_and_sizes_by_class(): void
    {
        $svg = (string) $this->icons->render('plumb:siphon', null, 'h-12 w-12');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('class="h-12 w-12"', $svg);
        $this->assertStringContainsString('var(--ic-water,#1D5FD1)', $svg);
        $this->assertStringContainsString('aria-hidden="true"', $svg);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*\s(width|height)=/', $svg);

        $this->assertNull($this->icons->render(null));
        $this->assertNull($this->icons->render('plumb:no_such_icon'));
        $this->assertNull($this->icons->render('upload', null));

        // Загруженная — одноцветная, цвет контура темы
        $upload = (string) $this->icons->render('upload', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1"/></svg>');
        $this->assertStringContainsString('style="color:var(--ic-stroke,#1E2B45)"', $upload);
        $this->assertStringContainsString('fill="currentColor"', $upload);
    }

    // --- Загруженная SVG ---

    public function test_sanitize_keeps_shapes_and_removes_everything_dangerous(): void
    {
        $raw = <<<'SVG'
<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="32" height="32" onload="alert(1)">
  <script>alert(1)</script>
  <style>path{fill:red}</style>
  <title>Иконка</title>
  <g fill="#ff0000" onclick="x()">
    <path d="M2 2h20v20H2z" stroke="rgb(0,0,0)" style="fill:blue"/>
    <a xlink:href="javascript:alert(1)"><circle cx="12" cy="12" r="4"/></a>
  </g>
  <foreignObject><div>html</div></foreignObject>
  <image href="https://evil.test/x.png"/>
  <rect x="1" y="1" width="4" height="4" fill="url(#g)"/>
</svg>
SVG;

        $clean = $this->icons->sanitize($raw);

        $this->assertNotNull($clean);
        $this->assertStringContainsString('viewBox="0 0 32 32"', $clean);
        $this->assertStringContainsString('<path d="M2 2h20v20H2z" stroke="currentColor"/>', $clean);
        $this->assertStringContainsString('<g fill="currentColor">', $clean);

        foreach (['script', 'style', 'onload', 'onclick', 'javascript', 'foreignObject', '<image', 'href', '<a', 'red', '#ff0000', 'rgb(', 'title'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean, $bad);
        }
    }

    public function test_sanitize_rejects_non_svg_and_entities(): void
    {
        $this->assertNull($this->icons->sanitize('not svg'));
        $this->assertNull($this->icons->sanitize('<html><body/></html>'));
        $this->assertNull($this->icons->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>'), 'без viewBox и размеров');
        $this->assertNull($this->icons->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script/></svg>'), 'без фигур');
        $this->assertNull($this->icons->sanitize('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg viewBox="0 0 1 1"><path d="&x;"/></svg>'));
        $this->assertNull($this->icons->sanitize(str_repeat(' ', CategoryIcons::MAX_UPLOAD + 1)));
    }

    // --- Админка: иконка ---

    public function test_admin_chooses_icon_from_set_and_clears_it(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category('Сифоны', ['parent_id' => $this->category('Арматура')->id]);

        $this->actingAs($admin)->get("/admin/category/{$category->id}/edit")->assertOk()
            ->assertSee('data-icon-picker', false)
            ->assertSee('data-icon-option="plumb:siphon"', false)
            ->assertDontSee('data-icon-option="material:', false)
            // Подкатегория — без выбора фото плитки
            ->assertDontSee('data-tile-picker', false);

        $form = ['name' => 'Сифоны', 'slug' => $category->slug, 'parent_id' => $category->parent_id];

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'plumb:siphon'])
            ->assertRedirect('/admin/categories');
        $this->assertSame('plumb:siphon', $category->fresh()->icon);

        $this->actingAs($admin)->from("/admin/category/{$category->id}/edit")
            ->post("/admin/category/{$category->id}", $form + ['icon' => 'material:faucet'])
            ->assertSessionHasErrors('icon');
        $this->assertSame('plumb:siphon', $category->fresh()->icon);

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => '']);
        $this->assertNull($category->fresh()->icon);
    }

    public function test_admin_uploads_svg_which_is_sanitized_and_kept(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category('Своя');
        $form = ['name' => 'Своя', 'slug' => $category->slug];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><path d="M4 4h16v16H4z" fill="#123456"/></svg>';
        $file = UploadedFile::fake()->createWithContent('icon.svg', $svg);

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'plumb:faucet', 'icon_file' => $file])
            ->assertRedirect('/admin/categories');

        $category->refresh();
        $this->assertSame('upload', $category->icon);
        $this->assertStringContainsString('fill="currentColor"', $category->icon_svg);
        $this->assertStringNotContainsString('onload', $category->icon_svg);

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'upload']);
        $this->assertSame('upload', $category->fresh()->icon);

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'plumb:pipe']);
        $this->assertSame(['plumb:pipe', null], [$category->fresh()->icon, $category->fresh()->icon_svg]);

        $this->actingAs($admin)->from("/admin/category/{$category->id}/edit")
            ->post("/admin/category/{$category->id}", $form + ['icon_file' => UploadedFile::fake()->createWithContent('x.svg', 'hello')])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame('plumb:pipe', $category->fresh()->icon);
    }

    public function test_preview_page_only_for_admin_with_sizes_set_and_tiles(): void
    {
        $root = $this->category('Смесители', ['icon' => 'plumb:faucet']);
        $this->category('Для кухни', ['parent_id' => $root->id, 'icon' => 'plumb:kitchen_faucet']);
        $this->category('Без иконки', ['parent_id' => $root->id]);
        $this->category('Старая', ['parent_id' => $root->id, 'icon' => 'material:faucet']);
        $this->product($root, [], 'tile.jpg');

        $this->get('/admin/category-icons')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/category-icons')->assertForbidden();

        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/category-icons')->assertOk()
            ->assertSee('Иконки категорий')
            ->assertSee('plumb:kitchen_faucet')
            ->assertSee('нет иконки')
            ->assertSee('нет в наборе: material:faucet')
            ->assertSee('Без иконки: 2.')
            ->assertSee('24 · 48 · 72')
            ->getContent();

        $this->assertStringContainsString('data-icon-library="plumb"', $html);
        // 72 px: весь набор + две категории с иконкой из набора (Смесители, Для кухни)
        $this->assertSame(count($this->icons->library()) + 2, substr_count($html, 'class="shrink-0 min-h-6 min-w-6 h-[72px] w-[72px]"'));
        $this->assertStringContainsString('data-tile-preview', $html);
        $this->assertStringContainsString('tile.jpg', $html);
    }

    // --- Витрина: иконки ---

    public function test_icons_in_mega_menu_and_chips_and_layout_without_icon(): void
    {
        $root = $this->filled('Смесители', ['icon' => 'plumb:faucet']);
        $this->filled('Для кухни', ['parent_id' => $root->id, 'icon' => 'plumb:kitchen_faucet']);
        $this->filled('Без иконки', ['parent_id' => $root->id]);
        $this->filled('Раздел без иконки');
        CatalogMenu::flush();

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-mega-root="'.$root->id.'"[^>]*>\s*<svg[^>]*data-category-icon/s', $home);
        $this->assertStringContainsString('Раздел без иконки', $home);

        $this->get('/catalog/'.$root->slug)->assertOk()
            ->assertSee('Для кухни')->assertSee('Без иконки')
            ->assertSee('data-category-icon', false);
    }

    // --- Плитки разделов ---

    public function test_tile_photo_priority_image_then_chosen_then_popular_then_icon(): void
    {
        $root = $this->category('Смесители', ['icon' => 'plumb:faucet']);
        $child = $this->category('Для кухни', ['parent_id' => $root->id]);
        $tiles = app(CategoryTiles::class);
        $ids = [$root->id, $child->id];

        // Нет товаров с фото и в наличии — иконка (null)
        $this->product($child, ['stock' => 0], 'no-stock.jpg');
        $this->product($child, [], null);
        $this->product($child, ['is_published' => false], 'draft.jpg');
        $this->assertNull($tiles->url($root, $ids));

        // Самый популярный с фото и в наличии (отменённые заказы не считаются)
        $calm = $this->product($child, ['stock' => 50], 'calm.jpg');
        $hit = $this->product($child, ['stock' => 2], 'hit.jpg');
        $cancelled = $this->product($child, ['stock' => 2], 'cancelled.jpg');
        $this->sold($hit, 5);
        $this->sold($cancelled, 100, OrderStatus::Cancelled);
        $this->assertStringEndsWith('hit.jpg', $tiles->url($root, $ids));

        // Без заказов — больше остаток
        OrderItem::query()->delete();
        $this->assertStringEndsWith('calm.jpg', $tiles->url($root, $ids));

        // Выбранный в админке важнее популярного
        $root->update(['tile_product_id' => $cancelled->id]);
        $this->assertStringEndsWith('cancelled.jpg', $tiles->url($root->fresh(), $ids));

        // Своя картинка важнее всего
        $root->update(['image' => 'categories/own.webp']);
        $this->assertStringEndsWith('own.webp', $tiles->url($root->fresh(), $ids));
    }

    public function test_tiles_for_tree_match_single_and_use_few_queries(): void
    {
        $tiles = app(CategoryTiles::class);
        $roots = [];

        foreach (range(1, 6) as $i) {
            $root = $this->category("Раздел {$i}");
            $child = $this->category("Подраздел {$i}", ['parent_id' => $root->id]);
            $this->product($child, ['stock' => $i], "r{$i}-a.jpg");
            $this->product($child, ['stock' => $i + 10], "r{$i}-b.jpg");
            $roots[] = $root;
        }
        $roots[0]->update(['image' => 'categories/own.webp']);
        $roots[1]->update(['tile_product_id' => Product::where('category_id', $roots[1]->id + 1)->orderBy('id')->first()->id]);
        $this->category('Пустой');
        CatalogMenu::flush();

        $tree = app(CatalogMenu::class)->tree();

        \DB::enableQueryLog();
        $all = $tiles->forTree($tree);
        $this->assertLessThanOrEqual(5, count(\DB::getQueryLog()), 'запросов не больше 5 на все разделы');
        \DB::disableQueryLog();

        foreach ($roots as $root) {
            $this->assertSame($tiles->url($root->fresh(), app(CatalogMenu::class)->descendantIds($root)), $all[$root->id], $root->name);
        }
        $this->assertStringEndsWith('own.webp', $all[$roots[0]->id]);
        $this->assertStringEndsWith('r2-a.jpg', $all[$roots[1]->id]);
        $this->assertStringEndsWith('r3-b.jpg', $all[$roots[2]->id]);

        // Кэш сбрасывается вместе с меню
        $roots[2]->update(['image' => 'categories/new.webp']);
        $this->assertStringEndsWith('new.webp', $tiles->forTree(app(CatalogMenu::class)->tree())[$roots[2]->id]);
    }

    public function test_tiles_on_home_and_catalog_show_photo_or_icon(): void
    {
        $withPhoto = $this->category('Смесители', ['icon' => 'plumb:faucet']);
        $this->product($withPhoto, [], 'mixer.jpg');
        $iconOnly = $this->category('Сифоны', ['icon' => 'plumb:siphon']);
        $this->product($iconOnly);
        $bare = $this->category('Разное');
        $this->product($bare);
        CatalogMenu::flush();

        foreach (['/', '/catalog'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/data-category-tile="'.$withPhoto->id.'".*?mixer\.jpg/s', $html, $url);
            $this->assertMatchesRegularExpression('/data-category-tile="'.$iconOnly->id.'".*?<svg[^>]*data-category-icon/s', $html, $url);
            // Без фото и без иконки — плитка всё равно есть
            $this->assertStringContainsString('data-category-tile="'.$bare->id.'"', $html, $url);
        }
    }

    public function test_admin_chooses_tile_product_by_list_or_sku(): void
    {
        $admin = User::factory()->admin()->create();
        $root = $this->category('Смесители');
        $popular = $this->product($root, ['stock' => 9], 'popular.jpg');
        $other = $this->product($root, ['stock' => 1, 'sku' => 'OTHER-1', 'article' => 'L4755-2'], 'other.jpg');
        $noPhoto = $this->product($root, ['sku' => 'NO-PHOTO']);
        $form = ['name' => 'Смесители', 'slug' => $root->slug, 'tile_sent' => 1];

        $this->actingAs($admin)->get("/admin/category/{$root->id}/edit")->assertOk()
            ->assertSee('data-tile-picker', false)
            ->assertSee('data-tile-option="'.$popular->id.'"', false)
            ->assertSee('data-tile-option="'.$other->id.'"', false)
            ->assertDontSee('data-tile-option="'.$noPhoto->id.'"', false);

        $this->actingAs($admin)->post("/admin/category/{$root->id}", $form + ['tile_product' => $other->id])->assertRedirect('/admin/categories');
        $this->assertSame($other->id, $root->fresh()->tile_product_id);

        // Артикул (без учёта регистра) важнее выбора из списка
        $this->actingAs($admin)->post("/admin/category/{$root->id}", $form + ['tile_product' => $other->id, 'tile_sku' => 'l4755-2']);
        $this->assertSame($other->id, $root->fresh()->tile_product_id);

        $this->actingAs($admin)->from("/admin/category/{$root->id}/edit")
            ->post("/admin/category/{$root->id}", $form + ['tile_sku' => 'NO-PHOTO'])
            ->assertSessionHasErrors('tile_sku');
        $this->actingAs($admin)->from("/admin/category/{$root->id}/edit")
            ->post("/admin/category/{$root->id}", $form + ['tile_sku' => 'NOPE'])
            ->assertSessionHasErrors('tile_sku');
        $this->actingAs($admin)->from("/admin/category/{$root->id}/edit")
            ->post("/admin/category/{$root->id}", $form + ['tile_product' => $noPhoto->id])
            ->assertSessionHasErrors('tile_product');

        // «Автоматически»
        $this->actingAs($admin)->post("/admin/category/{$root->id}", $form + ['tile_product' => '']);
        $this->assertNull($root->fresh()->tile_product_id);

        // Форма без блока плитки (tile_sent нет) выбор не трогает
        $root->update(['tile_product_id' => $other->id]);
        $this->actingAs($admin)->post("/admin/category/{$root->id}", ['name' => 'Смесители', 'slug' => $root->slug]);
        $this->assertSame($other->id, $root->fresh()->tile_product_id);
    }

    // --- Стартовые соответствия ---

    public function test_start_mapping_covers_categories_with_set_icons(): void
    {
        $map = require database_path('data/category_icons.php');

        $this->assertGreaterThanOrEqual(224, count($map));
        foreach ($map as $name => $key) {
            $this->assertStringStartsWith('plumb:', $key, $name);
            $this->assertTrue($this->icons->exists($key), "{$name}: {$key}");
        }

        // Те же категории, что в прежнем списке
        $this->assertSame([], array_diff(array_keys(require database_path('data/category_icons_v1.php')), array_keys($map)));
    }

    public function test_plumb_migration_replaces_only_start_icons(): void
    {
        $start = $this->category('Сифоны', ['icon' => 'custom:siphon']);
        $thermostat = $this->category('Термостаты комнатные', ['icon' => 'material:nest_thermostat']);
        $manual = $this->category('Смесители', ['icon' => 'custom:pipe']);
        $empty = $this->category('Радиаторы');
        $upload = $this->category('Ведра', ['icon' => 'upload', 'icon_svg' => '<svg/>']);

        $migration = require database_path('migrations/2026_11_20_110000_plumb_category_icons.php');
        $migration->up();

        $this->assertSame('plumb:siphon', $start->fresh()->icon);
        $this->assertSame('plumb:thermostat', $thermostat->fresh()->icon);
        $this->assertSame('custom:pipe', $manual->fresh()->icon, 'выбрана вручную — не трогаем');
        $this->assertSame('plumb:radiator', $empty->fresh()->icon);
        $this->assertSame('upload', $upload->fresh()->icon);
    }

    public function test_fill_migration_does_not_overwrite_chosen_icons(): void
    {
        $chosen = $this->category('Смесители', ['icon' => 'plumb:pipe']);
        $empty = $this->category('Сифоны');
        $unknown = $this->category('Нет в списке');

        $migration = require database_path('migrations/2026_11_19_110000_fill_category_icons.php');
        $migration->up();

        $this->assertSame('plumb:pipe', $chosen->fresh()->icon);
        $this->assertSame('plumb:siphon', $empty->fresh()->icon);
        $this->assertNull($unknown->fresh()->icon);
    }

    public function test_icons_are_never_smaller_than_24px(): void
    {
        // Компонент не даёт уменьшить иконку меньше 24 px
        $html = (string) $this->blade('<x-category-icon icon="plumb:siphon" class="h-4 w-4" />');
        $this->assertStringContainsString('min-h-6 min-w-6', $html);

        // В шаблонах — нет размеров меньше 24 px (h-1…h-5, h-[≤23px])
        $checked = 0;
        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<x-category-icon\b[^>]*class="([^"]*)"/', $file->getContents(), $m);
            foreach ($m[1] as $class) {
                $checked++;
                $this->assertDoesNotMatchRegularExpression('/\b[hw]-([1-5]|\[(1?\d|2[0-3])px\])(\s|$)/', $class, $file->getRelativePathname().": {$class}");
            }
        }
        $this->assertGreaterThan(10, $checked, 'проверены шаблоны с иконками, в том числе partials/header');

        // Плитка без фото: иконка ~120 px на фоне --ic-fill 40 %
        $tile = (string) $this->blade('<x-category-tile :node="$n" />', ['n' => ['id' => 1, 'name' => 'Сифоны', 'url' => '/catalog/x', 'count' => 3, 'icon' => 'plumb:siphon', 'icon_svg' => null]]);
        $this->assertStringContainsString('bg-ic-tile', $tile);
        $this->assertStringContainsString('h-[120px] w-[120px]', $tile);
        $this->assertStringContainsString('color-mix(in srgb, var(--ic-fill) 40%, transparent)', file_get_contents(resource_path('css/app.css')));
    }

    public function test_picker_hides_empty_groups_while_searching(): void
    {
        $html = (string) $this->blade('<x-category-icon-picker :category="$c" />', ['c' => new Category(['name' => 'Тест'])]);

        $this->assertSame(1, substr_count($html, 'x-show="any($el)" data-icon-group'));
        $this->assertStringContainsString('data-icon-nothing', $html);
    }
}
