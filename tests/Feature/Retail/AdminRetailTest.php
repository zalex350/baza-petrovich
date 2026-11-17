<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Админка розницы: скидки товара и категории, заявки с сайта.
 */
class AdminRetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::create(['name' => 'Профнастил', 'slug' => 'profnastil']);
        $this->product = Product::create([
            'name' => 'Профнастил С8', 'sku' => 'PN-8', 'price' => 30, 'stock' => 10,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_admin_sets_product_discount(): void
    {
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Розничная цена с НДС')
            ->assertSee('name="discount_percent"', false);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", [
            'name' => 'Профнастил С8', 'sku' => 'PN-8', 'price' => 30, 'vat_rate' => 20, 'stock' => 10,
            'discount_percent' => 10, 'discount_amount' => '',
        ])->assertRedirect();

        $this->assertEquals(27, $this->product->fresh()->sale_price);
    }

    public function test_admin_sets_category_discount(): void
    {
        $this->actingAs($this->admin)->post("/admin/category/{$this->category->id}", [
            'name' => 'Профнастил', 'discount_percent' => 20,
        ])->assertRedirect('/admin/categories');

        $this->assertEquals(20, $this->category->fresh()->discount_percent);
        $this->assertEquals(24, $this->product->fresh()->sale_price);
    }

    public function test_leads_list_and_status(): void
    {
        $lead = Lead::create(['type' => 'price', 'product_id' => $this->product->id, 'subject' => 'Профнастил С8', 'name' => 'Анна', 'phone' => '+375291234567']);

        $this->actingAs($this->admin)->get('/admin/leads')
            ->assertOk()
            ->assertSee('Узнать цену')
            ->assertSee('Анна')
            ->assertSee('Профнастил С8');

        $this->actingAs($this->admin)->post("/admin/lead/{$lead->id}/status", ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $lead->fresh()->status);
    }
}
