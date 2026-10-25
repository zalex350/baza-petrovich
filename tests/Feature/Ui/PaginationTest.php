<?php

namespace Tests\Feature\Ui;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * x-pagination (макет docs/design/Catalog): номера, текущая — тёмная, «Назад / Далее»,
 * пропуски, «N из M» на телефоне; в каталоге, поиске и админ-списках.
 */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private function paginator(int $page, int $last): LengthAwarePaginator
    {
        return new LengthAwarePaginator(range(1, 10), $last * 10, 10, $page, ['path' => '/catalog/x']);
    }

    public function test_single_page_renders_nothing(): void
    {
        $this->blade('<x-pagination :paginator="$p" />', ['p' => $this->paginator(1, 1)])
            ->assertDontSee('<nav', false);
    }

    public function test_numbers_current_prev_next_and_gaps(): void
    {
        $html = (string) $this->blade('<x-pagination :paginator="$p" />', ['p' => $this->paginator(5, 10)]);

        $this->assertStringContainsString('aria-label="Страницы"', $html);
        // Текущая — тёмная, без ссылки
        $this->assertMatchesRegularExpression('#<span class="[^"]*bg-primary[^"]*text-white[^"]*" aria-current="page">5</span>#', $html);
        // 1 … 4 5 6 … 10
        foreach ([1, 4, 6, 10] as $page) {
            $this->assertStringContainsString('href="/catalog/x?page='.$page.'"', $html);
        }
        $this->assertStringNotContainsString('href="/catalog/x?page=3"', $html);
        $this->assertSame(2, substr_count($html, '…'));
        $this->assertMatchesRegularExpression('#href="/catalog/x\?page=4"[^>]*rel="prev">Назад#', $html);
        $this->assertMatchesRegularExpression('#href="/catalog/x\?page=6"[^>]*rel="next">Далее#', $html);
        // Телефон — компактно
        $this->assertStringContainsString('5 из 10', $html);
    }

    public function test_edges_disable_prev_and_next(): void
    {
        $first = (string) $this->blade('<x-pagination :paginator="$p" />', ['p' => $this->paginator(1, 3)]);
        $this->assertMatchesRegularExpression('#<span[^>]*aria-disabled="true">Назад</span>#', $first);

        $last = (string) $this->blade('<x-pagination :paginator="$p" />', ['p' => $this->paginator(3, 3)]);
        $this->assertMatchesRegularExpression('#<span[^>]*aria-disabled="true">Далее</span>#', $last);
    }

    public function test_catalog_search_and_admin_lists_use_component(): void
    {
        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        for ($i = 1; $i <= 55; $i++) {
            Product::create([
                'name' => "Grohe {$i}", 'sku' => "GR-{$i}", 'price' => 10, 'stock' => 5,
                'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
            ]);
        }

        $client = User::factory()->create();

        foreach (['/catalog/smesiteli?sort=name_asc', '/search?q=Grohe'] as $url) {
            $this->actingAs($client)->get($url)
                ->assertOk()
                ->assertSee('data-ui-pagination', false)
                ->assertSee('aria-current="page">1</span>', false);
        }

        // Фильтры и сортировка остаются в ссылках страниц
        $this->actingAs($client)->get('/catalog/smesiteli?sort=name_asc')
            ->assertSee(e('/catalog/smesiteli?sort=name_asc&page=2').'" class=', false);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')
            ->assertOk()
            ->assertSee('data-ui-pagination', false)
            ->assertSee('Далее');
    }
}
