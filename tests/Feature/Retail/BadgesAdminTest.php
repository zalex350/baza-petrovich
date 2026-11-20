<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Метки ХИТ / НОВИНКА / Акция: фильтры в списке товаров админки, «Снять метку со всех»,
 * порядок в каруселях главной (последний отмеченный — первый), фильтр «Без описания».
 */
class BadgesAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::create(['name' => 'Сетка', 'slug' => 'setka']);
    }

    private function product(string $name, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => $name, 'sku' => 'S-'.md5($name), 'price' => 10, 'stock' => 1,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true,
            'description' => 'Есть описание',
        ]);
    }

    public function test_filters_and_clear_all_hits(): void
    {
        $this->product('Рабица 1', ['is_hit' => true]);
        $this->product('Рабица 2', ['is_hit' => true]);
        $promo = $this->product('Штакетник', ['is_promo' => true]);
        $this->product('Без текста', ['description' => null]);

        $this->actingAs($this->admin)->get('/admin/products')
            ->assertSee('data-badge-filter="hit"', false)->assertSee('Хиты (2)')
            ->assertSee('Акции (1)')->assertSee('Без описания (1)');

        $this->actingAs($this->admin)->get('/admin/products?description=none')->assertSee('Без текста')->assertDontSee('Рабица 1');

        $this->actingAs($this->admin)->get('/admin/products?badge=hit')
            ->assertSee('Рабица 1')->assertDontSee('Штакетник')->assertSee('data-clear-badge', false);

        $this->actingAs($this->admin)->post('/admin/products/clear-badge', ['badge' => 'hit'])->assertRedirect('/admin/products');

        $this->assertSame(0, Product::where('is_hit', true)->count());
        $this->assertTrue($promo->fresh()->is_promo, 'другие метки не тронуты');
        $this->actingAs($this->admin)->post('/admin/products/clear-badge', ['badge' => 'x'])->assertStatus(422);
    }

    public function test_last_marked_product_is_first_in_carousel(): void
    {
        $this->travel(-2)->days();
        $old = $this->product('Старый товар');
        $this->travelBack();
        $this->product('Хит импорта', ['is_hit' => true]);

        // Отмечаем старый товар — он становится первым в «Хитах»
        $this->travel(1)->minutes();
        $this->actingAs($this->admin)->postJson("/admin/product/{$old->id}/badge", ['field' => 'is_hit', 'value' => true])->assertOk();
        auth()->logout();

        $html = $this->get('/')->getContent();
        $hits = substr($html, strpos($html, 'Хиты'));
        $this->assertLessThan(strpos($hits, 'Хит импорта'), strpos($hits, 'Старый товар'));
    }
}
