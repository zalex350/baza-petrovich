<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVatTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'price' => 100,
            'stock' => 5,
            'is_active' => true,
        ], $attributes));
    }

    // --- Accessors ---

    public function test_default_vat_rate_is_20_percent(): void
    {
        $product = $this->makeProduct();

        $this->assertEquals(20.00, $product->vat_rate);
        $this->assertEquals(20.00, $product->fresh()->vat_rate);
    }

    public function test_vat_amount_and_price_with_vat_default_rate(): void
    {
        $product = $this->makeProduct(['price' => 100])->fresh();

        $this->assertSame(20.0, $product->vat_amount);
        $this->assertSame(120.0, $product->price_with_vat);
    }

    public function test_accessors_work_on_unsaved_model_with_default_rate(): void
    {
        $product = new Product(['price' => 50]);

        $this->assertSame(10.0, $product->vat_amount);
        $this->assertSame(60.0, $product->price_with_vat);
    }

    public function test_vat_amount_is_rounded_to_two_decimals(): void
    {
        // 199.99 * 20% = 39.998 → 40.00
        $product = $this->makeProduct(['price' => 199.99])->fresh();

        $this->assertSame(40.0, $product->vat_amount);
        $this->assertSame(239.99, $product->price_with_vat);
    }

    public function test_custom_vat_rate(): void
    {
        // 33.33 * 10% = 3.333 → 3.33
        $product = $this->makeProduct(['price' => 33.33, 'vat_rate' => 10])->fresh();

        $this->assertSame(3.33, $product->vat_amount);
        $this->assertSame(36.66, $product->price_with_vat);
    }

    public function test_zero_vat_rate(): void
    {
        $product = $this->makeProduct(['price' => 80, 'vat_rate' => 0])->fresh();

        $this->assertSame(0.0, $product->vat_amount);
        $this->assertSame(80.0, $product->price_with_vat);
    }

    // --- Admin ---

    public function test_admin_can_set_vat_rate_on_create(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/product', [
            'name' => 'VAT Product',
            'sku' => 'VAT-001',
            'price' => 100,
            'vat_rate' => 10,
            'stock' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(10, Product::where('sku', 'VAT-001')->first()->vat_rate);
    }

    public function test_create_without_vat_rate_uses_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/product', [
            'name' => 'No VAT field',
            'sku' => 'VAT-002',
            'price' => 100,
            'stock' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(20, Product::where('sku', 'VAT-002')->first()->vat_rate);
    }

    public function test_admin_can_update_vat_rate(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post("/admin/product/{$product->id}", [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => 100,
            'vat_rate' => 0,
            'stock' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(0, $product->fresh()->vat_rate);
    }

    public function test_vat_rate_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->makeProduct();

        foreach ([-1, 100.01, 'abc', ''] as $invalid) {
            $this->actingAs($admin)->post("/admin/product/{$product->id}", [
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => 100,
                'vat_rate' => $invalid,
                'stock' => 5,
            ])->assertSessionHasErrors(['vat_rate']);

            $this->actingAs($admin)->post('/admin/product', [
                'name' => 'Invalid VAT',
                'sku' => 'VAT-BAD',
                'price' => 100,
                'vat_rate' => $invalid,
                'stock' => 1,
            ])->assertSessionHasErrors(['vat_rate']);
        }

        $this->assertEquals(20, $product->fresh()->vat_rate);
        $this->assertDatabaseMissing('products', ['sku' => 'VAT-BAD']);
    }

    public function test_admin_forms_have_vat_rate_field(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->makeProduct();

        $this->actingAs($admin)->get('/admin/product/create')
            ->assertSee('Ставка НДС, %')
            ->assertSee('name="vat_rate"', false);

        $this->actingAs($admin)->get("/admin/product/{$product->id}")
            ->assertSee('Ставка НДС, %')
            ->assertSee('name="vat_rate"', false);
    }

    // --- Public pages ---

    public function test_product_page_shows_price_breakdown(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(['price' => 100]);

        $this->actingAs($user)->get("/product/{$product->id}")
            ->assertStatus(200)
            ->assertSeeInOrder([
                'Цена без НДС, за шт.', Money::format(100),
                'с НДС 20% — '.Money::format(120),
            ]);
    }

    public function test_catalog_shows_price_with_vat(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        $this->makeProduct(['price' => 100, 'category_id' => $category->id]);

        $this->actingAs($user)->get('/catalog/smesiteli')
            ->assertStatus(200)
            ->assertSeeInOrder([Money::format(100), 'без НДС', 'с НДС '.Money::format(120, false)]);
    }

    public function test_search_shows_price_with_vat(): void
    {
        $user = User::factory()->create();
        $this->makeProduct(['price' => 100]);

        $this->actingAs($user)->get('/search?q=Grohe')
            ->assertStatus(200)
            ->assertSeeInOrder([Money::format(100), 'без НДС', 'с НДС '.Money::format(120, false)]);
    }
}
