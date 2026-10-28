<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Комплектация» — один источник: поле товара, если пусто — характеристика «Комплектация»
 * (из импорта). В таблице характеристик она не дублируется; в админке — пометка источника.
 */
class ProductPackageContentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->product = Product::create([
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'price' => 100,
            'stock' => 5,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true,
            'is_published' => true,
        ]);

        $color = ProductAttribute::create(['name' => 'Цвет']);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $color->id, 'value' => 'Хром', 'sort_order' => 0]);
    }

    private function packageAttribute(string $value): void
    {
        $attribute = ProductAttribute::create(['name' => 'Комплектация']);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $attribute->id, 'value' => $value, 'sort_order' => 1]);
    }

    private function card(): string
    {
        return $this->actingAs($this->client)->get("/product/{$this->product->id}")->assertOk()->getContent();
    }

    public function test_attribute_is_shown_in_package_section_when_field_is_empty(): void
    {
        $long = trim(str_repeat('Смеситель, гибкая подводка 2 шт., крепёж, инструкция; ', 6));
        $this->packageAttribute($long);

        $html = $this->card();

        $this->assertMatchesRegularExpression('#data-package>\s*'.preg_quote(e($long), '#').'\s*</p>#u', $html);
        // В таблице характеристик — только «Цвет», «Комплектация» не дублируется
        $this->assertSame(1, substr_count($html, 'Комплектация</'));
        $this->assertStringContainsString('Хром', $html);
    }

    public function test_field_wins_over_attribute(): void
    {
        $this->packageAttribute('Из импорта');
        $this->product->update(['package_contents' => "Смеситель\nШланг"]);

        $html = $this->card();

        $this->assertStringContainsString("Смеситель\nШланг", $html);
        $this->assertStringNotContainsString('Из импорта', $html);
        $this->assertSame(['text' => "Смеситель\nШланг", 'source' => 'field'], $this->product->fresh()->packageContents());
    }

    public function test_no_section_without_field_and_attribute(): void
    {
        $this->assertStringNotContainsString('data-package', $this->card());
        $this->assertSame(['text' => '', 'source' => null], $this->product->packageContents());
    }

    public function test_admin_form_marks_source_of_package_contents(): void
    {
        $this->packageAttribute('Смеситель, подводка');

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-package-source="attribute"', false)
            ->assertSee('на сайте показывается характеристика «Комплектация»')
            ->assertSee('data-package-attribute-note', false);

        $this->product->update(['package_contents' => 'Своя']);

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertSee('data-package-source="field"', false)
            ->assertSee('Характеристика «Комплектация» (из импорта) не показывается.');
    }

    public function test_long_attribute_value_is_editable_in_full_in_admin(): void
    {
        $long = str_repeat('а', 279);
        $this->packageAttribute($long);

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('>'.$long.'</textarea>', false);
    }
}
