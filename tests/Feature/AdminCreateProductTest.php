<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreateProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->category = Category::create([
            'name' => 'Смесители',
            'slug' => 'smesiteli',
        ]);
    }

    public function test_create_form_requires_auth(): void
    {
        $response = $this->get('/admin/product/create');

        $response->assertRedirect('/login');
    }

    public function test_create_form_requires_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->get('/admin/product/create');

        $response->assertStatus(403);
    }

    public function test_admin_can_open_create_form(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/product/create');

        $response->assertStatus(200);
        $response->assertSee('Новый товар');
    }

    public function test_admin_can_create_product(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', [
            'name'        => 'Grohe Start',
            'sku'         => 'GR-001',
            'brand'       => 'Grohe',
            'price'       => 199.99,
            'stock'       => 5,
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();

        $product = Product::where('sku', 'GR-001')->first();

        $this->assertNotNull($product);
        $this->assertSame('Grohe Start', $product->name);
        $this->assertFalse((bool) $product->is_published);
    }

    public function test_new_product_is_created_as_draft(): void
    {
        $this->actingAs($this->admin)->post('/admin/product', [
            'name'  => 'Draft Product',
            'sku'   => 'DRF-001',
            'price' => 50.00,
            'stock' => 1,
        ]);

        $product = Product::where('sku', 'DRF-001')->first();

        $this->assertFalse((bool) $product->is_published);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', []);

        $response->assertSessionHasErrors(['name', 'sku', 'price', 'stock']);
    }

    public function test_store_validates_unique_sku(): void
    {
        Product::create([
            'name'  => 'Existing',
            'sku'   => 'GR-DUP',
            'price' => 10,
            'stock' => 0,
        ]);

        $response = $this->actingAs($this->admin)->post('/admin/product', [
            'name'  => 'New Product',
            'sku'   => 'GR-DUP',
            'price' => 10,
            'stock' => 0,
        ]);

        $response->assertSessionHasErrors(['sku']);
    }

    public function test_after_create_redirects_to_edit_page(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', [
            'name'  => 'Redirect Test',
            'sku'   => 'RDR-001',
            'price' => 10.00,
            'stock' => 0,
        ]);

        $product = Product::where('sku', 'RDR-001')->first();

        $response->assertRedirect("/admin/product/{$product->id}");
    }

    public function test_products_list_has_add_button(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/products');

        $response->assertStatus(200);
        $response->assertSee('/admin/product/create');
        $response->assertSee('Добавить товар');
    }
}
