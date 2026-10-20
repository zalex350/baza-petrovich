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

    public function test_results_are_paginated_by_24_and_keep_query(): void
    {
        // Латиница: LOWER/LIKE в SQLite тестов не работают с кириллицей (в MySQL — работают)
        for ($i = 1; $i <= 30; $i++) {
            $this->createProduct(['name' => sprintf('Mixer %02d', $i), 'sku' => "MIX-{$i}"]);
        }

        $first = $this->get('/search?q=mixer')->assertOk()->getContent();

        $this->assertStringContainsString('Mixer 01', $first);
        $this->assertStringContainsString('Mixer 24', $first);
        $this->assertStringNotContainsString('Mixer 25', $first);
        $this->assertStringContainsString('Найдено товаров: 30', $first);
        $this->assertStringContainsString(e('/search?' . http_build_query(['q' => 'mixer', 'page' => 2])), $first);

        $second = $this->get('/search?q=mixer&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('Найдено товаров: 30', $second);
        $this->assertStringContainsString('Mixer 25', $second);
        $this->assertStringContainsString('Mixer 30', $second);
        $this->assertStringNotContainsString('Mixer 24', $second);
    }

    public function test_empty_query_has_no_pagination_error(): void
    {
        $this->get('/search?q=&page=3')->assertOk()->assertSee('Найдено товаров: 0');
    }
}
