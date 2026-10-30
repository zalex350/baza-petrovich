<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Форма товара: кратность заказа в блоке «1С» (min_qty, qty_step, default_qty)
 * и бейджи ХИТ / НОВИНКА в блоке «Сайт».
 */
class AdminProductQtyBadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.catalog.manual_products' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::create(['name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 5, 'is_published' => true]);
    }

    private function edit(array $overrides = []): array
    {
        return array_merge([
            'action' => 'draft',
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'price' => 100,
            'stock' => 5,
        ], $overrides);
    }

    public function test_new_product_has_defaults(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 1, 'stock' => 0])->refresh();

        $this->assertSame([1, 1, 1], [$product->min_qty, $product->qty_step, $product->default_qty]);
        $this->assertFalse($product->is_hit);
        $this->assertFalse($product->is_new);
    }

    public function test_form_shows_qty_rules_in_erp_block_and_badges_in_site_block(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()->getContent();

        $erp = substr($html, strpos($html, 'data-erp-block'), strpos($html, 'data-site-block') - strpos($html, 'data-erp-block'));
        $site = substr($html, strpos($html, 'data-site-block'));

        foreach (['min_qty', 'qty_step', 'default_qty'] as $field) {
            $this->assertMatchesRegularExpression('#<input[^>]*name="'.$field.'"[^>]*form="product-form"#s', $erp, $field);
        }

        // Бейджи сохраняются сразу по галочке (POST …/badge), в форму товара не входят
        $this->assertStringContainsString("productBadges('/admin/product/{$this->product->id}/badge')", $site);
        $this->assertStringContainsString('Сохраняются сразу при отметке галочки', $site);

        foreach (['is_hit' => 'ХИТ', 'is_new' => 'НОВИНКА', 'is_promo' => 'Акция'] as $field => $label) {
            $this->assertStringContainsString($label, $site);
            $this->assertMatchesRegularExpression('#<input\s+type="checkbox"[^>]*name="'.$field.'"#', $site);
            $this->assertDoesNotMatchRegularExpression('#<input[^>]*name="'.$field.'"[^>]*form="product-form"#', $site);
        }
    }

    public function test_save_qty_rules_and_badges(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'min_qty' => 2, 'qty_step' => 2, 'default_qty' => 6, 'is_hit' => 1, 'is_new' => 0,
        ]))->assertSessionHasNoErrors();

        $this->product->refresh();

        $this->assertSame([2, 2, 6], [$this->product->min_qty, $this->product->qty_step, $this->product->default_qty]);
        $this->assertTrue($this->product->is_hit);
        $this->assertFalse($this->product->is_new);

        // Бейджи — сразу, без черновика
        $this->assertNull($this->product->draft);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit(['is_hit' => 0]));
        $this->assertFalse($this->product->refresh()->is_hit);
    }

    public function test_default_qty_must_be_reachable_from_min_by_step(): void
    {
        foreach ([1, 5] as $bad) {
            $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
                'min_qty' => 2, 'qty_step' => 2, 'default_qty' => $bad,
            ]))->assertSessionHasErrors('default_qty');
        }

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'min_qty' => 0, 'qty_step' => 0,
        ]))->assertSessionHasErrors(['min_qty', 'qty_step']);

        $this->assertSame(1, $this->product->refresh()->min_qty);
    }

    public function test_create_saves_qty_rules_and_badges(): void
    {
        $this->actingAs($this->admin)->post('/admin/product', [
            'action' => 'draft', 'name' => 'Новый', 'sku' => 'N-1', 'price' => 10, 'stock' => 0,
            'min_qty' => 3, 'qty_step' => 3, 'default_qty' => 3, 'is_hit' => 0, 'is_new' => 1,
        ])->assertSessionHasNoErrors();

        $product = Product::where('sku', 'N-1')->firstOrFail();

        $this->assertSame([3, 3, 3], [$product->min_qty, $product->qty_step, $product->default_qty]);
        $this->assertTrue($product->is_new);
    }

    public function test_without_manual_products_qty_rules_are_read_only_but_badges_save(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*name="min_qty"[^>]*\sdisabled[\s>=]#s', $html);
        $this->assertDoesNotMatchRegularExpression('#<input\s+type="checkbox"[^>]*name="is_hit"[^>]*\sdisabled[\s>=]#s', $html);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", [
            'action' => 'draft', 'min_qty' => 10, 'qty_step' => 10, 'default_qty' => 10, 'is_hit' => 1,
        ])->assertSessionHasNoErrors();

        $this->product->refresh();

        $this->assertSame(1, $this->product->min_qty);
        $this->assertTrue($this->product->is_hit);
    }
}
