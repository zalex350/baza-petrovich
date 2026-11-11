<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Единые форматы в шаблонах: суммы — x-price / money(), даты — date_ru()
 * (списки без времени, карточки — со временем).
 */
class FormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_products_price_with_vat_label_and_dash_for_empty(): void
    {
        Product::create(['name' => 'Priced', 'sku' => 'P-1', 'price' => 1234.5, 'stock' => 1]);
        Product::create(['name' => 'Empty', 'sku' => 'P-2', 'price' => null, 'stock' => 1]);

        $html = $this->actingAs(User::factory()->admin()->create())->get('/admin/products')->assertOk()->getContent();

        $this->assertStringContainsString("1\u{00A0}234,50\u{00A0}BYN", $html);
        $this->assertMatchesRegularExpression('#data-price>\s*1\x{00A0}234,50\x{00A0}BYN <span[^>]*>без НДС</span>#u', $html);
        $this->assertMatchesRegularExpression('#data-price-tbd>—</span>#u', $html);
        $this->assertStringNotContainsString('Цена уточняется', $html);
    }

    public function test_admin_user_card_dates_have_time(): void
    {
        Carbon::setTestNow('2026-03-05 14:07:00');
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id, 'blocked_at' => now()]);

        $this->actingAs(User::factory()->admin()->create())->get("/admin/user/{$client->id}")
            ->assertOk()->assertSee('с 05.03.2026 17:07')->assertSee('05.03.2026 17:07'); // 14:07 UTC — 17:07 по Минску
    }
}
