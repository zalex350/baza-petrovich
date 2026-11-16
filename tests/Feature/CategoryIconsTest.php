<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogMenu;
use App\Services\CategoryIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Иконки категорий (CategoryIcons, x-category-icon, x-category-icon-picker): набор (Material из списка
 * и свои), вывод с currentColor, очистка загруженной SVG, админка (выбор, загрузка, превью только
 * админу), витрина (главная, /catalog, мегаменю, подкатегории), стартовые соответствия.
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

    /** Категория с товаром — иначе её нет в меню и на главной */
    private function filled(string $name, array $attributes = []): Category
    {
        $category = $this->category($name, $attributes);
        Product::create(['name' => "Товар {$name}", 'sku' => 'S-'.$category->id, 'price' => 1, 'stock' => 1,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);

        return $category;
    }

    // --- Набор ---

    public function test_library_has_listed_material_icons_and_custom_icons(): void
    {
        $material = json_decode(file_get_contents(resource_path('icons/material.json')), true);
        $custom = json_decode(file_get_contents(resource_path('icons/custom.json')), true);
        $library = $this->icons->library();

        foreach (array_keys($material) as $name) {
            $this->assertArrayHasKey("material:{$name}", $library, "нет файла material/{$name}.svg — npm run icons");
        }
        foreach (array_keys($custom) as $name) {
            $this->assertArrayHasKey("custom:{$name}", $library, $name);
        }

        // В проекте — только иконки из списка
        $files = array_map(fn ($f) => basename($f, '.svg'), glob(resource_path('icons/material/*.svg')));
        sort($files);
        $listed = array_keys($material);
        sort($listed);
        $this->assertSame($listed, $files);

        $this->assertArrayHasKey('custom:siphon', $library);
        $this->assertArrayHasKey('custom:flex_hose', $library);
        $this->assertArrayHasKey('custom:fitting_tee', $library);
        $this->assertArrayHasKey('custom:corrugated_pipe', $library);
        $this->assertArrayHasKey('custom:installation', $library);
    }

    public function test_custom_icons_follow_the_style(): void
    {
        foreach (glob(resource_path('icons/custom/*.svg')) as $file) {
            $svg = file_get_contents($file);
            $name = basename($file);

            $this->assertStringContainsString('viewBox="0 0 24 24"', $svg, $name);
            $this->assertStringContainsString('stroke-width="1.5"', $svg, $name);
            $this->assertStringContainsString('stroke="currentColor"', $svg, $name);
            $this->assertStringContainsString('stroke-linecap="round"', $svg, $name);
            $this->assertStringContainsString('stroke-linejoin="round"', $svg, $name);
            // Своих цветов нет
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,6}|rgb\(/i', $svg, $name);
            $this->assertNotNull(simplexml_load_string($svg), $name);
        }
    }

    public function test_render_uses_current_color_and_class_size(): void
    {
        $material = (string) $this->icons->render('material:faucet', null, 'h-8 w-8');
        $this->assertStringStartsWith('<svg', $material);
        $this->assertStringContainsString('fill="currentColor"', $material);
        $this->assertStringContainsString('class="h-8 w-8"', $material);
        $this->assertStringContainsString('aria-hidden="true"', $material);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*\s(width|height)=/', $material);

        $custom = (string) $this->icons->render('custom:siphon');
        $this->assertStringContainsString('stroke="currentColor"', $custom);
        $this->assertStringContainsString('fill="none"', $custom);

        $this->assertNull($this->icons->render(null));
        $this->assertNull($this->icons->render('material:no_such_icon'));
        $this->assertNull($this->icons->render('upload', null));
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
        $this->assertStringContainsString('<rect x="1" y="1" width="4" height="4" fill="currentColor"/>', $clean);

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

    // --- Админка ---

    public function test_admin_chooses_icon_from_library_and_clears_it(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category('Сифоны');

        $this->actingAs($admin)->get("/admin/category/{$category->id}/edit")->assertOk()
            ->assertSee('data-icon-picker', false)
            ->assertSee('data-icon-option="custom:siphon"', false)
            ->assertSee('data-icon-option="material:faucet"', false);

        $form = ['name' => 'Сифоны', 'slug' => $category->slug];

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'custom:siphon'])
            ->assertRedirect('/admin/categories');
        $this->assertSame('custom:siphon', $category->fresh()->icon);

        $this->actingAs($admin)->from("/admin/category/{$category->id}/edit")
            ->post("/admin/category/{$category->id}", $form + ['icon' => 'material:nope'])
            ->assertSessionHasErrors('icon');
        $this->assertSame('custom:siphon', $category->fresh()->icon);

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

        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'material:faucet', 'icon_file' => $file])
            ->assertRedirect('/admin/categories');

        $category->refresh();
        $this->assertSame('upload', $category->icon);
        $this->assertStringContainsString('fill="currentColor"', $category->icon_svg);
        $this->assertStringNotContainsString('onload', $category->icon_svg);

        // Сохранение без нового файла — загруженная остаётся
        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'upload']);
        $this->assertSame('upload', $category->fresh()->icon);
        $this->assertNotNull($category->fresh()->icon_svg);

        // Выбор из набора — загруженная убирается
        $this->actingAs($admin)->post("/admin/category/{$category->id}", $form + ['icon' => 'custom:pipe']);
        $this->assertSame(['custom:pipe', null], [$category->fresh()->icon, $category->fresh()->icon_svg]);

        // Не SVG — ошибка, иконка не меняется
        $this->actingAs($admin)->from("/admin/category/{$category->id}/edit")
            ->post("/admin/category/{$category->id}", $form + ['icon_file' => UploadedFile::fake()->createWithContent('x.svg', 'hello')])
            ->assertSessionHasErrors('icon_file');
        $this->assertSame('custom:pipe', $category->fresh()->icon);
    }

    public function test_preview_page_only_for_admin(): void
    {
        $root = $this->category('Смесители', ['icon' => 'material:faucet']);
        $this->category('Для кухни', ['parent_id' => $root->id, 'icon' => 'custom:kitchen_sink']);
        $this->category('Без иконки', ['parent_id' => $root->id]);

        $this->get('/admin/category-icons')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/category-icons')->assertForbidden();

        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/category-icons')->assertOk()
            ->assertSee('Иконки категорий')
            ->assertSee('Для кухни')
            ->assertSee('custom:kitchen_sink')
            ->assertSee('нет иконки')
            ->assertSee('Без иконки: 1.')
            ->getContent();

        $this->assertStringContainsString('data-icon-library="custom"', $html);
        $this->assertStringContainsString('data-icon-library="material"', $html);
    }

    // --- Витрина ---

    public function test_icons_on_home_catalog_and_mega_menu(): void
    {
        $root = $this->filled('Смесители', ['icon' => 'material:faucet']);
        $child = $this->filled('Для кухни', ['parent_id' => $root->id, 'icon' => 'custom:kitchen_sink']);
        $this->filled('Без иконки', ['parent_id' => $root->id]);
        $this->filled('Раздел без иконки');
        CatalogMenu::flush();

        $faucet = 'data-category-icon';

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(1, substr_count($home, $faucet));
        $this->assertStringContainsString('Раздел без иконки', $home);

        $catalog = $this->get('/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('Для кухни', $catalog);
        $this->assertStringContainsString('Без иконки', $catalog);

        // Мегаменю: у раздела и подраздела — иконки; раздел без иконки — пустое место той же ширины
        $this->assertMatchesRegularExpression('/data-mega-root="'.$root->id.'"[^>]*>\s*<svg[^>]*data-category-icon/s', $home);
        $this->assertStringNotContainsString('heroicon-o-squares-2x2', $home);

        // Страница раздела: чипы подкатегорий с иконкой и без
        $this->get('/catalog/'.$root->slug)->assertOk()
            ->assertSee('Для кухни')
            ->assertSee('Без иконки')
            ->assertSee('data-category-icon', false);

        // Цвет — currentColor, своих цветов нет
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*data-category-icon[^>]*\sfill="#/', $home);
        $this->assertNotNull($child);
    }

    public function test_menu_without_icons_still_renders(): void
    {
        $root = $this->filled('Раздел');
        $this->filled('Подраздел', ['parent_id' => $root->id]);
        CatalogMenu::flush();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Подраздел', $html);
        $this->assertStringNotContainsString('data-category-icon', $html);
    }

    // --- Стартовые соответствия ---

    public function test_start_mapping_uses_existing_icons(): void
    {
        $map = require database_path('data/category_icons.php');

        $this->assertGreaterThan(200, count($map));
        foreach ($map as $name => $key) {
            $this->assertTrue($this->icons->exists($key), "{$name}: {$key}");
        }
    }

    public function test_fill_migration_does_not_overwrite_chosen_icons(): void
    {
        $chosen = $this->category('Смесители', ['icon' => 'custom:pipe']);
        $empty = $this->category('Сифоны');
        $unknown = $this->category('Нет в списке');

        $migration = require database_path('migrations/2026_11_19_110000_fill_category_icons.php');
        $migration->up();

        $this->assertSame('custom:pipe', $chosen->fresh()->icon);
        $this->assertSame('custom:siphon', $empty->fresh()->icon);
        $this->assertNull($unknown->fresh()->icon);
    }

    public function test_room_thermostat_start_icon_is_replaced_but_manual_choice_kept(): void
    {
        $start = $this->category('Термостаты комнатные', ['icon' => 'material:nest_thermostat']);
        $migration = require database_path('migrations/2026_11_19_120000_room_thermostat_icon.php');

        $migration->up();
        $this->assertSame('material:thermostat', $start->fresh()->icon);

        // Выбрано вручную — не трогается
        $start->update(['icon' => 'custom:radiator']);
        $migration->up();
        $this->assertSame('custom:radiator', $start->fresh()->icon);

        // Стартовый список — уже с новой иконкой
        $this->assertSame('material:thermostat', (require database_path('data/category_icons.php'))['Термостаты комнатные']);
    }

    public function test_picker_hides_empty_groups_while_searching(): void
    {
        $html = (string) $this->blade('<x-category-icon-picker :category="$c" />', ['c' => new Category(['name' => 'Тест'])]);

        $this->assertSame(2, substr_count($html, 'x-show="any($el)" data-icon-group'));
        $this->assertStringContainsString('data-icon-nothing', $html);
    }
}
