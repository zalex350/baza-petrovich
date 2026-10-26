<?php

namespace Tests\Feature\CatalogImport;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сайт и админка под импорт каталога: логистика, ТН ВЭД, сгенерированные описания,
 * флаги характеристик, служебная категория.
 */
class CatalogImportSiteTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Смеситель', 'sku' => 'LEDEME-A1', 'price' => 10, 'stock' => 5,
            'is_active' => true, 'is_published' => true, 'description' => 'Сгенерированное описание товара',
        ]);
    }

    private function value(string $name, string $value, array $flags = []): void
    {
        $attribute = ProductAttribute::create(['name' => $name] + $flags);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $attribute->id, 'value' => $value, 'sort_order' => 0]);
    }

    public function test_logistics_is_collapsed_block_and_tn_ved_is_admin_only(): void
    {
        $this->value('Цвет', 'хром');
        $this->value('Вес', '2,6', ['unit' => 'кг', 'is_logistics' => true]);
        $this->value('Код ТН ВЭД', '8481909000', ['is_admin_only' => true]);

        $html = $this->get("/product/{$this->product->id}")->assertOk()->getContent();

        $specs = substr($html, strpos($html, 'data-specs'), strpos($html, 'data-logistics') - strpos($html, 'data-specs'));
        $this->assertStringContainsString('Цвет', $specs);
        $this->assertStringNotContainsString('Вес', $specs);

        $this->assertMatchesRegularExpression('#<details[^>]*data-logistics>\s*<summary[^>]*>\s*Упаковка и логистика#', $html);
        $this->assertStringContainsString('2,6 кг', $html);
        $this->assertStringNotContainsString('8481909000', $html);

        // В админке (форма товара) — все характеристики
        $this->actingAs(User::factory()->admin()->create())->get("/admin/product/{$this->product->id}")
            ->assertOk()->assertSee('8481909000');
    }

    public function test_generated_description_is_hidden_until_approved(): void
    {
        $this->product->update(['description_generated' => true]);

        $this->get("/product/{$this->product->id}")->assertOk()->assertDontSee('Сгенерированное описание товара');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Описание сгенерировано — на сайте не показывается')
            ->assertSee('action="/admin/product/'.$this->product->id.'/approve-description"', false);

        $this->actingAs($admin)->post("/admin/product/{$this->product->id}/approve-description")
            ->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($this->product->refresh()->description_generated);
        $this->get("/product/{$this->product->id}")->assertSee('Сгенерированное описание товара');

        // Клиенту одобрять нельзя
        $this->product->update(['description_generated' => true]);
        $this->actingAs(User::factory()->create())->post("/admin/product/{$this->product->id}/approve-description")->assertForbidden();
        $this->assertTrue($this->product->refresh()->description_generated);
    }

    public function test_admin_filter_unapproved_description(): void
    {
        $this->product->update(['description_generated' => true]);
        Product::create(['name' => 'Approved', 'sku' => 'LEDEME-A2', 'price' => 10, 'stock' => 5, 'description' => 'Текст']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/products')
            ->assertSee('href="/admin/products?description=unapproved"', false)->assertSee('Описание не одобрено (1)');
        $this->actingAs($admin)->get('/admin/products?description=unapproved')
            ->assertOk()->assertSee('Описание не одобрено: 1')->assertSee('LEDEME-A1')->assertDontSee('LEDEME-A2');

        // Одобрили — из фильтра ушёл, кнопки нет
        $this->actingAs($admin)->post("/admin/product/{$this->product->id}/approve-description");
        $this->actingAs($admin)->get('/admin/products')->assertDontSee('description=unapproved');
    }

    public function test_attribute_form_flags_and_number_with_text_values(): void
    {
        $admin = User::factory()->admin()->create();
        $attribute = ProductAttribute::create(['name' => 'Рабочее давление', 'unit' => 'бар', 'type' => 'number']);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $attribute->id, 'value' => '6 бар (10 для испытания)', 'value_number' => 6, 'sort_order' => 0]);

        $this->actingAs($admin)->get("/admin/attribute/{$attribute->id}/edit")
            ->assertOk()->assertSee('Упаковка и логистика')->assertSee('Только в админке');

        // Уже числовая с текстовыми значениями из импорта — сохраняется, значения не трогаются
        $this->actingAs($admin)->post("/admin/attribute/{$attribute->id}", [
            'name' => 'Рабочее давление', 'unit' => 'бар', 'type' => 'number', 'is_logistics' => 1, 'is_admin_only' => 0,
        ])->assertRedirect('/admin/attributes')->assertSessionHasNoErrors();

        $attribute->refresh();
        $this->assertTrue($attribute->is_logistics);
        $this->assertFalse($attribute->is_admin_only);
        $this->assertSame(6.0, (float) $attribute->values()->sole()->value_number);
    }

    public function test_product_without_name_shows_brand_and_article(): void
    {
        $noName = Product::create(['name' => 'LEDEME-H35', 'sku' => 'LEDEME-H35', 'brand' => 'LEDEME', 'price' => 5, 'stock' => 1, 'is_active' => true, 'is_published' => true]);
        $noBrand = Product::create(['name' => '', 'sku' => 'GG-NLDZ', 'price' => 5, 'stock' => 1, 'is_active' => true, 'is_published' => true]);

        $this->assertSame('LEDEME H35', $noName->title());
        $this->assertFalse($noName->hasRealName());
        $this->assertSame('GG-NLDZ', $noBrand->title());
        $this->assertSame('Смеситель', $this->product->title());
        $this->assertTrue($this->product->hasRealName());

        $this->get("/product/{$noName->id}")->assertOk()->assertSee('<h1', false)->assertSee('LEDEME H35');

        // Админка: «БРЕНД артикул» с пометкой и фильтр «Без названия»
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/products')
            ->assertSee('LEDEME H35')->assertSee('без названия')->assertSee('Без названия (2)');
        $this->actingAs($admin)->get('/admin/products?name=none')
            ->assertOk()->assertSee('LEDEME H35')->assertSee('GG-NLDZ')->assertDontSee('LEDEME-A1');
    }

    public function test_hidden_category_breadcrumbs_and_menu(): void
    {
        $hidden = Category::create(['name' => 'Без категории', 'slug' => 'bez-kategorii', 'is_hidden' => true]);
        $this->product->update(['category_id' => $hidden->id]);

        $this->get("/product/{$this->product->id}")->assertOk()
            ->assertDontSee('href="/catalog/bez-kategorii"', false);
        $this->get('/catalog/bez-kategorii')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Без категории');
    }
}
