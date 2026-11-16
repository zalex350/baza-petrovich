<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\User;
use App\Services\CatalogMenu;
use App\Services\CategoryTiles;
use App\Services\Images\PlaceholderImages;
use App\Services\Images\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фото-заглушки товаров (product_images.is_placeholder, PlaceholderImages, images:placeholders) не показываются
 * нигде; настройка «Фото на плитках разделов» (CategoryTiles::SETTING): только выбранные вручную (по умолчанию) /
 * автоматически — самый продаваемый товар.
 */
class TilePhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ProductImage::disk());
    }

    private function category(string $name, array $attributes = []): Category
    {
        static $n = 0;
        $n++;

        return Category::create($attributes + ['name' => $name, 'slug' => "t-{$n}"]);
    }

    private function product(Category $category, array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + ['name' => "Товар {$n}", 'sku' => "T-{$n}", 'price' => 1, 'stock' => 1,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true]);
    }

    /** Фото с файлом на диске (содержимое — $content) */
    private function photo(Product $product, string $path, array $attributes = [], ?string $content = null): ProductImage
    {
        if ($content !== null || ! array_key_exists('file', $attributes)) {
            Storage::disk(ProductImage::disk())->put($path, $content ?? "image {$path}");
        }
        unset($attributes['file']);

        return ProductImage::create($attributes + ['product_id' => $product->id, 'path' => $path, 'is_main' => true]);
    }

    private function sold(Product $product, int $quantity): void
    {
        OrderItem::create([
            'order_id' => Order::create(['user_id' => User::factory()->create()->id, 'status' => OrderStatus::Completed])->id,
            'product_id' => $product->id, 'quantity' => $quantity, 'price' => 1,
        ]);
    }

    // --- Заглушки ---

    public function test_placeholder_is_never_main_image_nor_in_gallery(): void
    {
        $category = $this->category('Смесители');

        $mixed = $this->product($category);
        $this->photo($mixed, 'products/stub.jpg', ['is_placeholder' => true]);
        $this->photo($mixed, 'products/real.jpg', ['is_main' => false, 'sort_order' => 2]);

        $only = $this->product($category);
        $this->photo($only, 'products/only-stub.jpg', ['is_placeholder' => true]);

        $this->assertSame('products/real.jpg', $mixed->fresh()->mainImage->path);
        $this->assertSame(['products/real.jpg'], $mixed->fresh()->gallery()->pluck('path')->all());
        $this->assertNull($only->fresh()->mainImage);
        $this->assertFalse(Product::whereKey($only->id)->whereHas('mainImage')->exists());

        // Списки витрины и карточка: файла заглушки нет, у товара без настоящего фото — общая заглушка сайта
        $list = $this->get('/catalog/'.$category->slug)->assertOk()->getContent();
        $this->assertStringContainsString('real.jpg', $list);
        $this->assertStringNotContainsString('stub.jpg', $list);

        $card = $this->get('/product/'.$mixed->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('stub.jpg', $card);
        $this->assertStringNotContainsString('only-stub.jpg', $this->get('/product/'.$only->id)->assertOk()->getContent());
    }

    public function test_placeholder_name_patterns(): void
    {
        foreach (['no-photo.jpg', 'NoImage.png', 'no_foto.webp', 'placeholder.png', 'заглушка.jpg', 'нет фото.png', 'nophoto', 'img/no-img.gif', 'default-image.jpg', 'без_фото.jpg'] as $name) {
            $this->assertTrue(PlaceholderImages::nameLooksLike($name), $name);
        }
        foreach (['L4755-2.jpg', 'mixer-nordic.webp', 'piano.png', 'nosik-dlinnyi.jpg', 'notebook.jpg', 'image-1.jpg', null, ''] as $name) {
            $this->assertFalse(PlaceholderImages::nameLooksLike($name), (string) $name);
        }
    }

    public function test_upload_marks_placeholder_by_name_or_known_content(): void
    {
        $service = app(ProductImageService::class);
        $category = $this->category('Смесители');

        $stub = $service->upload($this->product($category), UploadedFile::fake()->image('no-photo.png', 40, 40));
        $this->assertTrue($stub->is_placeholder);
        $this->assertNotNull($stub->content_hash);

        // Тот же файл под другим именем — тоже заглушка; другой файл — настоящее фото
        $same = $service->upload($this->product($category), UploadedFile::fake()->image('12345.png', 40, 40));
        $this->assertSame($stub->content_hash, $same->content_hash);
        $this->assertTrue($same->is_placeholder);

        $real = $service->upload($this->product($category), UploadedFile::fake()->image('mixer.png', 300, 200));
        $this->assertFalse($real->is_placeholder);

        // Замена файла снимает пометку
        $service->replace($stub, UploadedFile::fake()->image('mixer-real.png', 120, 80));
        $this->assertFalse($stub->fresh()->is_placeholder);
    }

    public function test_command_reports_then_marks_placeholders_and_counts_real_photos(): void
    {
        $mixers = $this->category('Смесители');
        $kitchen = $this->category('Для кухни', ['parent_id' => $mixers->id]);
        $pipes = $this->category('Трубы');

        $byName = $this->photo($this->product($kitchen), 'products/no-photo.jpg');
        $missing = $this->photo($this->product($kitchen), 'products/gone.jpg', ['file' => false]);
        $dupes = collect(range(1, 3))->map(fn ($i) => $this->photo($this->product($pipes), "products/sup-{$i}.jpg", [], 'same bytes'));
        $real = $this->photo($this->product($kitchen), 'products/real-1.jpg');
        $this->photo($this->product($pipes), 'products/real-2.jpg');
        $this->product($pipes); // без фото
        $this->photo($this->product($pipes, ['is_published' => false]), 'products/draft.jpg'); // не на витрине

        // Отчёт: база не меняется
        $this->assertSame(0, Artisan::call('images:placeholders', ['--min-products' => 3]));
        $out = Artisan::output();
        $this->assertStringContainsString('найдено заглушек: 5', $out);
        $this->assertMatchesRegularExpression('/\|\s*Смесители\s*\|\s*1\s*\|\s*3\s*\|/u', $out);
        $this->assertMatchesRegularExpression('/\|\s*Трубы\s*\|\s*1\s*\|\s*5\s*\|/u', $out);
        $this->assertMatchesRegularExpression('/\|\s*ВСЕГО\s*\|\s*2\s*\|\s*8\s*\|/u', $out);
        $this->assertSame(0, ProductImage::where('is_placeholder', true)->count());

        $this->artisan('images:placeholders', ['--min-products' => 3, '--mark' => true])->assertSuccessful();

        $marked = ProductImage::where('is_placeholder', true)->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$byName->id, $missing->id])->merge($dupes->pluck('id'))->sort()->values()->all(), $marked);
        $this->assertSame(sha1('same bytes'), $dupes->first()->fresh()->content_hash);
        $this->assertFalse($real->fresh()->is_placeholder);

        // Меньше товаров с одинаковым файлом, чем порог, — не заглушка; --reset снимает прежние пометки
        $this->artisan('images:placeholders', ['--min-products' => 4, '--mark' => true, '--reset' => true])->assertSuccessful();
        $this->assertSame(2, ProductImage::where('is_placeholder', true)->count());
    }

    // --- Плитки разделов ---

    public function test_tiles_manual_by_default_show_only_chosen_or_icon(): void
    {
        $this->assertSame(CategoryTiles::MANUAL, CategoryTiles::mode());

        $root = $this->category('Смесители', ['icon' => 'plumb:faucet']);
        $popular = $this->product($root, ['stock' => 9]);
        $this->photo($popular, 'products/popular.jpg');
        $this->sold($popular, 10);
        $chosen = $this->product($root);
        $this->photo($chosen, 'products/chosen.jpg');
        CatalogMenu::flush();
        $tiles = app(CategoryTiles::class);

        $this->assertNull($tiles->url($root, [$root->id]));
        $this->assertNull($tiles->forTree(app(CatalogMenu::class)->tree())[$root->id]);
        $this->assertMatchesRegularExpression('/data-category-tile="'.$root->id.'".*?<svg[^>]*data-category-icon/s', $this->get('/catalog')->getContent());

        $root->update(['tile_product_id' => $chosen->id]);
        $this->assertStringEndsWith('chosen.jpg', $tiles->url($root->fresh(), [$root->id]));
        $this->assertStringEndsWith('chosen.jpg', $tiles->forTree(app(CatalogMenu::class)->tree())[$root->id]);

        // Выбранный товар с одной заглушкой — снова иконка
        $chosen->images()->update(['is_placeholder' => true]);
        CatalogMenu::flush();
        $this->assertNull($tiles->url($root->fresh(), [$root->id]));
        $this->assertNull($tiles->forTree(app(CatalogMenu::class)->tree())[$root->id]);
    }

    public function test_tiles_auto_take_best_seller_with_real_photo(): void
    {
        Setting::put([CategoryTiles::SETTING => CategoryTiles::AUTO]);

        $root = $this->category('Смесители');
        $stub = $this->product($root, ['stock' => 5]);
        $this->photo($stub, 'products/stub.jpg', ['is_placeholder' => true]);
        $this->sold($stub, 100);
        $hit = $this->product($root, ['stock' => 1]);
        $this->photo($hit, 'products/hit.jpg');
        $this->sold($hit, 3);
        $this->photo($this->product($root, ['stock' => 50]), 'products/calm.jpg');
        CatalogMenu::flush();
        $tiles = app(CategoryTiles::class);

        $this->assertStringEndsWith('hit.jpg', $tiles->url($root, [$root->id]));
        $this->assertStringEndsWith('hit.jpg', $tiles->forTree(app(CatalogMenu::class)->tree())[$root->id]);
        $this->assertStringNotContainsString('stub.jpg', $this->get('/')->getContent());
    }

    public function test_admin_switches_tile_photo_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $root = $this->category('Смесители');
        $product = $this->product($root);
        $this->photo($product, 'products/hit.jpg');
        CatalogMenu::flush();
        app(CategoryTiles::class)->forTree(app(CatalogMenu::class)->tree());

        $this->actingAs($admin)->get('/admin/categories')->assertOk()
            ->assertSee('data-tile-mode', false)
            ->assertSee('Фото на плитках разделов')
            ->assertSee('Без них — иконка раздела.');

        $this->actingAs($admin)->post('/admin/categories/tile-photos', ['tile_mode' => 'auto'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(CategoryTiles::AUTO, CategoryTiles::mode());
        // Кэш плиток сброшен: сразу самый продаваемый
        $this->assertStringEndsWith('hit.jpg', app(CategoryTiles::class)->forTree(app(CatalogMenu::class)->tree())[$root->id]);

        $this->actingAs($admin)->post('/admin/categories/tile-photos', ['tile_mode' => 'random'])->assertSessionHasErrors('tile_mode');
        $this->actingAs(User::factory()->create())->post('/admin/categories/tile-photos', ['tile_mode' => 'manual'])->assertForbidden();
        $this->assertSame(CategoryTiles::AUTO, CategoryTiles::mode());
    }
}
