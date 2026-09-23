<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDeleteProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->product = Product::create([
            'name'        => 'Grohe Start',
            'sku'         => 'GR-001',
            'price'       => 199.99,
            'stock'       => 5,
            'category_id' => $category->id,
            'is_active'   => true,
        ]);
    }

    public function test_admin_can_delete_product(): void
    {
        $id = $this->product->id;

        $response = $this->actingAs($this->admin)
            ->post("/admin/product/{$id}/delete");

        $response->assertRedirect('/admin/products');

        // Мягкое удаление: из обычной выборки пропадает
        $this->assertNull(Product::find($id));

        // Но физически остаётся в БД с заполненным deleted_at
        $trashed = Product::withTrashed()->find($id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    public function test_deleted_product_returns_404(): void
    {
        $id = $this->product->id;

        $this->actingAs($this->admin)->post("/admin/product/{$id}/delete");

        $response = $this->actingAs($this->admin)->get("/admin/product/{$id}");

        $response->assertStatus(404);
    }

    public function test_non_admin_cannot_delete_product(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $id   = $this->product->id;

        $response = $this->actingAs($user)->post("/admin/product/{$id}/delete");

        $response->assertStatus(403);
        $this->assertNotNull(Product::find($id));
    }

    public function test_guest_cannot_delete_product(): void
    {
        $id = $this->product->id;

        $response = $this->post("/admin/product/{$id}/delete");

        $response->assertRedirect('/login');
        $this->assertNotNull(Product::find($id));
    }

    public function test_delete_nonexistent_product_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product/99999/delete');

        $response->assertStatus(404);
    }
}
