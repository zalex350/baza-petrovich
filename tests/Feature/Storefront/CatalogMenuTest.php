<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryOrder;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogMenuTest extends TestCase
{
    use RefreshDatabase;

    private int $sku = 0;

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'parent_id' => $parent?->id]);
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Товар '.++$this->sku,
            'sku' => 'SKU-'.$this->sku,
            'price' => 100,
            'stock' => 5,
            'category_id' => $category->id,
            'is_active' => true,
            'is_published' => true,
        ], $attributes));
    }

    private function menu(): array
    {
        return app(CatalogMenu::class)->tree();
    }

    private function find(array $nodes, string $name): ?array
    {
        foreach ($nodes as $node) {
            if ($node['name'] === $name) {
                return $node;
            }
            if ($found = $this->find($node['children'], $name)) {
                return $found;
            }
        }

        return null;
    }

    // --- состав и счётчики ---

    public function test_only_categories_with_published_active_products_are_shown(): void
    {
        $full = $this->category('Смесители');
        $this->product($full);

        $unpublished = $this->category('Только черновики');
        $this->product($unpublished, ['is_published' => false]);

        $inactive = $this->category('Только скрытые');
        $this->product($inactive, ['is_active' => false]);

        $deleted = $this->category('Только удалённые');
        $this->product($deleted)->delete();

        $this->category('Пустая');

        $names = array_column($this->menu(), 'name');

        $this->assertSame(['Смесители'], $names);
    }

    public function test_counts_include_nested_subcategories(): void
    {
        $root = $this->category('Ванная');
        $child = $this->category('Смесители', $root);
        $grandchild = $this->category('Для раковины', $child);
        $emptyChild = $this->category('Пусто', $root);

        $this->product($root);
        $this->product($child);
        $this->product($child);
        $this->product($grandchild);
        $this->product($grandchild);
        $this->product($grandchild);
        $this->product($grandchild, ['is_published' => false]);
        $this->product($emptyChild, ['is_active' => false]);

        $tree = $this->menu();

        $this->assertSame(6, $this->find($tree, 'Ванная')['count']);
        $this->assertSame(5, $this->find($tree, 'Смесители')['count']);
        $this->assertSame(3, $this->find($tree, 'Для раковины')['count']);
        $this->assertNull($this->find($tree, 'Пусто'));
    }

    public function test_parent_without_own_products_is_shown_via_children(): void
    {
        $root = $this->category('Раздел');
        $this->product($this->category('Подраздел', $root));

        $node = $this->find($this->menu(), 'Раздел');

        $this->assertSame(1, $node['count']);
        $this->assertSame('/catalog/'.$root->slug, $node['url']);
    }

    public function test_tree_is_built_with_two_queries(): void
    {
        $root = $this->category('Корень');
        foreach (range(1, 5) as $i) {
            $child = $this->category("Ветка {$i}", $root);
            foreach (range(1, 3) as $j) {
                $this->product($this->category("Лист {$i}-{$j}", $child));
            }
        }

        // Режим порядка категорий — из настроек (кэш на всю страницу, читается и подвалом)
        CategoryOrder::mode();

        DB::enableQueryLog();
        app(CatalogMenu::class)->build();

        $this->assertCount(2, DB::getQueryLog());
    }

    // --- кэш ---

    public function test_tree_is_cached(): void
    {
        $this->product($this->category('Смесители'));

        $this->menu();
        $this->assertTrue(Cache::has(CatalogMenu::CACHE_KEY));

        DB::enableQueryLog();
        $this->menu();
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_cache_is_flushed_when_product_is_published(): void
    {
        $category = $this->category('Смесители');
        $product = $this->product($category, ['is_published' => false]);

        $this->assertSame([], $this->menu());

        $product->update(['is_published' => true]);

        $this->assertSame(['Смесители'], array_column($this->menu(), 'name'));
    }

    public function test_cache_is_flushed_when_product_is_hidden(): void
    {
        $product = $this->product($this->category('Смесители'));
        $this->assertCount(1, $this->menu());

        $product->update(['is_active' => false]);

        $this->assertSame([], $this->menu());
    }

    public function test_cache_is_flushed_via_admin_publish_and_toggle(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($this->category('Смесители'), ['is_published' => false]);
        $this->assertSame([], $this->menu());

        $this->actingAs($admin)->post("/admin/product/{$product->id}/publish");
        $this->assertSame(1, $this->menu()[0]['count']);

        $this->actingAs($admin)->post("/admin/product/{$product->id}/toggle-visibility");
        $this->assertSame([], $this->menu());
    }

    public function test_cache_is_flushed_when_product_moves_or_is_deleted(): void
    {
        $a = $this->category('Раздел А');
        $b = $this->category('Раздел Б');
        $product = $this->product($a);

        $this->assertSame(['Раздел А'], array_column($this->menu(), 'name'));

        $product->update(['category_id' => $b->id]);
        $this->assertSame(['Раздел Б'], array_column($this->menu(), 'name'));

        $product->delete();
        $this->assertSame([], $this->menu());

        $product->restore();
        $this->assertSame(['Раздел Б'], array_column($this->menu(), 'name'));
    }

    public function test_cache_is_flushed_when_category_changes(): void
    {
        $category = $this->category('Старое имя');
        $this->product($category);
        $this->menu();

        $category->update(['name' => 'Новое имя']);

        $this->assertSame(['Новое имя'], array_column($this->menu(), 'name'));
    }

    public function test_stock_change_does_not_flush_cache(): void
    {
        $product = $this->product($this->category('Смесители'));
        $this->menu();

        $product->update(['stock' => 1]);

        $this->assertTrue(Cache::has(CatalogMenu::CACHE_KEY));
    }

    // --- вывод ---

    public function test_mega_menu_renders_roots_children_and_more_link(): void
    {
        $root = $this->category('Ванная');
        $child = $this->category('Смесители', $root);
        foreach (range(1, 7) as $i) {
            $this->product($this->category("Тип {$i}", $child));
        }
        $this->category('Пустая корневая');

        $html = $this->get('/')->assertOk()->getContent();

        // Главная сама выводит категории — проверяем только панель мегаменю
        $start = strpos($html, 'id="mega-menu"');
        $panel = substr($html, $start, strpos($html, 'data-search-suggest', $start) - $start);

        $this->assertStringContainsString('data-mega-root="'.$root->id.'"', $panel);
        $this->assertStringContainsString('Открыть весь раздел', $panel);
        $this->assertStringContainsString('Тип 1', $panel);
        $this->assertStringContainsString('Тип 5', $panel);
        $this->assertStringNotContainsString('Тип 6', $panel);
        $this->assertStringContainsString('ещё 2', $panel);
        $this->assertStringNotContainsString('Пустая корневая', $panel);
    }

    public function test_menu_json_endpoint(): void
    {
        $root = $this->category('Ванная');
        $this->product($this->category('Смесители', $root));

        $this->getJson('/catalog/menu.json')
            ->assertOk()
            ->assertJsonPath('0.name', 'Ванная')
            ->assertJsonPath('0.count', 1)
            ->assertJsonPath('0.children.0.name', 'Смесители')
            ->assertJsonPath('0.url', '/catalog/'.$root->slug);
    }

    public function test_category_page_shows_products_of_whole_section(): void
    {
        $root = $this->category('Ванная');
        $child = $this->category('Смесители', $root);
        $grandchild = $this->category('Для раковины', $child);
        $other = $this->category('Кухня');

        $this->product($child, ['name' => 'Смеситель из подраздела']);
        $this->product($grandchild, ['name' => 'Смеситель из подподраздела']);
        $this->product($other, ['name' => 'Мойка кухонная']);

        $this->get('/catalog/'.$root->slug)
            ->assertOk()
            ->assertSee('Смеситель из подраздела')
            ->assertSee('Смеситель из подподраздела')
            ->assertDontSee('Мойка кухонная');
    }
}
