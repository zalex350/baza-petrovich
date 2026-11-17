<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\B2bPricing;
use Tests\TestCase;

/**
 * Фото товара на витрине (SRS §4.7, §3.11.3): основное фото в списках, корзине и заказе,
 * галерея карточки, заглушка; перенос прежнего products.image.
 */
class ProductImageStorefrontTest extends TestCase
{
    use B2bPricing;
    use RefreshDatabase;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->product = Product::create([
            'name' => 'Grohe Start',
            'display_name' => 'Смеситель Grohe Start',
            'sku' => 'GR-001',
            'price' => 100,
            'stock' => 5,
            'category_id' => $category->id,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    /**
     * Фото в БД (файлы не нужны — проверяется разметка).
     */
    private function addImage(Product $product, string $name, bool $main = false, int $order = 1): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'path' => "products/{$product->id}/{$name}-large.webp",
            'original_name' => "{$name}.jpg",
            'is_main' => $main,
            'sort_order' => $order,
            'width' => 1600,
            'height' => 1200,
        ]);
    }

    private function thumb(string $name): string
    {
        return "/storage/products/{$this->product->id}/{$name}-thumb.webp";
    }

    // --- Основное фото в списках ---

    public function test_lists_show_main_thumb_with_lazy_size_and_alt(): void
    {
        $this->addImage($this->product, 'second', order: 1);
        $this->addImage($this->product, 'main', main: true, order: 2);

        foreach (['/catalog/smesiteli', '/search?q=Grohe', '/'] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('src="'.$this->thumb('main').'"', false)
                ->assertDontSee($this->thumb('second'), false)
                ->assertSee('alt="Смеситель Grohe Start"', false)
                ->assertSee('width="300"', false)
                ->assertSee('height="225"', false)
                ->assertSee('loading="lazy"', false)
                ->assertSee("onerror=\"this.onerror=null;this.src='/images/no-photo.svg'\"", false);
        }

        $this->actingAs($this->client)->getJson('/search/suggest?q=Grohe')
            ->assertJsonPath('products.0.image', $this->thumb('main'));
    }

    public function test_cart_and_order_cards_show_main_thumb(): void
    {
        $this->addImage($this->product, 'main', main: true);

        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 1]);

        $this->actingAs($this->client)->get('/order')
            ->assertOk()
            ->assertSee('src="'.$this->thumb('main').'"', false);

        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client));
        $orderId = DB::table('orders')->value('id');

        $this->actingAs($this->client)->get("/order/{$orderId}")
            ->assertOk()
            ->assertSee('src="'.$this->thumb('main').'"', false);

        $this->actingAs(User::factory()->admin()->create())->get("/admin/order/{$orderId}")
            ->assertOk()
            ->assertSee('src="'.$this->thumb('main').'"', false);
    }

    public function test_placeholder_without_photo(): void
    {
        foreach (['/catalog/smesiteli', '/search?q=Grohe', '/', "/product/{$this->product->id}"] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('src="/images/no-photo.svg"', false)
                ->assertSee('data-placeholder', false)
                ->assertDontSee('data-gallery', false);
        }

        $this->actingAs($this->client)->getJson('/search/suggest?q=Grohe')
            ->assertJsonPath('products.0.image', '/images/no-photo.svg');

        $this->assertFileExists(public_path('images/no-photo.svg'));
    }

    public function test_main_image_is_not_loaded_per_product(): void
    {
        foreach (range(1, 5) as $i) {
            $product = Product::create([
                'name' => "Grohe {$i}", 'sku' => "GR-1{$i}", 'price' => 10, 'stock' => 1,
                'category_id' => $this->product->category_id, 'is_active' => true, 'is_published' => true,
            ]);
            $this->addImage($product, "p{$i}", main: true);
        }

        DB::enableQueryLog();
        $this->actingAs($this->client)->get('/catalog/smesiteli')->assertOk();
        $imageQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_images'));

        $this->assertCount(1, $imageQueries);
    }

    // --- Карточка товара ---

    public function test_product_card_shows_main_medium_and_thumbnails(): void
    {
        $this->addImage($this->product, 'first', order: 1);
        $this->addImage($this->product, 'main', main: true, order: 2);
        $this->addImage($this->product, 'third', order: 3);

        $html = $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-gallery', false)
            ->assertSee('src="/storage/products/'.$this->product->id.'/main-medium.webp"', false)
            ->assertSee('width="800"', false)
            ->assertSee('height="600"', false)
            ->assertSee('data-gallery-thumbs', false)
            ->assertSee('data-lightbox', false)
            ->assertSee('aria-label="Закрыть"', false)
            ->assertSee('aria-label="Следующее фото"', false)
            ->getContent();

        // Миниатюры: основное первым, затем по порядку
        $this->assertSame(
            ['main', 'first', 'third'],
            array_map(fn ($m) => $m, preg_match_all('#/'.$this->product->id.'/(\w+)-thumb\.webp#', $html, $m) ? $m[1] : [])
        );

        // Без JS миниатюра — ссылка на большую версию
        $this->assertStringContainsString('href="/storage/products/'.$this->product->id.'/third-large.webp"', $html);
    }

    public function test_main_photo_has_arrows_swipe_and_active_thumbnail(): void
    {
        $this->addImage($this->product, 'main', main: true, order: 1);
        $this->addImage($this->product, 'second', order: 2);

        $html = $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-gallery-prev', false)
            ->assertSee('data-gallery-next', false)
            ->assertSee('@touchend="touchEnd($event)"', false)
            ->assertSee('@click.prevent="zoom()"', false)
            ->assertSee(':aria-current="current === 1 ? \'true\' : \'false\'"', false)
            ->getContent();

        // Стрелки и на основном фото, и в увеличенном просмотре — один компонент
        $this->assertSame(2, substr_count($html, 'aria-label="Следующее фото"'));
        $this->assertSame(1, substr_count($html, 'x-data="productGallery('));
        $this->assertSame(2, preg_match_all('#\sdata-gallery-thumb\s#', $html));
    }

    public function test_admin_preview_has_same_gallery(): void
    {
        $this->product->update(['is_published' => false]);
        $this->addImage($this->product, 'main', main: true, order: 1);
        $this->addImage($this->product, 'second', order: 2);

        $this->actingAs(User::factory()->admin()->create())
            ->get("/admin/product/{$this->product->id}/preview")
            ->assertOk()
            ->assertSee('x-data="productGallery(', false)
            ->assertSee('data-gallery-next', false)
            ->assertSee('data-gallery-thumbs', false)
            ->assertSee('data-lightbox', false);
    }

    public function test_single_photo_has_no_thumbnails_or_arrows(): void
    {
        $this->addImage($this->product, 'main', main: true);

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-gallery', false)
            ->assertDontSee('data-gallery-thumbs', false)
            ->assertDontSee('aria-label="Следующее фото"', false);
    }

    public function test_guest_sees_gallery_too(): void
    {
        $this->addImage($this->product, 'main', main: true);

        $this->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('main-medium.webp', false);
    }

    // --- Цена уточняется ---

    public function test_price_tbd_instead_of_zero(): void
    {
        $this->product->update(['price' => null]);

        foreach (['/catalog/smesiteli', '/search?q=Grohe', '/', "/product/{$this->product->id}"] as $url) {
            // Раньше выводилось «0.00 BYN (с НДС: 0.00 BYN)»; «0.00 BYN» в шапке — пустая корзина
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('data-price-tbd', false)
                ->assertSee('Цена уточняется')
                ->assertDontSee('Цена по запросу')
                ->assertDontSee('(с НДС: 0.00 BYN)')
                ->assertDontSee('Цена без НДС:');
        }

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertDontSee("action=\"/order/add/{$this->product->id}\"", false);
    }

    // --- Миграция прежнего products.image ---

    public function test_migration_moves_legacy_image_to_gallery_as_main(): void
    {
        $migration = require database_path('migrations/2026_10_07_100000_extend_product_images_table.php');
        $migration->down();

        $this->assertTrue(Schema::hasColumn('products', 'image'));

        DB::table('products')->where('id', $this->product->id)->update(['image' => 'products/grohe-start.jpg']);
        $without = DB::table('products')->insertGetId(['name' => 'Без фото', 'sku' => 'NO-1', 'price' => 1, 'stock' => 1]);
        $emptyString = DB::table('products')->insertGetId(['name' => 'Пусто', 'sku' => 'NO-2', 'price' => 1, 'stock' => 1, 'image' => '']);

        // Фото, загруженное раньше без признака основного
        $legacyRow = DB::table('products')->insertGetId(['name' => 'Старая галерея', 'sku' => 'OLD-1', 'price' => 1, 'stock' => 1]);
        DB::table('product_images')->insert([
            ['product_id' => $legacyRow, 'path' => 'old-1.jpg'],
            ['product_id' => $legacyRow, 'path' => 'old-2.jpg'],
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('products', 'image'));

        $image = ProductImage::where('product_id', $this->product->id)->sole();
        $this->assertSame('products/grohe-start.jpg', $image->path);
        $this->assertSame('grohe-start.jpg', $image->original_name);
        $this->assertTrue($image->is_main);

        // Перенесённое фото без webp-версий отдаётся как есть для всех размеров
        $this->assertFalse($image->hasVariants());
        $this->assertSame('/storage/products/grohe-start.jpg', $image->url('thumb'));
        $this->assertSame('/storage/products/grohe-start.jpg', $image->url('large'));

        $this->assertSame(0, ProductImage::whereIn('product_id', [$without, $emptyString])->count());

        $this->assertSame(['old-1.jpg'], ProductImage::where('product_id', $legacyRow)->where('is_main', true)->pluck('path')->all());

        $this->actingAs($this->client)->get('/catalog/smesiteli')
            ->assertSee('src="/storage/products/grohe-start.jpg"', false);
    }
}
