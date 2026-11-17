<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Product;
use App\Services\Pricing\Discounts;
use App\Services\Vat\VatCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Розница: цены с НДС видны гостю, скидки товара и категории, «Узнать цену».
 */
class RetailPricesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Профильная труба', 'slug' => 'truba']);
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        $unit = $attributes['unit'] ?? 'м.п.';
        unset($attributes['unit']);

        // unit — поле 1С (не в $fillable)
        return tap(Product::create(array_merge([
            'name' => "Труба {$n}",
            'sku' => "TR-{$n}",
            'price' => 10,
            'stock' => 5,
            'category_id' => $this->category->id,
            'is_active' => true,
            'is_published' => true,
        ], $attributes)), fn (Product $p) => $p->forceFill(['unit' => $unit])->save());
    }

    public function test_vat_is_extracted_from_retail_price(): void
    {
        $line = app(VatCalculator::class)->line(12, 3, 20);

        $this->assertSame(36.0, $line->gross);
        $this->assertSame(6.0, $line->vat);
        $this->assertSame(30.0, $line->net);
        $this->assertSame(12.0, $this->product(['price' => 12])->price_with_vat);
    }

    public function test_guest_sees_price_stock_and_buy_button(): void
    {
        $product = $this->product(['name' => 'Труба 40x20x2', 'price' => 5.67]);

        foreach (['/catalog/truba', '/search?q=40x20', "/product/{$product->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee("5,67\u{00A0}BYN", false)
                ->assertSee('за м.п.')
                ->assertSee('data-stock=', false)
                ->assertSee('action="/order/add/'.$product->id.'"', false)
                ->assertDontSee('Цена и наличие — после входа')
                ->assertDontSee('Войдите, чтобы заказать');
        }
    }

    public function test_product_discount_percent_and_amount(): void
    {
        $percent = $this->product(['price' => 100, 'discount_percent' => 15]);
        $amount = $this->product(['price' => 100, 'discount_amount' => 7.5, 'discount_percent' => 50]);
        $none = $this->product(['price' => 100]);

        $this->assertEquals(85, $percent->fresh()->sale_price);
        $this->assertEquals(85, $percent->fresh()->price);
        $this->assertSame(100.0, $percent->fresh()->old_price);
        $this->assertSame(15, $percent->fresh()->discount_badge);

        // Сумма важнее процента
        $this->assertEquals(92.5, $amount->fresh()->price);

        $this->assertNull($none->fresh()->sale_price);
        $this->assertNull($none->fresh()->old_price);

        $this->get("/product/{$percent->id}")
            ->assertSee("85,00\u{00A0}BYN", false)
            ->assertSee('data-old-price', false)
            ->assertSee('−15%');
    }

    public function test_category_discount_applies_to_subcategories_unless_product_has_own(): void
    {
        $child = Category::create(['name' => 'Труба 1 сорт', 'slug' => 'truba-1', 'parent_id' => $this->category->id]);
        $inChild = $this->product(['price' => 200, 'category_id' => $child->id]);
        $own = $this->product(['price' => 200, 'category_id' => $child->id, 'discount_percent' => 5]);

        $this->category->update(['discount_percent' => 10]);

        $this->assertEquals(180, $inChild->fresh()->price);
        $this->assertEquals(190, $own->fresh()->price);

        $this->category->update(['discount_percent' => null]);
        $this->assertNull($inChild->fresh()->sale_price);

        // Новый товар в категории со скидкой — сразу со скидкой
        $child->update(['discount_percent' => 20]);
        $this->assertEquals(80, $this->product(['price' => 100, 'category_id' => $child->id])->fresh()->price);
    }

    public function test_sort_by_price_uses_discounted_price(): void
    {
        $this->product(['name' => 'Дорогая', 'price' => 50, 'discount_percent' => 90]); // 5,00
        $this->product(['name' => 'Средняя', 'price' => 20]);

        $this->get('/catalog/truba?sort=price_asc')->assertOk()->assertSeeInOrder(['Дорогая', 'Средняя']);
    }

    public function test_recalculate_after_bulk_price_change(): void
    {
        $product = $this->product(['price' => 100, 'discount_percent' => 10]);

        \DB::table('products')->where('id', $product->id)->update(['price' => 200]);
        app(Discounts::class)->recalculate();

        $this->assertEquals(180, $product->fresh()->sale_price);
    }

    public function test_product_without_price_has_price_request(): void
    {
        $product = $this->product(['price' => null]);

        $this->get("/product/{$product->id}")
            ->assertOk()
            ->assertSee('Цена по запросу')
            ->assertSee(route('lead.price', $product->id), false);

        $this->get(route('lead.price', $product->id))->assertOk()->assertSee($product->title());

        $this->post(route('lead.store'), [
            'type' => 'price',
            'product_id' => $product->id,
            'name' => 'Иван',
            'phone' => '+375 29 111-22-33',
            'comment' => '20 м',
            'consent' => '1',
        ])->assertSessionHas('lead_sent');

        $this->assertDatabaseHas('leads', ['type' => 'price', 'product_id' => $product->id, 'subject' => $product->title(), 'phone' => '+375 29 111-22-33']);
    }

    public function test_lead_requires_phone_and_consent(): void
    {
        $this->post(route('lead.store'), ['type' => 'callback', 'name' => 'Иван'])
            ->assertSessionHasErrors(['phone', 'consent']);

        $this->assertDatabaseCount('leads', 0);
    }
}
