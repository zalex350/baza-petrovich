<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Повторный заказ (SRS §8.11, UC-036): позиции в текущую корзину через CartValidator —
 * публикация, актуальная цена, остаток, кратность; итог в корзине; исходный заказ не меняется (BR-118).
 */
class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        $this->order = Order::create([
            'number' => 'SM-2026-000010', 'user_id' => $this->client->id, 'organization_id' => $this->client->organization_id,
            'status' => 'completed', 'total' => 1, 'placed_at' => now()->subMonth(),
        ]);
    }

    private function product(string $name, array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + [
            'name' => $name, 'sku' => "R-{$n}", 'price' => 100, 'stock' => 50,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    private function line(?Product $product, int $quantity, float $price = 100, ?string $name = null): OrderItem
    {
        return OrderItem::create([
            'order_id' => $this->order->id, 'product_id' => $product?->id, 'product_name' => $name ?? $product?->name,
            'product_sku' => $product?->sku ?? 'X', 'quantity' => $quantity, 'price' => $price, 'vat_rate' => 20,
            'amount_without_vat' => $price * $quantity, 'vat_amount' => 0, 'amount_with_vat' => $price * $quantity,
        ]);
    }

    private function reorder()
    {
        return $this->actingAs($this->client)->post("/order/{$this->order->id}/reorder");
    }

    public function test_all_positions_added_at_current_price_and_report_shown(): void
    {
        $a = $this->product('Смеситель A', ['price' => 120]);
        $b = $this->product('Смеситель B');
        $this->line($a, 2, 100);
        $this->line($b, 3, 100);

        $this->reorder()
            ->assertRedirect('/order')
            ->assertSessionHas('success', 'Добавлено в корзину: 2 позиции из заказа SM-2026-000010');

        $items = CartItem::orderBy('id')->get();
        $this->assertSame([2, 3], $items->pluck('quantity')->all());
        $this->assertEquals(120, $items[0]->price_at_add);
        $this->assertTrue($items[0]->selected);

        // Цена изменилась — сообщение в итоге
        $this->assertSame([['name' => 'Смеситель A', 'reason' => 'цена изменилась: было '.money(100).', стало '.money(120)]], session('reorder_issues'));

        $this->actingAs($this->client)->get('/order')
            ->assertSee('data-reorder-issues', false)
            ->assertSee('Не всё из заказа SM-2026-000010 добавлено как было')
            ->assertSee('«Смеситель A» — цена изменилась');
    }

    public function test_unavailable_positions_listed_with_reasons(): void
    {
        $ok = $this->product('Доступный');
        $hidden = $this->product('Скрытый', ['is_active' => false]);
        $draft = $this->product('Снятый с публикации', ['is_published' => false]);
        $noPrice = $this->product('Без цены', ['price' => null]);
        $empty = $this->product('Закончился', ['stock' => 0]);
        $deleted = $this->product('Удалённый');
        $deleted->delete();

        $this->line($ok, 1);
        $this->line($hidden, 1);
        $this->line($draft, 1);
        $this->line($noPrice, 1);
        $this->line($empty, 1);
        $this->line($deleted, 1);
        $this->line(null, 1, name: 'Удалён насовсем');

        $this->reorder()->assertSessionHas('success', 'Добавлено в корзину: 1 позиция из заказа SM-2026-000010');

        $this->assertSame([
            ['name' => 'Скрытый', 'reason' => 'снят с продажи'],
            ['name' => 'Снятый с публикации', 'reason' => 'снят с продажи'],
            ['name' => 'Без цены', 'reason' => 'цена уточняется — заказать нельзя'],
            ['name' => 'Закончился', 'reason' => 'нет в наличии'],
            ['name' => 'Удалённый', 'reason' => 'снят с продажи'],
            ['name' => 'Удалён насовсем', 'reason' => 'снят с продажи'],
        ], session('reorder_issues'));

        $this->assertSame([$ok->id], CartItem::pluck('product_id')->all());
    }

    public function test_quantity_adjusted_to_stock_and_multiplicity(): void
    {
        $low = $this->product('Мало на складе', ['stock' => 3]);
        $step = $this->product('Кратность 4', ['min_qty' => 4, 'qty_step' => 4]);
        $this->line($low, 5);
        $this->line($step, 5);

        $this->reorder()->assertSessionHas('success');

        $this->assertSame(3, CartItem::where('product_id', $low->id)->value('quantity'));
        $this->assertSame(8, CartItem::where('product_id', $step->id)->value('quantity'));
        $this->assertSame([
            ['name' => 'Мало на складе', 'reason' => 'количество скорректировано до 3 (доступно 3)'],
            ['name' => 'Кратность 4', 'reason' => 'количество скорректировано до 8'],
        ], session('reorder_issues'));
    }

    public function test_adds_to_existing_cart_position_within_stock(): void
    {
        $product = $this->product('Уже в корзине', ['stock' => 5]);
        $cart = Cart::create(['user_id' => $this->client->id]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'product_name' => 'Уже в корзине', 'quantity' => 4, 'price_at_add' => 100, 'selected' => false]);
        $this->line($product, 3);

        $this->reorder();

        $item = CartItem::sole();
        $this->assertSame(5, $item->quantity);
        $this->assertTrue($item->selected);
        $this->assertSame([['name' => 'Уже в корзине', 'reason' => 'количество скорректировано до 1 (доступно 5, в корзине уже было 4)']], session('reorder_issues'));

        // Больше добавить нечего
        $this->reorder()->assertSessionHas('error', 'Ни одну позицию заказа SM-2026-000010 сейчас нельзя добавить в корзину');
        $this->assertSame([['name' => 'Уже в корзине', 'reason' => 'нет в наличии сверх 5 шт. в корзине']], session('reorder_issues'));
    }

    public function test_original_order_is_not_changed(): void
    {
        $product = $this->product('Смеситель', ['price' => 150, 'stock' => 1]);
        $line = $this->line($product, 2, 100);
        $before = $this->order->fresh()->toArray();

        $this->reorder();

        $this->assertSame($before, $this->order->fresh()->toArray());
        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertEquals(100, $line->fresh()->price);
        $this->assertSame(1, Order::count());
    }

    public function test_reorder_buttons_in_list_and_card(): void
    {
        $this->actingAs($this->client)->get('/orders')->assertSee('action="/order/'.$this->order->id.'/reorder"', false)->assertSee('Повторить');
        $this->actingAs($this->client)->get("/order/{$this->order->id}")->assertSee('data-reorder', false)->assertSee('Повторить заказ');
    }

    public function test_colleague_can_reorder_foreign_cannot_admin_cannot(): void
    {
        $this->line($this->product('Смеситель'), 1);

        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);
        $this->actingAs($colleague)->post("/order/{$this->order->id}/reorder")->assertRedirect('/order');
        $this->assertSame(1, $colleague->cart->items()->count());

        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->actingAs($stranger)->post("/order/{$this->order->id}/reorder")->assertNotFound();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/order/{$this->order->id}/reorder")->assertNotFound();
    }
}
