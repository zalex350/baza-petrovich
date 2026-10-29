<?php

namespace Tests\Feature\Storefront;

use App\Http\Controllers\CatalogController;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Подборки «Акции» (/promo), «Новинки» (/new), «Хиты» (/hits): каталожный список с фильтрами
 * и сортировкой, отбор по флагу; флаг «Акция» и бейдж «%».
 */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
    }

    private function product(string $name, array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + [
            'name' => $name,
            'sku' => "SKU-{$n}",
            'brand' => 'Grohe',
            'price' => 100 + $n,
            'stock' => 5,
            'category_id' => $this->category->id,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    public function test_promo_page_lists_only_promo_products(): void
    {
        $this->product('Акционный смеситель', ['is_promo' => true]);
        $this->product('Обычный смеситель');
        $this->product('Скрытая акция', ['is_promo' => true, 'is_active' => false]);

        $this->actingAs($this->client)->get('/promo')
            ->assertOk()
            ->assertSee('<h1 class="text-3xl font-bold tracking-tight">Акции</h1>', false)
            ->assertSee('Акционный смеситель')
            ->assertDontSee('Обычный смеситель')
            ->assertDontSee('Скрытая акция')
            ->assertSee('data-product-badge="promo"', false)
            ->assertSee('data-catalog-form', false);
    }

    public function test_new_and_hits_pages_filter_by_their_flag(): void
    {
        $this->product('Новинка сезона', ['is_new' => true]);
        $this->product('Хит продаж', ['is_hit' => true]);

        $this->get('/new')->assertOk()->assertSee('Новинка сезона')->assertDontSee('Хит продаж');
        $this->get('/hits')->assertOk()->assertSee('Хит продаж')->assertDontSee('Новинка сезона');
    }

    public function test_filters_and_sort_work_on_collection(): void
    {
        $this->product('Grohe акция', ['is_promo' => true, 'brand' => 'Grohe', 'price' => 300]);
        $this->product('Hansgrohe акция', ['is_promo' => true, 'brand' => 'Hansgrohe', 'price' => 200]);

        $this->actingAs($this->client)->get('/promo?brand[]=Hansgrohe')
            ->assertOk()
            ->assertSee('Hansgrohe акция')
            ->assertDontSee('Grohe акция</a>', false)
            ->assertSee('Производитель: Hansgrohe');

        $this->actingAs($this->client)->get('/promo?sort=price_asc')
            ->assertSeeInOrder(['Hansgrohe акция', 'Grohe акция']);
    }

    public function test_collection_is_remembered_for_continue_shopping(): void
    {
        $this->actingAs($this->client)->get('/promo?sort=price_asc');

        $this->assertSame('/promo?sort=price_asc', CatalogController::continueUrl());
    }

    public function test_admin_sets_promo_flag_in_site_block(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product('Смеситель');

        $this->actingAs($admin)->get("/admin/product/{$product->id}")
            ->assertOk()
            ->assertSee('name="is_promo"', false);

        // Товары из 1С: поля учётной системы не принимаются, флаг — данные сайта, сохраняется сразу
        config(['shop.catalog.manual_products' => false]);

        $this->actingAs($admin)->post("/admin/product/{$product->id}", ['is_promo' => '1', 'action' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($product->fresh()->is_promo);
    }

    public function test_badge_order_promo_new_hit(): void
    {
        $this->product('Всё сразу', ['is_promo' => true, 'is_new' => true, 'is_hit' => true]);

        $this->get('/catalog/smesiteli')
            ->assertSeeInOrder(['data-product-badge="promo"', 'data-product-badge="new"', 'data-product-badge="hit"'], false)
            ->assertSee('<span class="sr-only"> Акция</span>', false);
    }
}
