<?php

namespace Tests\Feature;

use App\Enums\CartProblemType;
use App\Enums\CartState;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CartValidationResult;
use App\Services\CartValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = Cart::create(['user_id' => User::factory()->create()->id]);
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name'         => "Смеситель {$sku}",
            'sku'          => $sku,
            'price'        => 100,
            'stock'        => 10,
            'is_active'    => true,
            'is_published' => true,
        ], $attributes));
    }

    private function inCart(Product $product, int $quantity = 1, float $priceAtAdd = 100): CartItem
    {
        return CartItem::create([
            'cart_id'      => $this->cart->id,
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'quantity'     => $quantity,
            'price_at_add' => $priceAtAdd,
        ]);
    }

    private function validate(): CartValidationResult
    {
        return app(CartValidator::class)->validate($this->cart->fresh()->load('items.product'));
    }

    /**
     * @return list<CartProblemType>
     */
    private function types(CartValidationResult $result, CartItem $item): array
    {
        return array_map(fn ($problem) => $problem->type, $result->for($item));
    }

    public function test_cart_without_problems_is_ready(): void
    {
        $item = $this->inCart($this->product('A-1'), 10);

        $result = $this->validate();

        $this->assertSame(CartState::Ready, $result->state());
        $this->assertTrue($result->isReady());
        $this->assertSame([], $result->for($item));
        $this->assertSame([], $result->messages());
        $this->assertEquals(1000, $result->total());
    }

    public function test_missing_and_empty_cart_are_empty(): void
    {
        $validator = app(CartValidator::class);

        $this->assertSame(CartState::Empty, $validator->validate(null)->state());
        $this->assertSame(CartState::Empty, $this->validate()->state());
        $this->assertFalse($this->validate()->isReady());
    }

    public function test_returns_all_problems_of_all_items_at_once(): void
    {
        $deleted     = $this->inCart($this->product('DEL'));
        $hidden      = $this->inCart($this->product('HID'));
        $unpublished = $this->inCart($this->product('UNP'));
        $noPrice     = $this->inCart($this->product('NOP'));
        $outOfStock  = $this->inCart($this->product('OUT'));
        $notEnough   = $this->inCart($this->product('LOW'), 5);
        $priceUp     = $this->inCart($this->product('PRC'));
        $both        = $this->inCart($this->product('BTH'), 5);
        $fine        = $this->inCart($this->product('OK'));

        $deleted->product->delete();
        $hidden->product->update(['is_active' => false]);
        $unpublished->product->update(['is_published' => false]);
        $noPrice->product->update(['price' => null]);
        $outOfStock->product->update(['stock' => 0]);
        $notEnough->product->update(['stock' => 3]);
        $priceUp->product->update(['price' => 120]);
        $both->product->update(['stock' => 2, 'price' => 80]);

        $result = $this->validate();

        $this->assertSame([CartProblemType::Missing], $this->types($result, $deleted));
        $this->assertSame([CartProblemType::Inactive], $this->types($result, $hidden));
        $this->assertSame([CartProblemType::Unpublished], $this->types($result, $unpublished));
        $this->assertSame([CartProblemType::NoPrice], $this->types($result, $noPrice));
        $this->assertSame([CartProblemType::OutOfStock], $this->types($result, $outOfStock));
        $this->assertSame([CartProblemType::InsufficientStock], $this->types($result, $notEnough));
        $this->assertSame([CartProblemType::PriceChanged], $this->types($result, $priceUp));
        $this->assertSame(
            [CartProblemType::InsufficientStock, CartProblemType::PriceChanged],
            $this->types($result, $both)
        );
        $this->assertSame([], $result->for($fine));

        $this->assertCount(9, $result->all());
        $this->assertSame(CartState::NeedsCorrection, $result->state());

        $this->assertContains('Смеситель DEL: товар больше недоступен', $result->messages());
        $this->assertContains('Смеситель LOW: недостаточно на складе: доступно 3, в корзине 5', $result->messages());
        $this->assertContains('Смеситель PRC: цена изменилась: было 100.00 BYN, стало 120.00 BYN', $result->messages());
    }

    public function test_only_price_changes_mean_needs_update(): void
    {
        $item = $this->inCart($this->product('A-1', ['price' => 150]), 2, 100);
        $this->inCart($this->product('B-1'));

        $result = $this->validate();

        $this->assertSame(CartState::NeedsUpdate, $result->state());
        $this->assertTrue($result->hasPriceChanges());
        $this->assertFalse($result->isReady());

        $change = $result->priceChange($item);
        $this->assertEquals(100, $change->oldPrice);
        $this->assertEquals(150, $change->newPrice);
    }

    public function test_correction_takes_precedence_over_update(): void
    {
        $this->inCart($this->product('A-1', ['price' => 150]));
        $this->inCart($this->product('B-1', ['stock' => 0]));

        $this->assertSame(CartState::NeedsCorrection, $this->validate()->state());
    }

    public function test_unavailable_product_reports_single_reason_and_is_excluded_from_total(): void
    {
        // Скрыт, без остатка и с другой ценой — пользователю важна только первая причина
        $hidden = $this->inCart($this->product('HID', ['is_active' => false, 'stock' => 0, 'price' => 500]), 3);
        $this->inCart($this->product('OK'), 2);

        $result = $this->validate();

        $this->assertSame([CartProblemType::Inactive], $this->types($result, $hidden));
        $this->assertTrue($result->isUnavailable($hidden));
        $this->assertEquals(200, $result->total());
    }

    public function test_physically_deleted_product_is_missing(): void
    {
        $item = $this->inCart($this->product('A-1'));

        $item->product->forceDelete();

        $result = $this->validate();
        $item = $this->cart->items()->sole();

        $this->assertNull($item->product_id);
        $this->assertSame([CartProblemType::Missing], $this->types($result, $item));
        $this->assertSame(['Смеситель A-1: товар больше недоступен'], $result->messages());
    }

    public function test_price_is_compared_to_cents(): void
    {
        $this->inCart($this->product('A-1', ['price' => 100.00]), 1, 100.004);

        $this->assertSame(CartState::Ready, $this->validate()->state());
    }
}
