<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Товар из импорта каталога — без цены (цена придёт из 1С). Форма товара в админке должна
 * сохраняться и у него: флаги ХИТ / НОВИНКА / Акция, категория, тексты. Раньше пустая цена
 * блокировала отправку (required у поля) и отклонялась сервером — флаги не сохранялись.
 */
class AdminImportedProductSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $imported;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.catalog.manual_products' => true]);

        $this->admin = User::factory()->admin()->create();

        // Как создаёт CatalogImportApplier: без цены, остаток 0, черновик
        $this->imported = Product::create([
            'name' => 'Смеситель для кухни L4755-2',
            'sku' => 'LEDEME-L4755-2',
            'brand' => 'LEDEME',
            'price' => null,
            'stock' => 0,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true,
            'is_published' => false,
        ]);
    }

    /**
     * Поля формы так, как их отправит браузер (все элементы с form="product-form").
     */
    private function browserFields(string $html, array $check = []): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);
        $fields = [];

        foreach ($xpath->query('//*[@form="product-form"][@name]') as $el) {
            $name = $el->getAttribute('name');
            $type = $el->getAttribute('type');

            if ($el->hasAttribute('disabled') || $type === 'file' || $el->nodeName === 'button') {
                continue;
            }
            if ($type === 'checkbox' && ! $el->hasAttribute('checked') && ! in_array($name, $check, true)) {
                continue;
            }

            $value = match ($el->nodeName) {
                'textarea' => $el->textContent,
                'select' => (fn ($o) => $o ? $o->getAttribute('value') : '')($xpath->query('.//option[@selected]', $el)->item(0)),
                default => $el->getAttribute('value'),
            };

            str_ends_with($name, '[]') ? $fields[substr($name, 0, -2)][] = $value : $fields[$name] = $value;
        }

        return $fields;
    }

    public function test_price_field_does_not_block_submit_for_product_without_price(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->imported->id}")->assertOk()->getContent();

        // Пустая цена не должна мешать браузеру отправить форму
        $this->assertDoesNotMatchRegularExpression('#<input[^>]*name="price"[^>]*required#', $html);
        $this->assertStringContainsString('Цена уточняется', $html);
    }

    public function test_flags_are_saved_for_imported_product_without_price(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->imported->id}")->getContent();

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->imported->id}", $this->browserFields($html, ['is_hit', 'is_promo']) + ['action' => 'draft'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $product = $this->imported->fresh();
        $this->assertTrue($product->is_hit);
        $this->assertTrue($product->is_promo);
        // Цена осталась пустой — на витрине «Цена уточняется», заказать нельзя
        $this->assertNull($product->price);
    }

    public function test_flags_can_be_unchecked_again(): void
    {
        $this->imported->update(['is_hit' => true, 'is_promo' => true]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->imported->id}")->getContent();
        $fields = $this->browserFields($html);
        // Браузер не отправляет снятую галочку — уходит только скрытое поле «0»
        $fields['is_hit'] = '0';
        $fields['is_promo'] = '0';

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->imported->id}", $fields + ['action' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->imported->fresh()->is_hit);
        $this->assertFalse($this->imported->fresh()->is_promo);
    }

    public function test_product_with_price_and_new_product_still_require_price(): void
    {
        $priced = Product::create(['name' => 'С ценой', 'sku' => 'P-1', 'price' => 10, 'stock' => 1]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$priced->id}")->getContent();
        $this->assertMatchesRegularExpression('#<input[^>]*name="price"[^>]*required#', $html);

        $this->actingAs($this->admin)
            ->post("/admin/product/{$priced->id}", ['price' => ''] + $this->browserFields($html) + ['action' => 'draft'])
            ->assertSessionHasErrors('price');
        $this->assertEquals(10, $priced->fresh()->price);

        $this->actingAs($this->admin)->post('/admin/product', ['name' => 'Новый', 'sku' => 'N-1', 'stock' => 0])
            ->assertSessionHasErrors('price');
    }

    public function test_entered_price_is_still_validated(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->imported->id}")->getContent();

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->imported->id}", ['price' => '-5'] + $this->browserFields($html) + ['action' => 'draft'])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->imported->id}", ['price' => '12,50'] + $this->browserFields($html) + ['action' => 'draft'])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->imported->id}", ['price' => '12.50'] + $this->browserFields($html) + ['action' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertEquals(12.50, $this->imported->fresh()->price);
    }
}
