<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * products.guid — GUID номенклатуры 1С (docs/CATALOG_ANALYSIS.md, «Решения», п. 1).
 */
class ProductGuidTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(['name' => $sku, 'sku' => $sku, 'price' => 1, 'stock' => 0] + $attributes);
    }

    public function test_guid_is_optional_and_not_mass_assignable(): void
    {
        // Без GUID — сколько угодно товаров (NULL не участвует в уникальности)
        $a = $this->product('A', ['guid' => 'from-form']);
        $b = $this->product('B');

        $this->assertNull($a->refresh()->guid);
        $this->assertNull($b->refresh()->guid);
    }

    public function test_guid_is_unique(): void
    {
        $a = $this->product('A');
        $a->forceFill(['guid' => '6f1c2a3e-0000-4000-8000-000000000001'])->save();

        $this->assertSame('6f1c2a3e-0000-4000-8000-000000000001', $a->refresh()->guid);

        $this->expectException(QueryException::class);
        $this->product('B')->forceFill(['guid' => '6f1c2a3e-0000-4000-8000-000000000001'])->save();
    }
}
