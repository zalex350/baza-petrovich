<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Админка → Товары: поиск по артикулу, названию, бренду вместе с фильтрами и пагинацией.
 */
class AdminProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        foreach ([
            ['Grohe Start', 'GR-001', 'Grohe', $category->id],
            ['Hansgrohe Logis', 'HG-777', 'Hansgrohe', $category->id],
            ['Смеситель Lemark', 'LM-500', 'Lemark', null],
        ] as [$name, $sku, $brand, $categoryId]) {
            Product::create(['name' => $name, 'sku' => $sku, 'brand' => $brand, 'price' => 100, 'stock' => 1, 'category_id' => $categoryId]);
        }
    }

    public function test_search_by_sku_name_and_brand(): void
    {
        foreach (['HG-777' => 'Hansgrohe Logis', 'Logis' => 'Hansgrohe Logis', 'Lemark' => 'Смеситель Lemark'] as $q => $found) {
            $this->actingAs($this->admin)->get('/admin/products?q='.urlencode($q))
                ->assertOk()
                ->assertSee($found)
                ->assertDontSee('GR-001')
                ->assertSee('value="'.$q.'"', false);
        }
    }

    public function test_search_combines_with_filter_and_keeps_it(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/products?category=none&q=grohe')
            ->assertOk()
            ->assertDontSee('Grohe Start')
            ->assertDontSee('Смеситель Lemark')
            ->getContent();

        // Фильтр — в форме поиска, поиск — в ссылке «Показать все»
        $this->assertStringContainsString('name="category" value="none"', $html);
        $this->assertStringContainsString('href="/admin/products?q=grohe"', $html);

        $this->actingAs($this->admin)->get('/admin/products?category=none&q=lemark')
            ->assertSee('Смеситель Lemark');
    }

    public function test_filter_links_keep_search(): void
    {
        $this->actingAs($this->admin)->get('/admin/products?q=Lemark')
            ->assertSee('href="/admin/products?category=none&amp;q=Lemark"', false)
            ->assertSee('Найдено: 1');
    }

    public function test_pagination_keeps_search(): void
    {
        foreach (range(1, 55) as $i) {
            Product::create(['name' => "Grohe {$i}", 'sku' => "GX-{$i}", 'price' => 1, 'stock' => 1]);
        }

        $this->actingAs($this->admin)->get('/admin/products?q=grohe')
            ->assertOk()
            ->assertSee('q=grohe&amp;page=2', false);
    }
}
