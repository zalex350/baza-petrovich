<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Форма товара по тесту 29.10:
 * F-6 — цена нового товара обязательна и больше 0 при любой кнопке;
 * F-7 — проверка только серверная (novalidate), ошибки у полей и общий alert;
 * бейджи ХИТ / НОВИНКА / Акция у существующего товара — сразу по галочке (POST …/badge).
 */
class AdminProductFormFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.catalog.manual_products' => true]);

        $this->admin = User::factory()->admin()->create();
    }

    private function newProduct(array $overrides = []): array
    {
        return array_merge(['name' => 'Grohe Start', 'sku' => 'NEW-1', 'price' => 100, 'stock' => 0], $overrides);
    }

    public static function priceCases(): array
    {
        $cases = [];

        foreach (['draft', 'preview', 'publish'] as $action) {
            $cases["$action, пусто"] = [$action, ''];
            $cases["$action, 0"] = [$action, '0'];
            $cases["$action, 0.00"] = [$action, '0.00'];
        }

        return $cases;
    }

    // --- F-6 ---

    #[DataProvider('priceCases')]
    public function test_new_product_without_price_is_not_created(string $action, string $price): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/product/create')
            ->post('/admin/product', $this->newProduct(['price' => $price, 'action' => $action]))
            ->assertRedirect('/admin/product/create')
            ->assertSessionHasErrors('price');

        $this->assertSame(0, Product::count());
    }

    public function test_new_product_with_price_is_published(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/product', $this->newProduct(['price' => '0.01', 'action' => 'publish']))
            ->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertEquals(0.01, $product->price);
        $this->assertTrue((bool) $product->is_published);
    }

    public function test_create_form_does_not_prefill_zero_price(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/product/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<input[^>]*name="price"[^>]*required#', $html);
        $this->assertDoesNotMatchRegularExpression('#<input[^>]*name="price"[^>]*value=#', $html);
        $this->assertStringContainsString('Обязательна, больше 0.', $html);
    }

    // --- F-7 ---

    public function test_form_is_validated_by_server_only(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/product/create')->getContent();

        $this->assertMatchesRegularExpression('#<form\s[^>]*id="product-form"[^>]*\snovalidate#s', $html);
    }

    public function test_errors_are_shown_under_fields_with_summary_alert(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/product/create')
            ->post('/admin/product', $this->newProduct(['name' => '', 'price' => '0', 'action' => 'publish']));

        $html = $this->actingAs($this->admin)->get('/admin/product/create')->getContent();

        $this->assertStringContainsString('data-validation-summary', $html);
        $this->assertStringContainsString('Проверьте выделенные поля', $html);
        $this->assertStringContainsString('id="f-name-error"', $html);
        $this->assertStringContainsString('id="f-price-error"', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="price"[^>]*aria-invalid="true"#', $html);
        // Текст ошибки — один раз, у поля (не списком сверху)
        $this->assertSame(1, substr_count($html, 'Заполните поле «Название»'));
        // Введённое сохранилось
        $this->assertStringContainsString('value="NEW-1"', $html);
    }

    public function test_existing_product_error_keeps_field_text(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 10, 'stock' => 1]);
        Product::create(['name' => 'B', 'sku' => 'B-1', 'price' => 10, 'stock' => 1]);

        $this->actingAs($this->admin)
            ->from("/admin/product/{$product->id}")
            ->post("/admin/product/{$product->id}", ['name' => 'A', 'sku' => 'B-1', 'price' => 10, 'stock' => 1, 'action' => 'draft'])
            ->assertSessionHasErrors('sku');

        $html = $this->actingAs($this->admin)->get("/admin/product/{$product->id}")->getContent();

        $this->assertStringContainsString('id="f-sku-error"', $html);
        $this->assertStringContainsString('data-validation-summary', $html);
    }

    // --- Бейджи ---

    public function test_badge_is_saved_immediately(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 10, 'stock' => 1]);

        foreach (['is_hit', 'is_promo', 'is_new'] as $field) {
            $this->actingAs($this->admin)
                ->postJson("/admin/product/{$product->id}/badge", ['field' => $field, 'value' => true])
                ->assertOk()
                ->assertExactJson(['message' => 'Сохранено', 'value' => true]);

            $this->assertTrue($product->fresh()->{$field});
        }

        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_hit', 'value' => false])
            ->assertExactJson(['message' => 'Сохранено', 'value' => false]);

        $this->assertFalse($product->fresh()->is_hit);
        // Черновик бейджи не создают
        $this->assertNull($product->fresh()->draft);
    }

    public function test_badge_rejects_other_fields(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 10, 'stock' => 1]);

        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_published', 'value' => false])
            ->assertUnprocessable();
        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_hit', 'value' => 'yes'])
            ->assertUnprocessable();

        $this->assertTrue((bool) $product->fresh()->is_published);
        $this->assertFalse($product->fresh()->is_hit);
    }

    public function test_badge_requires_admin(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 10, 'stock' => 1]);

        $this->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_hit', 'value' => true])->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_hit', 'value' => true])
            ->assertForbidden();

        $this->assertFalse($product->fresh()->is_hit);
    }

    public function test_badge_does_not_touch_draft_or_other_fields(): void
    {
        $product = Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 10, 'stock' => 1, 'is_new' => true]);
        $product->draft()->create(['data' => ['description' => 'Черновое']]);

        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_promo', 'value' => true])
            ->assertOk();

        $product->refresh();
        $this->assertTrue($product->is_promo);
        $this->assertTrue($product->is_new);
        $this->assertFalse($product->is_new_locked);
        $this->assertSame('Черновое', $product->draft->data['description']);
    }

    public function test_new_product_badges_are_saved_with_product(): void
    {
        $html = $this->actingAs($this->admin)->get('/admin/product/create')->getContent();

        // У нового товара бейджи — часть формы (скрытое «0» тоже)
        $this->assertStringContainsString('<input type="hidden" name="is_hit" value="0" form="product-form">', $html);
        $this->assertStringContainsString('Сохраняются вместе с товаром', $html);
        $this->assertStringNotContainsString('productBadges(', $html);

        $this->actingAs($this->admin)
            ->post('/admin/product', $this->newProduct(['is_hit' => '1', 'is_promo' => '0', 'action' => 'draft']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Product::sole()->is_hit);
        $this->assertFalse(Product::sole()->is_promo);
    }
}
