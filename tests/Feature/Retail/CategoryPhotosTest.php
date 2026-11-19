<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * petrovich:category-photos — фото разделов из папки; плитка с картинкой раздела — на всю площадь.
 */
class CategoryPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/catphotos-'.uniqid();
        mkdir($this->dir);
        $fake = UploadedFile::fake()->image('x.jpg', 1200, 800);
        copy($fake->getRealPath(), $this->dir.'/setka.jpg');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_sets_category_image_by_source_key(): void
    {
        $setka = Category::create(['name' => 'Сетка', 'slug' => 'setka']);
        $setka->forceFill(['source_key' => 'setka'])->save();
        $other = Category::create(['name' => 'Другое', 'slug' => 'drugoe']);

        $this->artisan('petrovich:category-photos', ['dir' => $this->dir])->expectsOutputToContain('Фото разделов: 1')->assertSuccessful();

        $path = $setka->fresh()->image;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNull($other->fresh()->image);

        // Повтор без --force — не меняет
        $this->artisan('petrovich:category-photos', ['dir' => $this->dir])->expectsOutputToContain('Фото разделов: 0');
        $this->assertSame($path, $setka->fresh()->image);

        // Плитка /catalog: своя картинка — object-cover
        $setka->products()->create(['name' => 'Рабица', 'sku' => 'R-1', 'price' => 10, 'stock' => 1, 'is_active' => true, 'is_published' => true]);
        app(CatalogMenu::class)->flush();
        $this->get('/catalog')->assertOk()->assertSee('object-cover', false)->assertSee($path, false);
    }

    public function test_reset_removes_all_category_images(): void
    {
        $setka = Category::create(['name' => 'Сетка', 'slug' => 'setka']);
        $setka->forceFill(['source_key' => 'setka'])->save();
        $this->artisan('petrovich:category-photos', ['dir' => $this->dir])->assertSuccessful();
        $path = $setka->fresh()->image;

        $this->artisan('petrovich:category-photos', ['--reset' => true])->expectsOutputToContain('Картинок снято: 1')->assertSuccessful();

        $this->assertNull($setka->fresh()->image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_credits_page(): void
    {
        $this->get('/photo-credits')->assertOk()->assertSee('Авторы фото');
    }
}
