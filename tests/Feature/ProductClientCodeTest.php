<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Коды товаров клиентов (product_client_codes) и снимок кода в позиции заказа — только структура.
 */
class ProductClientCodeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->product = $this->product('GR-001');
    }

    private function product(string $sku): Product
    {
        return Product::create(['name' => "Товар {$sku}", 'sku' => $sku, 'price' => 10, 'stock' => 1]);
    }

    private function code(Product $product, Organization $org, string $code): ProductClientCode
    {
        return ProductClientCode::create(['product_id' => $product->id, 'organization_id' => $org->id, 'code' => $code]);
    }

    public function test_relations_and_code_for(): void
    {
        $code = $this->code($this->product, $this->org, 'КЛ-17');
        $this->code($this->product, $this->other, 'X-1');

        $this->assertTrue($code->product->is($this->product));
        $this->assertTrue($code->organization->is($this->org));
        $this->assertCount(2, $this->product->clientCodes);
        $this->assertSame(['КЛ-17'], $this->org->productCodes()->pluck('code')->all());

        $this->assertSame('КЛ-17', $this->product->codeFor($this->org));
        $this->assertSame('X-1', $this->product->codeFor($this->other));
        $this->assertNull($this->product->codeFor(Organization::factory()->create()));
        $this->assertNull($this->product->codeFor(null));
    }

    public function test_one_code_per_product_for_organization(): void
    {
        $this->code($this->product, $this->org, 'A');

        $this->expectException(QueryException::class);
        $this->code($this->product, $this->org, 'B');
    }

    public function test_code_is_unique_within_organization_but_not_across(): void
    {
        $this->code($this->product, $this->org, 'A');

        // Тот же код у другой организации — можно
        $this->code($this->product, $this->other, 'A');
        $this->assertSame(2, ProductClientCode::where('code', 'A')->count());

        $this->expectException(QueryException::class);
        $this->code($this->product('GR-002'), $this->org, 'A');
    }

    public function test_codes_are_deleted_with_product_or_organization(): void
    {
        $this->code($this->product, $this->org, 'A');
        $second = $this->product('GR-002');
        $this->code($second, $this->other, 'B');

        // Каскад — при физическом удалении (soft delete товара коды не трогает)
        $this->product->forceDelete();
        $this->assertSame(['B'], ProductClientCode::pluck('code')->all());

        $this->other->delete();
        $this->assertSame(0, ProductClientCode::count());
    }

    public function test_list_loads_codes_of_one_organization_without_n_plus_one(): void
    {
        $products = collect([$this->product, $this->product('GR-002'), $this->product('GR-003')]);

        foreach ($products as $i => $product) {
            $this->code($product, $this->org, "ORG-{$i}");
            $this->code($product, $this->other, "OTHER-{$i}");
        }

        // Запрос списка: коды подгружены одним запросом, только своей организации
        $list = Product::query()->withClientCodeFor($this->org)->orderBy('id')->get();

        DB::enableQueryLog();
        $codes = $list->map(fn (Product $p) => $p->codeFor($this->org))->all();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(['ORG-0', 'ORG-1', 'ORG-2'], $codes);
        $this->assertTrue($list->every(fn (Product $p) => $p->clientCodes->count() === 1));

        // Уже загруженные (страница пагинатора): один запрос на всех
        $page = Product::query()->orderBy('id')->paginate(10);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Product::loadClientCodesFor($page, $this->other);
        $codes = collect($page->items())->map(fn (Product $p) => $p->codeFor($this->other))->all();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame(['OTHER-0', 'OTHER-1', 'OTHER-2'], $codes);
    }

    public function test_order_item_keeps_client_code_snapshot(): void
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'organization_id' => $this->org->id]);

        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'price' => 10,
            'client_code' => 'КЛ-17',
        ]);

        // Снимок не зависит от дальнейших изменений кода
        $this->assertSame('КЛ-17', $item->refresh()->client_code);
        $this->assertNull(OrderItem::create(['order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'price' => 10])->refresh()->client_code);
    }
}
