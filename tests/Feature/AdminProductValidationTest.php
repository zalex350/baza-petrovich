<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'name'  => 'Grohe Start',
            'sku'   => 'GR-001',
            'price' => 100,
            'stock' => 5,
        ]);
    }

    private function updatePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name'  => $product->name,
            'sku'   => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
        ], $overrides);
    }

    public function test_store_rejects_negative_price(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', [
            'name'  => 'Negative Price',
            'sku'   => 'NEG-PRICE',
            'price' => -1,
            'stock' => 5,
        ]);

        $response->assertSessionHasErrors(['price']);
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-PRICE']);
    }

    public function test_store_rejects_negative_stock(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', [
            'name'  => 'Negative Stock',
            'sku'   => 'NEG-STOCK',
            'price' => 10,
            'stock' => -1,
        ]);

        $response->assertSessionHasErrors(['stock']);
        $this->assertDatabaseMissing('products', ['sku' => 'NEG-STOCK']);
    }

    public function test_update_rejects_negative_price(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(
            "/admin/product/{$product->id}",
            $this->updatePayload($product, ['price' => -50, 'name' => 'Changed'])
        );

        $response->assertSessionHasErrors(['price']);

        $product->refresh();
        $this->assertEquals(100, $product->price);
        $this->assertSame('Grohe Start', $product->name);
    }

    public function test_update_rejects_negative_stock(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(
            "/admin/product/{$product->id}",
            $this->updatePayload($product, ['stock' => -3, 'name' => 'Changed'])
        );

        $response->assertSessionHasErrors(['stock']);

        $product->refresh();
        $this->assertSame(5, (int) $product->stock);
        $this->assertSame('Grohe Start', $product->name);
    }

    public function test_update_requires_price_and_stock(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(
            "/admin/product/{$product->id}",
            $this->updatePayload($product, ['price' => '', 'stock' => ''])
        );

        $response->assertSessionHasErrors(['price', 'stock']);
    }

    public function test_update_rejects_fractional_stock(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(
            "/admin/product/{$product->id}",
            $this->updatePayload($product, ['stock' => 1.5])
        );

        $response->assertSessionHasErrors(['stock']);
    }

    public function test_update_accepts_zero_price_and_stock(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin)->post(
            "/admin/product/{$product->id}",
            $this->updatePayload($product, ['price' => 0, 'stock' => 0])
        );

        $response->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertEquals(0, $product->price);
        $this->assertSame(0, (int) $product->stock);
    }
}
