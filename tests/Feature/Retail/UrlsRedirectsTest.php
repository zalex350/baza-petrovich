<?php

namespace Tests\Feature\Retail;

use App\Console\Commands\PetrovichArticles;
use App\Http\Middleware\RemoveTrailingSlash;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\User;
use App\Support\Html;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ЧПУ товаров, редиректы со старого baza-petrovich.by и при смене адреса, «Статьи».
 */
class UrlsRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private Category $root;

    private Category $child;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = Category::create(['name' => 'Профнастил', 'slug' => 'profnastil']);
        $this->root->forceFill(['source_key' => 'profnastil'])->save();
        $this->child = Category::create(['name' => 'Для забора', 'slug' => 'profnastil-dlya-zabora', 'parent_id' => $this->root->id]);
        $this->child->forceFill(['source_key' => 'profnastil/profnastil-dlya-zabora'])->save();

        $this->product = Product::create([
            'name' => 'Профнастил С8 зелёный', 'sku' => 'PN-1', 'slug' => 'profnastil-s8-zelenyj', 'price' => 20, 'stock' => 5,
            'category_id' => $this->child->id, 'is_active' => true, 'is_published' => true,
        ]);
        $this->product->forceFill(['source_key' => 'catalog/profnastil/profnastil-dlya-zabora/profnastil-s8-zelenyj'])->save();
    }

    public function test_product_has_slug_url_and_id_redirects(): void
    {
        $this->assertSame('/product/profnastil-s8-zelenyj', $this->product->url(false));

        $this->get('/product/profnastil-s8-zelenyj')->assertOk()->assertSee('Профнастил С8 зелёный');
        $this->get('/product/'.$this->product->id.'?utm_source=x')->assertStatus(301)->assertRedirect('/product/profnastil-s8-zelenyj?utm_source=x');

        // Списки и поиск ведут на ЧПУ
        $this->get('/catalog/profnastil')->assertSee('href="'.url('/product/profnastil-s8-zelenyj').'"', false);

        // Без slug — по id, без редиректа
        $plain = Product::create(['name' => 'Без адреса', 'sku' => 'NS-1', 'price' => 1, 'stock' => 1, 'category_id' => $this->child->id, 'is_active' => true, 'is_published' => true]);
        $this->get('/product/'.$plain->id)->assertOk();
    }

    public function test_unique_slug(): void
    {
        $this->assertSame('profnastil-s8-zelenyj-2', Product::uniqueSlug('profnastil s8 zelenyj'));
        $this->assertSame('profnastil-s8-zelenyj', Product::uniqueSlug('profnastil s8 zelenyj', $this->product->id));
        $this->assertSame('setka-rabica-50x50', Product::uniqueSlug('Сетка рабица 50x50'));
        $this->assertSame('tovar-12345', Product::uniqueSlug('12345'));
    }

    /** Тестовый клиент Laravel сам срезает «/» в конце — проверяем middleware напрямую */
    public function test_trailing_slash_redirects(): void
    {
        $middleware = new RemoveTrailingSlash;
        $next = fn () => response('ok');

        $response = $middleware->handle(Request::create('/catalog/profnastil/?sort=price_asc'), $next);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(url('/catalog/profnastil?sort=price_asc'), $response->headers->get('Location'));

        $this->assertSame('ok', $middleware->handle(Request::create('/'), $next)->getContent());
        $this->assertSame('ok', $middleware->handle(Request::create('/order/', 'POST'), $next)->getContent());
    }

    public function test_old_catalog_urls(): void
    {
        // Товар старого сайта — по ключу импорта
        $this->get('/catalog/profnastil/profnastil-dlya-zabora/profnastil-s8-zelenyj')->assertStatus(301)->assertRedirect('/product/profnastil-s8-zelenyj');
        // Подраздел старого сайта
        $this->get('/catalog/profnastil/profnastil-dlya-zabora')->assertRedirect('/catalog/profnastil-dlya-zabora');
        // Товара больше нет — его раздел; раздела нет — ближайший родитель или каталог
        $this->get('/catalog/profnastil/profnastil-dlya-zabora/snyatyj-tovar')->assertRedirect('/catalog/profnastil-dlya-zabora');
        $this->get('/catalog/profnastil/net-takogo/i-tovara')->assertRedirect('/catalog/profnastil');
        $this->get('/catalog/net/takogo')->assertRedirect('/catalog');
        // Кириллица в старых адресах
        $this->get('/catalog/profnastil/'.rawurlencode('новый-ресурс'))->assertRedirect('/catalog/profnastil');
    }

    public function test_old_service_pages_and_unknown(): void
    {
        Page::create(['title' => 'Резка металла', 'slug' => 'rezka-metalla', 'type' => Page::SERVICE, 'body' => 'x', 'is_published' => true]);

        $this->get('/uslugi/rezka-metalla')->assertOk();
        $this->get('/uslugi/net-takoj')->assertRedirect('/uslugi');
        $this->get('/company')->assertRedirect('/about');
        $this->get('/cutter')->assertRedirect('/uslugi/rezka-metalla');
        $this->get('/nashi-klienty/rup-maz')->assertRedirect('/about');
        $this->get('/news/grafik-raboty-v-mae')->assertRedirect('/stati');

        $this->get('/sovsem-net-takoj-stranicy')->assertNotFound();
        // POST не редиректится — форма не должна молча уйти на другую страницу
        $this->post('/catalog/net/takogo')->assertNotFound();
    }

    public function test_slug_change_creates_redirect_and_shortens_chain(): void
    {
        $this->product->update(['slug' => 'profnastil-s8-zelenyj-new']);
        $this->get('/product/profnastil-s8-zelenyj')->assertStatus(301)->assertRedirect('/product/profnastil-s8-zelenyj-new');

        $this->product->update(['slug' => 'profnastil-s8-final']);
        $this->assertSame('/product/profnastil-s8-final', Redirect::where('from_path', 'product/profnastil-s8-zelenyj')->value('to_url'));
        $this->get('/product/profnastil-s8-zelenyj-new')->assertRedirect('/product/profnastil-s8-final');

        // Счётчик переходов
        $this->assertSame(1, Redirect::where('from_path', 'product/profnastil-s8-zelenyj')->value('hits'));

        $this->child->update(['slug' => 'dlya-zabora']);
        $this->get('/catalog/profnastil-dlya-zabora')->assertRedirect('/catalog/dlya-zabora');
    }

    public function test_admin_redirects(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/redirects')->assertOk()->assertSee('data-redirect-form', false);

        $this->actingAs($admin)->post('/admin/redirects', ['from' => 'https://baza-petrovich.by/Akcii/Leto/', 'to' => '/promo'])->assertRedirect('/admin/redirects');
        $this->assertSame('/promo', Redirect::where('from_path', 'akcii/leto')->value('to_url'));
        $this->get('/akcii/leto')->assertRedirect('/promo');

        $this->actingAs($admin)->post('/admin/redirects', ['from' => '/', 'to' => '/promo'])->assertSessionHasErrors('from');
        $this->actingAs($admin)->post('/admin/redirects', ['from' => '/x', 'to' => 'javascript:alert(1)'])->assertSessionHasErrors('to');

        $this->actingAs($admin)->get('/admin/redirects/check?url=/catalog/profnastil/profnastil-dlya-zabora/profnastil-s8-zelenyj/')
            ->assertSessionHas('success', '/catalog/profnastil/profnastil-dlya-zabora/profnastil-s8-zelenyj → /product/profnastil-s8-zelenyj');

        $id = Redirect::where('from_path', 'akcii/leto')->value('id');
        $this->actingAs($admin)->post("/admin/redirect/{$id}/delete")->assertRedirect();
        $this->assertDatabaseMissing('redirects', ['id' => $id]);
    }

    public function test_articles_import_pages_and_old_urls(): void
    {
        Artisan::call('petrovich:articles');

        $count = Page::articles()->count();
        $this->assertGreaterThan(100, $count);
        $this->assertSame(0, Page::articles()->where('source_key', 'like', '%grafik-raboty%')->count(), 'графики работы не переносятся');

        $article = Page::articles()->where('source_key', 'sovet/metalloshtaketnik-vibor')->firstOrFail();
        $this->assertSame('metalloshtaketnik-vibor', $article->slug);
        $this->assertTrue($article->is_published);
        $this->assertNotNull($article->published_at);

        $this->get('/stati')->assertOk()->assertSee('data-article-card', false);
        $this->get('/stati/metalloshtaketnik-vibor')->assertOk()->assertSee($article->title)->assertSee('"@type":"Article"', false);
        $this->get('/sovet/metalloshtaketnik-vibor')->assertRedirect('/stati/metalloshtaketnik-vibor');

        // Повтор — без дублей
        Artisan::call('petrovich:articles');
        $this->assertSame($count, Page::articles()->count());

        // Повтор заголовка в начале текста убирается
        $this->assertSame('<p>Текст</p>', PetrovichArticles::withoutRepeatedTitle('<p><strong>Забор!</strong></p> <p>Текст</p>', 'Забор'));
        $this->assertStringNotContainsString('Как выбрать оптимальную толщину профнастила для вашего забора: секреты прочности и эстетики.</strong>',
            Page::where('source_key', 'sovet/kak-vybrat-optimalnuyu-tolshhinu-profnastila-dlya-vashego-zabora-sekrety-prochnosti-i-estetiki')->value('body'));

        // Заголовок без «крика»
        $this->assertSame('Забор из профнастила!', PetrovichArticles::title('ЗАБОР ИЗ ПРОФНАСТИЛА!!!'));
    }

    public function test_admin_pages_tabs_and_article_form(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/pages?type=article')->assertOk()->assertSee('Добавить статью');
        $this->actingAs($admin)->get('/admin/page/create?type=article')->assertOk()->assertSee('data-article-fields', false);

        $this->actingAs($admin)->post('/admin/page', [
            'title' => 'Как выбрать профнастил', 'slug' => '', 'type' => 'article', 'body' => '<p>Текст</p>',
            'excerpt' => 'Коротко', 'is_published' => '1',
        ])->assertRedirect();

        $page = Page::where('slug', 'kak-vybrat-profnastil')->firstOrFail();
        $this->assertSame(Page::ARTICLE, $page->type);
        $this->assertNotNull($page->published_at);
        $this->get('/stati/kak-vybrat-profnastil')->assertOk()->assertSee('Как выбрать профнастил');

        // Смена адреса статьи — редирект
        $page->update(['slug' => 'vybor-profnastila']);
        $this->get('/stati/kak-vybrat-profnastil')->assertRedirect('/stati/vybor-profnastila');
    }

    public function test_html_keeps_safe_images_and_tables(): void
    {
        $html = Html::clean('<p>a</p><img src="/storage/a.webp" onerror="x()"><img src="javascript:alert(1)"><img src="http://evil/x.png"><table><tr><td style="x">1</td></tr></table>');

        $this->assertStringContainsString('<img src="/storage/a.webp" alt="" loading="lazy">', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('<td>1</td>', $html);
    }
}
