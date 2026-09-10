<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Смесители',
            'slug' => 'smesiteli',
        ]);
    }

    private function createProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Grohe Start',
            'sku' => 'GR-100',
            'price' => 150.00,
            'stock' => 10,
            'brand' => 'Grohe',
            'description' => 'Смеситель для кухни',
            'category_id' => $this->category->id,
            'is_active' => true,
        ], $attrs));
    }

    public function test_search_by_exact_sku_finds_product(): void
    {
        $this->createProduct(['sku' => 'GR-100', 'name' => 'Grohe Start']);

        $response = $this->get('/search?q=GR-100');

        $response->assertStatus(200);
        $response->assertSee('Grohe Start');
        $response->assertSee('GR-100');
    }

    public function test_search_by_partial_name_finds_product(): void
    {
        $this->createProduct(['name' => 'Grohe Start']);

        $response = $this->get('/search?q=Groh');

        $response->assertStatus(200);
        $response->assertSee('Grohe Start');
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->createProduct(['name' => 'Grohe Start', 'sku' => 'GR-100']);

        $response = $this->get('/search?q=grohe');

        $response->assertStatus(200);
        $response->assertSee('Grohe Start');
    }

    public function test_inactive_product_not_in_search_results(): void
    {
        $this->createProduct([
            'name' => 'Hidden Product',
            'sku' => 'HDN-999',
            'is_active' => false,
        ]);

        $response = $this->get('/search?q=HDN-999');

        $response->assertStatus(200);
        $response->assertDontSee('Hidden Product');
        $response->assertSee('ничего не найдено');
    }

    public function test_empty_query_returns_no_products_without_error(): void
    {
        $this->createProduct(['name' => 'Grohe Start']);

        $response = $this->get('/search');

        $response->assertStatus(200);
        $response->assertDontSee('Grohe Start');
    }

    public function test_search_with_no_matches_shows_empty_state(): void
    {
        $this->createProduct(['name' => 'Grohe Start']);

        $response = $this->get('/search?q=несуществующийтовар12345');

        $response->assertStatus(200);
        $response->assertDontSee('Grohe Start');
        $response->assertSee('ничего не найдено');
    }
}
