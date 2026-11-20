<?php

namespace Tests\Feature\Storefront;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Главная: слайдер баннеров, карусели «Акции», «Новинки», «Хиты» (по 10, «Все»),
 * затем «Популярные товары». Пустой блок не выводится.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + [
            'name' => "Товар {$n}", 'sku' => "H-{$n}", 'price' => 10, 'stock' => 5,
            'category_id' => $this->category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_slider_shows_only_active_banners_in_order(): void
    {
        Banner::create(['title' => 'Второй', 'image_desktop' => 'banners/b.webp', 'sort_order' => 2]);
        Banner::create(['title' => 'Первый', 'image_desktop' => 'banners/a.webp', 'image_mobile' => 'banners/a-m.webp', 'url' => '/promo', 'sort_order' => 1]);
        Banner::create(['title' => 'Выключен', 'image_desktop' => 'banners/c.webp', 'sort_order' => 0, 'is_active' => false]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-banner-slider', $html);
        $this->assertSame(2, preg_match_all('#data-banner[\s>]#', $html));
        $this->assertStringNotContainsString('Выключен', $html);
        $this->assertMatchesRegularExpression('#alt="Первый".*alt="Второй"#s', $html);
        $this->assertStringContainsString('media="(max-width: 767px)"', $html);
        $this->assertStringContainsString('href="/promo"', $html);
        $this->assertStringContainsString('data-banner-dots', $html);
    }

    public function test_no_banners_no_slider(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-banner-slider', false);
    }

    public function test_carousels_have_up_to_ten_products_and_all_link(): void
    {
        foreach (range(1, 12) as $i) {
            $this->product(['is_promo' => true]);
        }
        $this->product(['is_new' => true, 'name' => 'Свежая новинка']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, preg_match_all('#data-carousel[\s>]#', $html), 'Хитов нет — карусель не выводится');
        $this->assertStringContainsString('aria-label="Акции"', $html);
        $this->assertStringContainsString('aria-label="Новинки"', $html);
        $this->assertStringNotContainsString('aria-label="Хиты"', $html);

        $promo = substr($html, strpos($html, 'aria-label="Акции"'), strpos($html, 'aria-label="Новинки"') - strpos($html, 'aria-label="Акции"'));
        $this->assertSame(10, substr_count($promo, 'data-product-card'));
        $this->assertStringContainsString('href="/promo"', $promo);
        $this->assertStringContainsString('Арт. ', $promo);
    }

    public function test_order_slider_carousels_popular(): void
    {
        Banner::create(['image_desktop' => 'banners/a.webp']);
        $this->product(['is_hit' => true]);

        $this->get('/')->assertSeeInOrder(['data-banner-slider', 'data-home-carousels', 'aria-label="Хиты"', 'data-home-popular'], false);
    }

    public function test_popular_block_shows_only_hits(): void
    {
        $this->product(['name' => 'Обычный товар']);
        $this->assertStringNotContainsString('data-home-popular', $this->get('/')->getContent(), 'хитов нет — блока нет');

        $this->product(['name' => 'Товар-хит', 'is_hit' => true]);
        $html = $this->get('/')->getContent();
        $popular = substr($html, strpos($html, 'data-home-popular'));
        $this->assertStringContainsString('Товар-хит', $popular);
        $this->assertStringNotContainsString('Обычный товар', $popular);
    }

    public function test_empty_catalog_has_no_empty_blocks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('data-carousel', false)
            ->assertDontSee('data-home-popular', false);
    }
}
