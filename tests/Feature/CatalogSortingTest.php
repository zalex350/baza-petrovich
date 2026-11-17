<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Catalog\CategoryOrder;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\B2bPricing;
use Tests\TestCase;

/**
 * Сортировка товаров по цене (каталог, подборки, поиск — только после входа, без цены — в конце)
 * и порядок категорий (CategoryOrder): по количеству товаров с закреплёнными первыми, вручную,
 * по алфавиту — в мегаменю, /catalog, на главной, в подкатегориях и в админке.
 */
class CatalogSortingTest extends TestCase
{
    use B2bPricing;
    use RefreshDatabase;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->admin = User::factory()->admin()->create();
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        static $n = 0;
        $n++;

        return Category::create(['name' => $name, 'slug' => "c-{$n}", 'parent_id' => $parent?->id]);
    }

    private function products(Category $category, int $count, array $attributes = []): void
    {
        static $n = 0;
        foreach (range(1, $count) as $i) {
            $n++;
            Product::create($attributes + ['name' => "Item {$n}", 'sku' => "SKU-{$n}", 'price' => 10, 'stock' => 1,
                'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);
        }
    }

    /** Порядок строк в HTML */
    private function assertOrder(array $needles, string $html, string $message = ''): void
    {
        $positions = array_map(fn ($n) => strpos($html, $n), $needles);
        $this->assertNotContains(false, $positions, $message.' — нет на странице');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, $message);
    }

    private function names(array $nodes): array
    {
        return array_column($nodes, 'name');
    }

    // --- Цена ---

    private function priced(): Category
    {
        $category = $this->category('Смесители');
        foreach (['Mid' => 20, 'Cheap' => 10, 'NoPrice' => null, 'Dear' => 30] as $name => $price) {
            Product::create(['name' => "Mixer {$name}", 'sku' => "M-{$name}", 'price' => $price, 'stock' => 1,
                'category_id' => $category->id, 'is_active' => true, 'is_published' => true, 'is_hit' => true]);
        }

        return $category;
    }

    public function test_catalog_and_collections_sort_by_price_for_client_only(): void
    {
        $category = $this->priced();

        foreach (['/catalog/'.$category->slug, '/hits'] as $url) {
            $this->actingAs($this->client)->get($url)->assertOk()->assertSee('Цена ↑')->assertSee('Цена ↓');

            $asc = $this->actingAs($this->client)->get($url.'?sort=price_asc')->getContent();
            $this->assertOrder(['Mixer Cheap', 'Mixer Mid', 'Mixer Dear', 'Mixer NoPrice'], $asc, "{$url} ↑");

            $desc = $this->actingAs($this->client)->get($url.'?sort=price_desc')->getContent();
            $this->assertOrder(['Mixer Dear', 'Mixer Mid', 'Mixer Cheap', 'Mixer NoPrice'], $desc, "{$url} ↓, без цены — в конце");
            // Сортировка — в адресе (ссылки вида и пагинации её сохраняют)
            $this->assertStringContainsString('sort=price_desc', $desc);
        }

        auth()->logout();
        foreach (['/catalog/'.$category->slug, '/hits'] as $url) {
            $guest = $this->get($url.'?sort=price_asc')->assertOk()->assertDontSee('Цена ↑')->getContent();
            // Параметр игнорируется — порядок по умолчанию (новые первыми)
            $this->assertOrder(['Mixer Dear', 'Mixer NoPrice', 'Mixer Cheap', 'Mixer Mid'], $guest, "{$url} гость");
        }
    }

    public function test_search_sorts_by_price_for_client_only(): void
    {
        $this->priced();

        $this->actingAs($this->client)->get('/search?q=mixer')->assertOk()
            ->assertSee('data-search-sort', false)->assertSee('Цена ↑')->assertSee('По релевантности');

        $asc = $this->actingAs($this->client)->get('/search?q=mixer&sort=price_asc')->getContent();
        $this->assertOrder(['Mixer Cheap', 'Mixer Mid', 'Mixer Dear', 'Mixer NoPrice'], $asc);

        $desc = $this->actingAs($this->client)->get('/search?q=mixer&sort=price_desc')->getContent();
        $this->assertOrder(['Mixer Dear', 'Mixer Mid', 'Mixer Cheap', 'Mixer NoPrice'], $desc);

        auth()->logout();
        $guest = $this->get('/search?q=mixer&sort=price_desc')->assertOk()->assertDontSee('Цена ↓')->getContent();
        $this->assertStringNotContainsString('data-search-sort', $guest);
        // Гость — по релевантности (одинаковая — по названию)
        $this->assertOrder(['Mixer Cheap', 'Mixer Dear', 'Mixer Mid', 'Mixer NoPrice'], $guest);
    }

    // --- Порядок категорий ---

    private function catalogTree(): array
    {
        $a = $this->category('Alpha');
        $b = $this->category('Bravo');
        $c = $this->category('Charlie');
        $this->products($a, 1);
        $this->products($b, 2);
        $sub = $this->category('Charlie sub', $c);
        $this->products($sub, 5);
        // Неопубликованные не считаются
        $this->products($a, 10, ['is_published' => false]);

        $x = $this->category('Xray sub', $b);
        $y = $this->category('Yankee sub', $b);
        $this->products($x, 1);
        $this->products($y, 3);
        CatalogMenu::flush();

        return compact('a', 'b', 'c', 'x', 'y');
    }

    public function test_categories_by_product_count_everywhere(): void
    {
        ['b' => $b] = $this->catalogTree();

        // Bravo: 2 + 1 + 3 = 6 с подкатегориями, Charlie: 5, Alpha: 1
        $tree = app(CatalogMenu::class)->tree();
        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $this->names($tree));
        $this->assertSame(['Yankee sub', 'Xray sub'], $this->names($tree[0]['children']));

        $this->assertOrder(['data-mega-root="'.$b->id.'"', 'Charlie', 'Alpha'], $this->get('/')->getContent(), 'мегаменю и главная');
        $this->assertOrder(['>Bravo<', '>Charlie<', '>Alpha<'], $this->get('/catalog')->getContent(), '/catalog');
        $this->assertOrder(['Yankee sub', 'Xray sub'], $this->get('/catalog/'.$b->slug)->getContent(), 'подкатегории');
    }

    public function test_admin_arrows_pin_category_and_unpin_restores(): void
    {
        ['a' => $a, 'c' => $c, 'x' => $x] = $this->catalogTree();

        // Alpha (1 товар) — выше Charlie (5): Alpha и всё, что выше её, закрепляются
        $this->actingAs($this->admin)->post("/admin/category/{$a->id}/move", ['direction' => 'up'])->assertRedirect();

        $this->assertTrue($a->fresh()->is_pinned);
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $this->names(app(CatalogMenu::class)->tree()));
        $this->assertFalse($c->fresh()->is_pinned, 'ниже нового места — не закрепляется');

        // Подкатегория: Xray (1) выше Yankee (3)
        $this->actingAs($this->admin)->post("/admin/category/{$x->id}/move", ['direction' => 'up']);
        $this->assertSame(['Xray sub', 'Yankee sub'], $this->names(app(CatalogMenu::class)->tree()[0]['children']));

        $this->actingAs($this->admin)->get('/admin/categories')->assertOk()
            ->assertSee('закреплена')->assertSee('Вернуть автопорядок');
        $this->actingAs($this->admin)->get("/admin/category/{$a->id}/edit")->assertOk()
            ->assertSee('data-category-pinned', false);

        // Вернуть автопорядок: Alpha снова по количеству
        $this->actingAs($this->admin)->post("/admin/category/{$a->id}/unpin")->assertRedirect();
        $this->assertFalse($a->fresh()->is_pinned);
        $tree = app(CatalogMenu::class)->tree();
        $this->assertSame('Alpha', end($tree)['name']);
    }

    public function test_modes_manual_and_alphabet(): void
    {
        ['a' => $a] = $this->catalogTree();

        $this->actingAs($this->admin)->post('/admin/categories/order-mode', ['mode' => 'alpha'])->assertRedirect();
        $this->assertSame('alpha', Setting::value(CategoryOrder::SETTING));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(app(CatalogMenu::class)->tree()));

        // По алфавиту стрелок нет, ↑ / ↓ не действуют
        $this->actingAs($this->admin)->get('/admin/categories')->assertDontSee('data-category-move', false);
        $this->actingAs($this->admin)->post("/admin/category/{$a->id}/move", ['direction' => 'down'])->assertSessionHas('warning');

        // Только вручную: sort_order (у всех 0 — по названию), ↑ / ↓ без закрепления
        $this->actingAs($this->admin)->post('/admin/categories/order-mode', ['mode' => 'manual']);
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->names(app(CatalogMenu::class)->tree()));
        $this->actingAs($this->admin)->post("/admin/category/{$a->id}/move", ['direction' => 'down']);
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $this->names(app(CatalogMenu::class)->tree()));
        $this->assertFalse($a->fresh()->is_pinned);

        $this->actingAs($this->admin)->from('/admin/categories')
            ->post('/admin/categories/order-mode', ['mode' => 'random'])->assertSessionHasErrors('mode');
    }

    public function test_admin_list_has_published_counts_with_subcategories(): void
    {
        ['a' => $a, 'b' => $b] = $this->catalogTree();

        $html = $this->actingAs($this->admin)->get('/admin/categories')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-category-row="'.$b->id.'".*?data-category-count>6</s', $html);
        // Неопубликованные не считаются
        $this->assertMatchesRegularExpression('/data-category-row="'.$a->id.'".*?data-category-count>1</s', $html);
        $this->assertStringContainsString('Порядок категорий', $html);
        // Список — в порядке витрины
        $this->assertOrder(['data-category-row="'.$b->id.'"', 'data-category-row="'.$a->id.'"'], $html);
    }

    public function test_order_follows_new_products_and_night_refresh(): void
    {
        ['a' => $a] = $this->catalogTree();
        app(CatalogMenu::class)->tree();

        // Публикация через модель — кэш сбрасывает наблюдатель
        $this->products($a, 10);
        $this->assertSame('Alpha', app(CatalogMenu::class)->tree()[0]['name']);

        // Мимо модели (массовое обновление) — ночная команда
        Product::query()->where('category_id', $a->id)->update(['is_published' => false]);
        $this->artisan('catalog:menu-refresh')->assertSuccessful();
        $this->assertSame(['Bravo', 'Charlie'], $this->names(app(CatalogMenu::class)->tree()));
    }
}
