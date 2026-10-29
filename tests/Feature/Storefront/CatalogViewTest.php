<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Catalog\CatalogListing;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Список каталога: вид «плитка / список» (?view=grid), «Показать ещё», плитки подкатегорий.
 */
class CatalogViewTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
    }

    private function products(int $count, ?Category $category = null): void
    {
        foreach (range(1, $count) as $i) {
            Product::create([
                'name' => "Смеситель {$i}", 'sku' => "V-{$i}", 'price' => 10, 'stock' => 5,
                'category_id' => ($category ?? $this->category)->id, 'is_active' => true, 'is_published' => true,
            ]);
        }
    }

    public function test_list_view_is_default(): void
    {
        $this->products(2);

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertOk()
            ->assertSee('data-product-row', false)
            ->assertDontSee('data-product-card', false)
            ->assertSee('data-view-switch', false)
            ->assertSee('data-view="grid"', false);
    }

    public function test_grid_view_shows_tiles_with_code_stock_price_and_buy(): void
    {
        $this->products(2);

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli?view=grid')
            ->assertOk()
            ->assertSee('data-view-grid', false)
            ->assertSee('Код: V-1')
            ->assertDontSee('data-product-row', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-product-card'));
        $this->assertSame(2, substr_count($html, 'name="layout" value="tile"'));
        $this->assertStringContainsString('data-stock=', $html);
        // Вид сохраняется при смене фильтров — скрытое поле формы
        $this->assertStringContainsString('name="view" value="grid"', $html);
    }

    public function test_add_from_tile_returns_tile_block(): void
    {
        $this->products(1);

        $html = $this->actingAs($this->client)
            ->postJson('/order/add/'.Product::first()->id, ['qty' => 2, 'layout' => 'tile'])
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('name="layout" value="tile"', $html);
        $this->assertStringContainsString('В корзине: 2 шт.', $html);
    }

    public function test_load_more_links_to_next_page_keeping_view_and_filters(): void
    {
        $this->products(CatalogListing::PER_PAGE + 3);

        $html = $this->actingAs($this->client)->get('/catalog/smesiteli?view=grid&sort=name_asc')
            ->assertOk()
            ->assertSee('data-load-more', false)
            ->assertSee('Показать ещё')
            ->getContent();

        $this->assertMatchesRegularExpression('#href="[^"]*view=grid[^"]*page=2[^"]*"[^>]*data-load-more#', str_replace('&amp;', '&', $html));
        // Пагинация остаётся
        $this->assertStringContainsString('data-pagination', $html);

        $this->actingAs($this->client)->get('/catalog/smesiteli?view=grid&sort=name_asc&page=2')
            ->assertOk()
            ->assertDontSee('data-load-more', false);
    }

    public function test_reset_filters_keeps_view(): void
    {
        $this->products(1);

        $this->actingAs($this->client)->get('/catalog/smesiteli?view=grid&stock[]=in_stock')
            ->assertSee('href="/catalog/smesiteli?view=grid"', false);
    }

    public function test_subcategories_as_tiles_when_any_has_image_otherwise_chips(): void
    {
        $child = Category::create(['name' => 'Для кухни', 'slug' => 'kitchen', 'parent_id' => $this->category->id]);
        $other = Category::create(['name' => 'Для ванной', 'slug' => 'bath', 'parent_id' => $this->category->id]);
        $this->products(1, $child);
        $this->products(1, $other);

        $this->get('/catalog/smesiteli')
            ->assertSee('data-subcategories', false)
            ->assertDontSee('data-subcategory-tiles', false);

        $child->update(['image' => 'categories/kitchen.webp']);
        CatalogMenu::flush();

        $this->get('/catalog/smesiteli')
            ->assertSee('data-subcategory-tiles', false)
            ->assertSee('categories/kitchen.webp', false)
            // У подкатегории без картинки — заглушка
            ->assertSee(ProductImage::placeholderUrl(), false);
    }
}
