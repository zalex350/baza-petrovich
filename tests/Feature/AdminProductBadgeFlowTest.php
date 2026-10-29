<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A-6: снятая в админке «Новинка» пропадает с карточки товара и не возвращается импортом
 * каталога (is_new_locked). Бейдж сохраняется сразу по галочке (POST …/badge); форма
 * отправляется так, как её отправил бы браузер: все поля с form="product-form", снятые галочки не уходят.
 */
class AdminProductBadgeFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Поля формы товара так, как их отправит браузер (снятые галочки и disabled — нет) */
    private function browserFields(string $html, array $uncheck): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $fields = [];

        foreach ((new \DOMXPath($dom))->query('//*[@form="product-form"][@name]') as $el) {
            $name = $el->getAttribute('name');
            $type = $el->getAttribute('type');

            if ($el->hasAttribute('disabled') || $type === 'file' || $el->nodeName === 'button') {
                continue;
            }
            if ($type === 'checkbox' && (! $el->hasAttribute('checked') || in_array($name, $uncheck, true))) {
                continue;
            }

            $value = match ($el->nodeName) {
                'textarea' => $el->textContent,
                'select' => (fn ($o) => $o ? $o->getAttribute('value') : '')((new \DOMXPath($dom))->query('.//option[@selected]', $el)->item(0)),
                default => $el->getAttribute('value'),
            };

            str_ends_with($name, '[]') ? $fields[substr($name, 0, -2)][] = $value : $fields[$name] = $value;
        }

        return $fields;
    }

    public static function modes(): array
    {
        return ['товары из 1С' => [false], 'товары вручную' => [true]];
    }

    #[DataProvider('modes')]
    public function test_unchecked_new_badge_disappears_from_product_page(bool $manual): void
    {
        config(['shop.catalog.manual_products' => $manual]);
        $admin = User::factory()->admin()->create();
        $product = Product::create(['name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 5,
            'is_active' => true, 'is_published' => true, 'is_new' => true]);

        $this->get("/product/{$product->id}")->assertSee('data-product-badge="new"', false);

        // Галочку сняли — сохраняется сразу, без кнопок формы
        $this->actingAs($admin)->get("/admin/product/{$product->id}")->assertOk();
        $this->actingAs($admin)->postJson("/admin/product/{$product->id}/badge", ['field' => 'is_new', 'value' => false])
            ->assertOk()
            ->assertExactJson(['message' => 'Сохранено', 'value' => false]);

        $this->assertFalse($product->fresh()->is_new);
        // Решение админа: импорт каталога «Новинку» больше не вернёт
        $this->assertTrue($product->fresh()->is_new_locked);
        $this->get("/product/{$product->id}")->assertDontSee('data-product-badge="new"', false);

        // Последующее сохранение формы любой кнопкой бейдж не возвращает
        $html = $this->actingAs($admin)->get("/admin/product/{$product->id}")->getContent();
        $this->actingAs($admin)->post("/admin/product/{$product->id}", $this->browserFields($html, []) + ['action' => 'publish'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_new);
    }

    public function test_save_without_badge_change_does_not_lock_it(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::create(['name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 5, 'is_new' => true]);

        $html = $this->actingAs($admin)->get("/admin/product/{$product->id}")->getContent();
        $this->actingAs($admin)->post("/admin/product/{$product->id}", $this->browserFields($html, []) + ['action' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($product->fresh()->is_new);
        $this->assertFalse($product->fresh()->is_new_locked);
    }
}
