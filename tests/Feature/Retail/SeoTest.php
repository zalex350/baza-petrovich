<?php

namespace Tests\Feature\Retail;

use App\Http\Controllers\SeoController;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\SeoTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SEO витрины: robots.txt, sitemap.xml, title/description по шаблонам, canonical и noindex,
 * Open Graph, JSON-LD (Product, BreadcrumbList, FAQPage, Organization), тексты разделов.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['seo.index' => true, 'seo.url' => 'https://baza-petrovich.by', 'seo.site_name' => 'Петрович']);
        Cache::forget(SeoController::CACHE_KEY);

        $this->category = Category::create(['name' => 'Профнастил', 'slug' => 'profnastil']);
        $this->product = Product::create([
            'name' => 'Профнастил С8 зелёный',
            'sku' => 'PN-C8',
            'price' => 21.4,
            'stock' => 5,
            'category_id' => $this->category->id,
            'is_active' => true,
            'is_published' => true,
        ]);
        $this->product->forceFill(['unit' => 'м2'])->save();
    }

    public function test_robots_txt_lists_private_paths_and_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /order')
            ->assertSee('Disallow: /search')
            ->assertSee('Sitemap: https://baza-petrovich.by/sitemap.xml');
    }

    public function test_staging_closes_everything(): void
    {
        config(['seo.index' => false]);

        $this->get('/robots.txt')->assertSee("Disallow: /\n", false)->assertDontSee('Sitemap:');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
        $this->get('/product/'.$this->product->id)->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_sitemap_has_shown_catalog_and_pages_only(): void
    {
        $hidden = Category::create(['name' => 'Служебная', 'slug' => 'sluzh', 'is_hidden' => true]);
        $draft = Product::create(['name' => 'Черновик', 'sku' => 'D-1', 'price' => 1, 'stock' => 1, 'category_id' => $this->category->id, 'is_active' => true, 'is_published' => false]);
        $inHidden = Product::create(['name' => 'Скрытый', 'sku' => 'H-1', 'price' => 1, 'stock' => 1, 'category_id' => $hidden->id, 'is_active' => true, 'is_published' => true]);
        Page::updateOrCreate(['slug' => 'delivery'], ['title' => 'Доставка', 'body' => 'x', 'is_published' => true]);
        Page::create(['title' => 'Монтаж забора', 'slug' => 'montazh', 'type' => Page::SERVICE, 'body' => 'x', 'is_published' => true]);

        $xml = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $this->assertNotFalse(simplexml_load_string($xml), 'корректный XML');
        $this->assertStringContainsString('<loc>https://baza-petrovich.by/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://baza-petrovich.by/catalog/profnastil</loc>', $xml);
        $this->assertStringContainsString('<loc>https://baza-petrovich.by/product/'.$this->product->id.'</loc>', $xml);
        $this->assertStringContainsString('<loc>https://baza-petrovich.by/delivery</loc>', $xml);
        $this->assertStringContainsString('<loc>https://baza-petrovich.by/uslugi/montazh</loc>', $xml);
        $this->assertStringNotContainsString('/product/'.$draft->id.'<', $xml);
        $this->assertStringNotContainsString('/product/'.$inHidden->id.'<', $xml);
        $this->assertStringNotContainsString('/catalog/sluzh', $xml);
    }

    public function test_product_page_title_description_canonical_and_schema(): void
    {
        $html = $this->get('/product/'.$this->product->id.'?utm_source=google&gclid=abc')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Профнастил С8 зелёный — цена 21,40 BYN за м2, купить в Минске — Петрович</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Профнастил С8 зелёный за 21,40 BYN за м2. Профнастил — в наличии', $html);
        // Метки рекламы — не дубль: страница индексируется, canonical без параметров
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://baza-petrovich.by/product/'.$this->product->id.'">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $html);

        $product = $this->schema($html, 'Product');
        $this->assertSame('Профнастил С8 зелёный', $product['name']);
        $this->assertSame('21.40', $product['offers']['price']);
        $this->assertSame('BYN', $product['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $product['offers']['availability']);

        $crumbs = $this->schema($html, 'BreadcrumbList');
        $this->assertSame('Профнастил', end($crumbs['itemListElement'])['name']);
    }

    public function test_own_seo_fields_beat_template(): void
    {
        $this->product->update(['seo_title' => 'Свой заголовок', 'seo_description' => 'Своё описание']);

        $this->get('/product/'.$this->product->id)
            ->assertSee('<title>Свой заголовок — Петрович</title>', false)
            ->assertSee('<meta name="description" content="Своё описание">', false);
    }

    public function test_template_from_settings_and_optional_parts(): void
    {
        Setting::put(['seo_product_title' => 'Купить {name}[ по {price}] в {city}']);

        $this->assertSame('Купить Профнастил С8 зелёный по 21,40 BYN за м2 в Минске', SeoTemplates::productTitle($this->product->fresh()));

        // Без цены — часть в скобках пропадает
        $this->product->update(['price' => null]);
        $this->assertSame('Купить Профнастил С8 зелёный в Минске', SeoTemplates::productTitle($this->product->fresh()));
    }

    public function test_category_title_with_min_price_faq_and_text(): void
    {
        Product::create(['name' => 'Профнастил ПС20', 'sku' => 'PN-20', 'price' => 30, 'stock' => 1, 'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true]);
        $this->category->update([
            'description' => '<h2>Как выбрать</h2><p>Текст раздела</p>',
            'faq' => [['q' => 'Режете в размер?', 'a' => 'Да, по длине ската.']],
        ]);

        $html = $this->get('/catalog/profnastil')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Профнастил — купить в Минске, цены от 21,40 BYN — Петрович</title>', $html);
        $this->assertStringContainsString('2 товара', $html);
        $this->assertStringContainsString('data-faq', $html);
        $this->assertStringContainsString('Режете в размер?', $html);
        $this->assertStringContainsString('Текст раздела', $html);

        $faq = $this->schema($html, 'FAQPage');
        $this->assertSame('Да, по длине ската.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function test_filtered_and_sorted_lists_are_noindex(): void
    {
        $this->get('/catalog/profnastil?sort=price_asc')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertDontSee('rel="canonical"', false);

        $this->get('/catalog/profnastil?page=2')
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<link rel="canonical" href="https://baza-petrovich.by/catalog/profnastil?page=2">', false);

        $this->get('/order')->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_home_has_organization_and_search_schema(): void
    {
        Setting::put(['google_site_verification' => 'abc123XYZ_-1', 'contact_email' => 'info@example.by']);

        $html = $this->get('/')->assertOk()->getContent();

        $organization = $this->schema($html, 'Organization');
        $this->assertSame('Петрович', $organization['name']);
        $this->assertCount(2, $organization['subOrganization']);
        $this->assertSame('BY', $organization['subOrganization'][0]['address']['addressCountry']);
        $this->assertSame('WebSite', $this->schema($html, 'WebSite')['@type']);
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc123XYZ_-1">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://baza-petrovich.by/images/og-default.jpg">', $html);
    }

    public function test_admin_edits_category_text_faq_and_seo(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/category/'.$this->category->id.'/edit')
            ->assertOk()
            ->assertSee('name="faq"', false)
            ->assertSee('name="seo_title"', false);

        $this->actingAs($admin)->post('/admin/category/'.$this->category->id, [
            'name' => 'Профнастил',
            'slug' => 'profnastil',
            'description' => '<p>Новый текст</p>',
            'faq' => "Вопрос один?\nОтвет один.\n\nВопрос два?\nОтвет два,\nв две строки.",
            'seo_title' => 'Профнастил в Минске',
            'seo_description' => '',
        ])->assertRedirect();

        $category = $this->category->fresh();
        $this->assertSame('<p>Новый текст</p>', $category->description);
        $this->assertSame([['q' => 'Вопрос один?', 'a' => 'Ответ один.'], ['q' => 'Вопрос два?', 'a' => "Ответ два,\nв две строки."]], $category->faq);
        $this->assertSame('Профнастил в Минске', $category->seo_title);
        $this->assertNull($category->seo_description);
    }

    public function test_seo_command_fills_empty_texts_only(): void
    {
        $this->category->forceFill(['source_key' => 'profnastil'])->save();
        $setka = Category::create(['name' => 'Сетка', 'slug' => 'setka', 'description' => '<p>Свой текст</p>']);
        $setka->forceFill(['source_key' => 'setka'])->save();

        Artisan::call('petrovich:seo', ['--products' => true]);

        $this->assertStringContainsString('Профнастил для забора и кровли', $this->category->fresh()->description);
        $this->assertNotEmpty($this->category->fresh()->faq);
        $this->assertSame('<p>Свой текст</p>', $setka->fresh()->description, 'заполненный текст не перезаписывается');
        $this->assertNotEmpty($setka->fresh()->faq);

        $product = $this->product->fresh();
        $this->assertStringContainsString('Профнастил С8 зелёный из раздела «Профнастил».', $product->description);
        $this->assertStringContainsString('листы нарежем', $product->description);
        $this->assertTrue($product->description_generated, 'ждёт одобрения в админке');
    }

    /** JSON-LD объект страницы по @type */
    private function schema(string $html, string $type): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        foreach ($m[1] as $json) {
            $item = json_decode($json, true);

            if (($item['@type'] ?? null) === $type) {
                return $item;
            }
        }

        $this->fail("Нет разметки {$type}");
    }
}
