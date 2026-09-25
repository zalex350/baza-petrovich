<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function makeProduct(bool $active): Product
    {
        return Product::create([
            'name'      => 'Grohe Start',
            'sku'       => 'GR-001',
            'price'     => 100,
            'stock'     => 5,
            'is_active' => $active,
        ]);
    }

    public function test_toggle_requires_auth(): void
    {
        $product = $this->makeProduct(true);

        $this->post("/admin/product/{$product->id}/toggle-visibility")
            ->assertRedirect('/login');

        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    public function test_toggle_requires_admin_role(): void
    {
        $product = $this->makeProduct(true);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->post("/admin/product/{$product->id}/toggle-visibility")
            ->assertStatus(403);

        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    public function test_admin_can_hide_visible_product(): void
    {
        $product = $this->makeProduct(true);

        $this->actingAs($this->admin)
            ->from('/admin/products')
            ->post("/admin/product/{$product->id}/toggle-visibility")
            ->assertRedirect('/admin/products')
            ->assertSessionHas('success', 'Товар скрыт с сайта');

        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    public function test_admin_can_show_hidden_product(): void
    {
        $product = $this->makeProduct(false);

        $this->actingAs($this->admin)
            ->from("/admin/product/{$product->id}")
            ->post("/admin/product/{$product->id}/toggle-visibility")
            ->assertRedirect("/admin/product/{$product->id}")
            ->assertSessionHas('success', 'Товар показан на сайте');

        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    public function test_toggle_missing_product_returns_404(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/product/999999/toggle-visibility')
            ->assertStatus(404);
    }

    public function test_saving_edit_form_does_not_change_visibility(): void
    {
        $product = $this->makeProduct(true);

        $this->actingAs($this->admin)->post("/admin/product/{$product->id}", [
            'name'  => 'Renamed',
            'sku'   => 'GR-001',
            'price' => 120,
            'stock' => 3,
        ]);

        $product->refresh();
        $this->assertSame('Renamed', $product->name);
        $this->assertTrue((bool) $product->is_active);
    }

    public function test_edit_page_shows_hide_button_for_visible_product(): void
    {
        $product = $this->makeProduct(true);

        $this->actingAs($this->admin)
            ->get("/admin/product/{$product->id}")
            ->assertStatus(200)
            ->assertSee('Виден на сайте')
            ->assertSee('Скрыть товар')
            ->assertDontSee('Показать товар')
            ->assertDontSee('name="is_active"', false);
    }

    public function test_edit_page_shows_show_button_for_hidden_product(): void
    {
        $product = $this->makeProduct(false);

        $this->actingAs($this->admin)
            ->get("/admin/product/{$product->id}")
            ->assertStatus(200)
            ->assertSee('Скрыт с сайта')
            ->assertSee('Показать товар')
            ->assertDontSee('Скрыть товар');
    }

    public function test_products_list_has_toggle_action(): void
    {
        $visible = $this->makeProduct(true);
        $hidden = Product::create([
            'name'      => 'Hansgrohe Logis',
            'sku'       => 'HG-001',
            'price'     => 200,
            'stock'     => 1,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/products')
            ->assertStatus(200)
            ->assertSee("/admin/product/{$visible->id}/toggle-visibility")
            ->assertSee("/admin/product/{$hidden->id}/toggle-visibility")
            ->assertSee('Скрыть')
            ->assertSee('Показать');
    }
}
