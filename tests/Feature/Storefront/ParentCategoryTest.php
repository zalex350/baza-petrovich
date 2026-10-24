<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\CatalogFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Родительская категория: товары всех подкатегорий (рекурсивно), чипы подкатегорий,
 * кликабельные родители в мегаменю, мобильном меню и хлебных крошках; без N+1.
 */
class ParentCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $root;

    private Category $child;

    private Category $grandchild;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->root = Category::create(['name' => 'Сантехника', 'slug' => 'santehnika']);
        $this->child = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli', 'parent_id' => $this->root->id]);
        $this->grandchild = Category::create(['name' => 'Для кухни', 'slug' => 'dlya-kuhni', 'parent_id' => $this->child->id]);
    }

    private function product(Category $category, string $name): Product
    {
        $this->n++;

        return Product::create([
            'name' => $name, 'sku' => "SKU-{$this->n}", 'brand' => 'Grohe', 'price' => 100, 'stock' => 5,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_parent_page_shows_products_of_all_subcategories(): void
    {
        $this->product($this->root, 'Товар корня');
        $this->product($this->child, 'Товар смесителей');
        $this->product($this->grandchild, 'Товар кухни');

        $this->actingAs($this->client)->get('/catalog/santehnika')
            ->assertOk()
            ->assertSee('Товар корня')
            ->assertSee('Товар смесителей')
            ->assertSee('Товар кухни')
            ->assertSee('3 товара');

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertDontSee('Товар корня')
            ->assertSee('Товар кухни');
    }

    public function test_subcategory_chips_link_to_direct_children(): void
    {
        $empty = Category::create(['name' => 'Пустая', 'slug' => 'pustaya', 'parent_id' => $this->root->id]);
        $this->product($this->grandchild, 'Товар кухни');

        $html = $this->actingAs($this->client)->get('/catalog/santehnika')->assertOk()->getContent();

        $this->assertStringContainsString('data-subcategories', $html);
        // Прямой потомок — с количеством всех вложенных товаров; внук и пустая — не чипами
        $this->assertMatchesRegularExpression('#<a href="/catalog/smesiteli"[^>]*data-subcategory>\s*Смесители\s*<span[^>]*>1</span>#', $html);
        $this->assertDoesNotMatchRegularExpression('#href="/catalog/dlya-kuhni"[^>]*data-subcategory#', $html);
        $this->assertStringNotContainsString('/catalog/'.$empty->slug.'"', $html);

        // У листовой категории чипов нет
        $this->actingAs($this->client)->get('/catalog/dlya-kuhni')->assertDontSee('data-subcategories', false);
    }

    public function test_many_subcategories_are_collapsed(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->product(Category::create(['name' => "Раздел {$i}", 'slug' => "razdel-{$i}", 'parent_id' => $this->root->id]), "Товар {$i}");
        }

        $html = $this->actingAs($this->client)->get('/catalog/santehnika')->assertOk()->getContent();

        // 14 разделов: 12 видно, 2 — по «Показать все (14)»
        $this->assertSame(14, substr_count($html, 'data-subcategory>'));
        $this->assertSame(2, preg_match_all('#<li\s+x-show="all"#', $html));
        $this->assertStringContainsString('Показать все (14)', $html);
    }

    public function test_parents_are_links_in_breadcrumbs_mega_menu_and_mobile_menu(): void
    {
        $this->product($this->grandchild, 'Товар кухни');

        $html = $this->actingAs($this->client)->get('/catalog/dlya-kuhni')->assertOk()->getContent();

        // Хлебные крошки: родители — ссылки, текущая — текст
        $crumbs = substr($html, strpos($html, 'data-breadcrumbs'));
        $this->assertStringContainsString('<a href="/catalog/santehnika"', $crumbs);
        $this->assertStringContainsString('<a href="/catalog/smesiteli"', $crumbs);
        $this->assertStringContainsString('aria-current="page">Для кухни</span>', $crumbs);

        // Мегаменю: заголовок группы — ссылка на раздел
        $this->assertMatchesRegularExpression('#<a href="/catalog/santehnika"[^>]*data-mega-heading>Сантехника</a>#', $html);

        // Мобильное меню: у раздела с подразделами — ссылка и отдельная кнопка «вглубь»
        $this->assertStringContainsString(':href="node.url"', $html);
        $this->assertStringContainsString('data-mobile-parent-link', $html);
        $this->assertStringContainsString('@click="enter(node)"', $html);
    }

    public function test_filters_are_inherited_from_parent(): void
    {
        $color = ProductAttribute::create(['name' => 'Цвет', 'is_filterable' => true]);
        app(CatalogFilters::class)->save($this->root, ['brand', "attribute:{$color->id}"]);

        $p = $this->product($this->grandchild, 'Товар кухни');
        ProductAttributeValue::create(['product_id' => $p->id, 'attribute_id' => $color->id, 'value' => 'Хром', 'sort_order' => 0]);

        $this->actingAs($this->client)->get('/catalog/dlya-kuhni')
            ->assertOk()
            ->assertSee('data-facet="attribute:'.$color->id.'"', false)
            ->assertSee('Хром');
    }

    public function test_query_count_does_not_grow_with_subcategories_and_products(): void
    {
        $color = ProductAttribute::create(['name' => 'Цвет', 'is_filterable' => true]);
        app(CatalogFilters::class)->save($this->root, ['brand', 'price', 'stock', "attribute:{$color->id}"]);

        $fill = function (int $categories, int $perCategory) use ($color) {
            for ($c = 0; $c < $categories; $c++) {
                $sub = Category::create(['name' => "Раздел {$this->n}-{$c}", 'slug' => "razdel-{$this->n}-{$c}", 'parent_id' => $this->child->id]);

                for ($i = 0; $i < $perCategory; $i++) {
                    $p = $this->product($sub, "Товар {$c}-{$i}");
                    ProductAttributeValue::create(['product_id' => $p->id, 'attribute_id' => $color->id, 'value' => 'Хром', 'sort_order' => 0]);
                }
            }
        };

        $count = function (): int {
            // Прогрев: дерево меню строится один раз и кэшируется
            $this->actingAs($this->client)->get('/catalog/santehnika')->assertOk();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->client)->get('/catalog/santehnika')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $fill(2, 2);
        $small = $count();

        $fill(6, 3);
        $large = $count();

        $this->assertSame($small, $large, "Запросов: {$small} при 4 товарах, {$large} при 22");
    }
}
