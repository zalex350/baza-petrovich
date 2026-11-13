<?php

namespace Tests\Feature\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchSynonym;
use App\Services\Search\ProductSearch;
use App\Services\Search\SearchText;
use Database\Seeders\SearchSynonymSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Поиск по формам слов и синонимам (ProductSearch, SearchIndex): индекс search_text, все слова
 * в любом порядке, синонимы в обе стороны и в одну, порядок выдачи, артикул и «Возможно…».
 * Синонимы каждый тест задаёт сам (стартовый набор миграции удаляется).
 */
class SearchMorphologyTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        SearchSynonym::query()->delete();
        $this->category = Category::create(['name' => 'Сантехника', 'slug' => 'santehnika']);
    }

    private function product(string $name, array $attrs = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'name' => $name,
            'sku' => "SKU-{$n}",
            'price' => 100,
            'stock' => 5,
            'category_id' => $this->category->id,
            'is_active' => true,
            'is_published' => true,
        ], $attrs));
    }

    private function synonyms(string ...$lines): void
    {
        foreach ($lines as $line) {
            SearchSynonym::create(SearchSynonym::parseLine($line));
        }
    }

    /** id найденных в порядке выдачи */
    private function found(string $query): array
    {
        $search = app(ProductSearch::class);

        return $search->apply(Product::query()->where('is_active', 1), $search->parse($query))->pluck('id')->all();
    }

    public function test_index_has_words_and_stems_of_names_brand_and_category_path(): void
    {
        $parent = Category::create(['name' => 'Для кухни', 'slug' => 'kitchen']);
        $child = Category::create(['name' => 'Смесители', 'slug' => 'mixers', 'parent_id' => $parent->id]);

        $product = $this->product('Smes. kuh. hrom', ['display_name' => 'Смеситель Ёлка', 'brand' => 'Grohe', 'category_id' => $child->id]);

        $index = $product->fresh()->search_text;

        foreach ([' смеситель ', ' смесител ', ' елка ', ' smes ', ' grohe ', ' смесители ', ' кухни ', ' кухн '] as $part) {
            $this->assertStringContainsString($part, $index);
        }
        // Служебные слова — не в индексе
        $this->assertStringNotContainsString(' для ', $index);
    }

    public function test_word_forms_and_order_do_not_matter(): void
    {
        $this->synonyms('кухня, кухонный');

        $a = $this->product('Смеситель для кухни Alpha');
        $b = $this->product('Смесители кухонные Beta');
        $c = $this->product('Смеситель для ванны Gamma');

        $one = $this->found('смеситель кухня');
        $two = $this->found('смесители для кухни');
        $three = $this->found('кухни смесителя');

        sort($one);
        sort($two);
        sort($three);

        $this->assertSame([$a->id, $b->id], $one);
        $this->assertSame($one, $two);
        $this->assertSame($one, $three);
        $this->assertNotContains($c->id, $one);

        // Страница поиска и подсказки — те же товары
        $this->get('/search?q='.urlencode('смеситель кухня'))->assertOk()
            ->assertSee('Смеситель для кухни Alpha')->assertSee('Смесители кухонные Beta')->assertDontSee('Gamma');
        $this->getJson('/search/suggest?q='.urlencode('смесители для кухни'))->assertOk()->assertJsonCount(2, 'products');
    }

    public function test_all_words_must_match(): void
    {
        $this->product('Смеситель для кухни Alpha');

        $this->assertSame([], $this->found('смеситель кухня латунь'));
    }

    public function test_partial_word_while_typing_matches_word_start(): void
    {
        $mixer = $this->product('Смеситель для кухни Alpha');
        $plunger = $this->product('Вантуз Beta');
        $bath = $this->product('Ванна стальная Gamma');

        $this->assertSame([$mixer->id], $this->found('смеси'));
        // «ванна» → основа «ван» целым словом: ванны — да, вантуз — нет
        $this->assertSame([$bath->id], $this->found('ванны'));
        $this->assertNotContains($plunger->id, $this->found('ванна'));
    }

    public function test_kran_kitchen_finds_mixers_through_synonym_group(): void
    {
        $this->synonyms('смесители, краны', 'кухня, кухонный');

        $mixer = $this->product('Смеситель для кухонной мойки Fmark');
        $valve = $this->product('Кран шаровой Beta');
        $bath = $this->product('Смеситель для ванны Gamma');
        $this->product('Ванна стальная Delta');

        $this->assertSame([$mixer->id], $this->found('кран кухня'));
        // Группа — в обе стороны, формы слов в группе не важны
        $this->assertEqualsCanonicalizing([$mixer->id, $valve->id, $bath->id], $this->found('кран'));
        $this->assertContains($valve->id, $this->found('смесители'));
    }

    public function test_one_way_replacement(): void
    {
        $this->synonyms('мойка → раковина');

        $sink = $this->product('Мойка кухонная Alpha');
        $basin = $this->product('Раковина накладная Beta');

        $this->assertEqualsCanonicalizing([$sink->id, $basin->id], $this->found('мойки'));
        $this->assertSame([$basin->id], $this->found('раковина'));
    }

    public function test_phrase_synonym(): void
    {
        $this->synonyms('вентиль, шаровой кран');

        $valve = $this->product('Кран шаровой полнопроходной Alpha');
        $other = $this->product('Кран угловой Beta');

        $this->assertSame([$valve->id], $this->found('вентиль'));
        $this->assertNotContains($other->id, $this->found('вентиль'));
    }

    public function test_ranking_exact_article_then_title_then_stems_then_synonyms(): void
    {
        // Латиница: LOWER в SQLite тестов не работает с кириллицей (в MySQL — работает)
        $this->synonyms('tap, mixer');
        $parts = Category::create(['name' => 'Tap parts', 'slug' => 'tap-parts']);

        $synonym = $this->product('Mixer Aaa');
        $stems = $this->product('Beta', ['category_id' => $parts->id]);
        $title = $this->product('Zulu tap');
        $article = $this->product('Zzz', ['article' => 'TAP']);

        $this->assertSame([$article->id, $title->id, $stems->id, $synonym->id], $this->found('tap'));
    }

    public function test_exact_article_is_first(): void
    {
        $exact = $this->product('Смеситель Ledeme', ['article' => 'L4755-2', 'brand' => 'Ledeme']);
        $longer = $this->product('Аэратор Ledeme', ['article' => 'L4755-22', 'brand' => 'Ledeme']);
        $inName = $this->product('Картридж для L4755-2');

        $found = $this->found('L4755-2');

        $this->assertSame($exact->id, $found[0]);
        $this->assertEqualsCanonicalizing([$exact->id, $longer->id, $inName->id], $found);

        $this->getJson('/search/suggest?q=L4755-2')->assertOk()->assertJsonPath('products.0.id', $exact->id);
    }

    public function test_article_without_dash_suggests_did_you_mean(): void
    {
        $this->product('Смеситель Ledeme', ['article' => 'L4755-2', 'brand' => 'Ledeme']);

        $this->getJson('/search/suggest?q=L47552')->assertOk()
            ->assertJsonCount(0, 'products')
            ->assertJsonPath('did_you_mean', ['L4755-2']);

        $this->get('/search?q=L47552')->assertOk()->assertSee('Возможно')->assertSee('L4755-2');
    }

    public function test_only_stop_words_find_nothing(): void
    {
        $this->product('Смеситель для кухни Alpha');

        $this->assertSame([], $this->found('для'));
        $this->get('/search?q='.urlencode('для'))->assertOk()->assertSee('ничего не найдено');
    }

    public function test_suggest_categories_by_stems_and_synonyms(): void
    {
        $this->synonyms('смеситель, кран');
        Category::create(['name' => 'Смесители для кухни', 'slug' => 'kitchen-mixers']);
        Category::create(['name' => 'Ванны', 'slug' => 'baths']);

        $this->getJson('/search/suggest?q='.urlencode('кран кухня'))->assertOk()
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.name', 'Смесители для кухни');

        // По пути с родителем: «Смесители / Для кухни»
        $parent = Category::create(['name' => 'Смесители', 'slug' => 'mixers']);
        Category::create(['name' => 'Для кухни', 'slug' => 'mixers-kitchen', 'parent_id' => $parent->id]);

        $this->getJson('/search/suggest?q='.urlencode('смеситель кухня'))->assertOk()
            ->assertJsonCount(2, 'categories')
            ->assertJsonPath('categories.1.name', 'Смесители / Для кухни');
    }

    public function test_index_follows_product_and_category_changes(): void
    {
        $product = $this->product('Alpha');

        $this->assertSame([], $this->found('унитаз'));

        $this->category->update(['name' => 'Унитазы']);
        $this->assertSame([$product->id], $this->found('унитаз'));

        $product->update(['display_name' => 'Инсталляция Geberit']);
        $this->assertSame([$product->id], $this->found('инсталляции'));
    }

    public function test_reindex_command_fills_index_changed_past_model(): void
    {
        $product = $this->product('Смеситель Alpha');
        Product::query()->whereKey($product->id)->update(['name' => 'Унитаз Alpha', 'search_text' => null]);

        $this->artisan('search:reindex')->expectsOutputToContain('изменено товаров — 1')->assertSuccessful();
        $this->assertSame([$product->id], $this->found('унитазы'));

        $this->artisan('search:reindex')->expectsOutputToContain('изменено товаров — 0')->assertSuccessful();
    }

    public function test_synonym_rules_take_effect_immediately(): void
    {
        $mixer = $this->product('Смеситель Alpha');

        $this->assertSame([], $this->found('кран'));

        $rule = SearchSynonym::create(SearchSynonym::parseLine('смеситель, кран'));
        $this->assertSame([$mixer->id], $this->found('кран'));

        $rule->delete();
        $this->assertSame([], $this->found('кран'));
    }

    public function test_seeder_fills_only_empty_table(): void
    {
        (new SearchSynonymSeeder)->run();
        $this->assertSame(count(SearchSynonymSeeder::LINES), SearchSynonym::count());

        SearchSynonym::query()->first()->delete();
        (new SearchSynonymSeeder)->run();
        $this->assertSame(count(SearchSynonymSeeder::LINES) - 1, SearchSynonym::count());
    }

    public function test_text_normalization(): void
    {
        $text = app(SearchText::class);

        $this->assertSame(['смесители', 'кухни', 'f', 'sl', 'c1166'], $text->words('Смесители для  КУХНИ, F-SL-C1166'));
        $this->assertSame($text->stem('смесители'), $text->stem('смеситель'));
        $this->assertSame('grohe', $text->stem('grohe'));
    }
}
