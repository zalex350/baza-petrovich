<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchSynonym;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Админка «Синонимы поиска»: доступ, добавление группы и замены в одну сторону, проверки строки,
 * правка в строке, удаление, действие в поиске сразу, «Проверить запрос».
 */
class AdminSearchSynonymTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SearchSynonym::query()->delete();
        $this->admin = User::factory()->admin()->create();
    }

    private function product(string $name): Product
    {
        $category = Category::firstOrCreate(['slug' => 'santehnika'], ['name' => 'Сантехника']);

        return Product::create([
            'name' => $name, 'sku' => 'SKU-'.md5($name), 'price' => 10, 'stock' => 1,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_only_admin_has_access(): void
    {
        $this->get('/admin/search-synonyms')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/search-synonyms')->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/search-synonyms')->assertOk()
            ->assertSee('Синонимы поиска')->assertSee('Синонимов нет');
    }

    public function test_add_group_and_one_way_rule(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/search-synonyms', ['line' => ' смеситель ,кран,, смеситель '])->assertRedirect('/admin/search-synonyms');
        $this->post('/admin/search-synonyms', ['line' => 'мойка -> раковина, умывальник'])->assertRedirect('/admin/search-synonyms');

        $this->assertDatabaseHas('search_synonyms', ['terms' => 'смеситель, кран', 'targets' => null]);
        $this->assertDatabaseHas('search_synonyms', ['terms' => 'мойка', 'targets' => 'раковина, умывальник']);

        $this->get('/admin/search-synonyms')->assertOk()
            ->assertSee('мойка → раковина, умывальник')
            ->assertSee('в одну сторону')
            ->assertSee('группа');
    }

    public function test_invalid_lines_are_rejected(): void
    {
        $this->actingAs($this->admin)->from('/admin/search-synonyms');

        foreach (['', 'смеситель', 'для, с', '→ раковина'] as $line) {
            $this->post('/admin/search-synonyms', ['line' => $line])->assertSessionHasErrors('line');
        }

        $this->assertSame(0, SearchSynonym::count());
    }

    public function test_edit_and_delete(): void
    {
        $this->actingAs($this->admin);
        $rule = SearchSynonym::create(SearchSynonym::parseLine('смеситель, кран'));

        $this->post("/admin/search-synonym/{$rule->id}", ['lines' => [$rule->id => 'кран → смеситель']])
            ->assertRedirect('/admin/search-synonyms');
        $this->assertSame('кран → смеситель', $rule->fresh()->line());

        $this->from('/admin/search-synonyms')
            ->post("/admin/search-synonym/{$rule->id}", ['lines' => [$rule->id => 'кран']])
            ->assertSessionHasErrors("lines.{$rule->id}");

        $this->post("/admin/search-synonym/{$rule->id}/delete")->assertRedirect('/admin/search-synonyms');
        $this->assertSame(0, SearchSynonym::count());
    }

    public function test_rule_from_admin_works_in_search_without_deploy(): void
    {
        $this->product('Смеситель для кухни Alpha');

        $this->getJson('/search/suggest?q='.urlencode('кран'))->assertJsonCount(0, 'products');

        $this->actingAs($this->admin)->post('/admin/search-synonyms', ['line' => 'смеситель, кран']);

        $this->getJson('/search/suggest?q='.urlencode('кран'))->assertJsonCount(1, 'products');
    }

    public function test_check_query_shows_expansion_and_count(): void
    {
        $this->product('Смеситель для кухни Alpha');
        $this->product('Кран шаровой Beta');
        SearchSynonym::create(SearchSynonym::parseLine('смеситель, кран'));

        $this->actingAs($this->admin)->get('/admin/search-synonyms?check='.urlencode('краны'))->assertOk()
            ->assertSee('data-check-result', false)
            ->assertSee('основа «кран»')
            ->assertSee('«смеситель»')
            ->assertSeeInOrder(['Нашлось товаров на витрине:', '2'])
            ->assertSee('Смеситель для кухни Alpha')
            ->assertSee('Кран шаровой Beta');
    }
}
