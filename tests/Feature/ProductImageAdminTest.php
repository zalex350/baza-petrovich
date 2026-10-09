<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Images\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фото товара в админке (SRS §10.4.8, BR-051…BR-054, ERR-010): загрузка, размеры,
 * основное, порядок, замена, удаление, доступ.
 */
class ProductImageAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();

        $this->product = Product::create([
            'name'         => 'Grohe Start',
            'sku'          => 'GR-001',
            'price'        => 100,
            'stock'        => 5,
            'is_active'    => true,
            'is_published' => true,
        ]);
    }

    // --- Помощники: настоящие изображения через GD ---

    private function imageBytes(string $format, int $width = 1200, int $height = 900): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, intdiv($width, 2), $height, imagecolorallocate($im, 200, 30, 30));

        ob_start();
        match ($format) {
            'jpg'  => imagejpeg($im, null, 85),
            'png'  => imagepng($im),
            'webp' => imagewebp($im, null, 80),
            'gif'  => imagegif($im),
        };

        return ob_get_clean();
    }

    /**
     * Файл с заданным содержимым и именем; тип определяется по содержимому (finfo).
     */
    private function file(string $name, string $bytes, string $clientMime = 'image/jpeg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    private function image(string $name = 'photo.jpg', int $width = 1200, int $height = 900): UploadedFile
    {
        $format = pathinfo($name, PATHINFO_EXTENSION) === 'jpeg' ? 'jpg' : pathinfo($name, PATHINFO_EXTENSION);

        return $this->file($name, $this->imageBytes($format, $width, $height));
    }

    /**
     * JPEG с EXIF Orientation (6 — повёрнут на 90° по часовой, как снимает телефон).
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $jpeg = $this->imageBytes('jpg', $width, $height);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00\x00\x00\x00\x00";
        $exif = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    private function upload(array $files)
    {
        return $this->actingAs($this->admin)
            ->from("/admin/product/{$this->product->id}")
            ->post("/admin/product/{$this->product->id}/images", ['images' => $files]);
    }

    private function url(ProductImage $image, string $action): string
    {
        return "/admin/product/{$this->product->id}/images/{$image->id}/{$action}";
    }

    /**
     * @return list<int>
     */
    private function order(): array
    {
        return $this->product->images()->pluck('id')->all();
    }

    private function mainCount(): int
    {
        return ProductImage::where('product_id', $this->product->id)->where('is_main', true)->count();
    }

    // --- Загрузка: форматы, размер, MIME ---

    public function test_admin_uploads_jpg_png_webp_at_once(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpeg'), $this->image('c.png'), $this->image('d.webp')])
            ->assertRedirect("/admin/product/{$this->product->id}#images")
            ->assertSessionHas('success', 'Загружено фото: 4')
            ->assertSessionHasNoErrors();

        $images = $this->product->images()->get();

        $this->assertSame(['a.jpg', 'b.jpeg', 'c.png', 'd.webp'], $images->pluck('original_name')->all());
        $this->assertSame([1, 2, 3, 4], $images->pluck('sort_order')->all());

        foreach ($images as $image) {
            // Имя на диске случайное, не исходное; папка товара
            $this->assertMatchesRegularExpression("#^products/{$this->product->id}/[a-z0-9]{32}-large\\.webp$#", $image->path);

            foreach ($image->paths() as $path) {
                Storage::disk('public')->assertExists($path);
            }
        }
    }

    public function test_unsupported_format_is_rejected(): void
    {
        $this->upload([$this->file('anim.gif', $this->imageBytes('gif'), 'image/gif')])
            ->assertSessionHasErrors(['images.0' => 'Файл «anim.gif» — недопустимый формат. Можно загружать JPG, PNG и WebP']);

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_file_larger_than_10_mb_is_rejected(): void
    {
        $this->upload([UploadedFile::fake()->create('huge.jpg', 10 * 1024 + 1, 'image/jpeg')])
            ->assertSessionHasErrors(['images.0' => 'Файл «huge.jpg» больше 10 МБ']);

        $this->assertSame(0, ProductImage::count());
    }

    public function test_mime_is_checked_by_content_not_extension(): void
    {
        // Текст и PHP-скрипт с расширением картинки и «картиночным» MIME от клиента
        $this->upload([
            $this->file('photo.jpg', "просто текст\n", 'image/jpeg'),
            $this->file('shell.png', '<?php echo "hacked"; ?>', 'image/png'),
        ])->assertSessionHasErrors(['images.0', 'images.1']);

        // Настоящий PNG с расширением .jpg — принимается: важен тип содержимого
        $this->upload([$this->file('really-png.jpg', $this->imageBytes('png'))])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ProductImage::count());
    }

    public function test_broken_image_is_reported_and_others_are_uploaded(): void
    {
        // Начало настоящего JPEG: MIME image/jpeg, но декодировать нельзя
        $broken = $this->file('broken.jpg', substr($this->imageBytes('jpg'), 0, 300));

        $this->upload([$this->image('good.jpg'), $broken])
            ->assertSessionHas('success', 'Загружено фото: 1')
            ->assertSessionHasErrors(['images' => 'Файл «broken.jpg» повреждён или не является изображением']);

        $this->assertSame(['good.jpg'], ProductImage::pluck('original_name')->all());
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    public function test_upload_without_files_is_rejected(): void
    {
        $this->upload([])->assertSessionHasErrors(['images' => 'Выберите файлы для загрузки']);
    }

    // --- Размеры ---

    public function test_sizes_are_generated_in_webp_keeping_proportions(): void
    {
        $this->upload([$this->image('wide.jpg', 3000, 1500)])->assertSessionHasNoErrors();

        $image = ProductImage::sole();
        $disk = Storage::disk('public');

        foreach (['thumb' => [300, 150], 'medium' => [800, 400], 'large' => [1600, 800]] as $size => [$w, $h]) {
            $info = getimagesizefromstring($disk->get($image->pathFor($size)));

            $this->assertSame([$w, $h], [$info[0], $info[1]], $size);
            $this->assertSame('image/webp', $info['mime']);
        }

        $this->assertSame([1600, 800], [$image->width, $image->height]);
        $this->assertSame([300, 150], $image->dimensions('thumb'));
    }

    public function test_small_image_is_not_upscaled(): void
    {
        $this->upload([$this->image('small.png', 200, 120)])->assertSessionHasNoErrors();

        $image = ProductImage::sole();

        foreach (ProductImage::SIZES as $size) {
            $info = getimagesizefromstring(Storage::disk('public')->get($image->pathFor($size)));
            $this->assertSame([200, 120], [$info[0], $info[1]], $size);
        }
    }

    public function test_exif_orientation_is_applied(): void
    {
        $this->upload([$this->file('phone.jpg', $this->jpegWithOrientation(400, 200, 6))])->assertSessionHasNoErrors();

        // Снято «лёжа» 400×200 с Orientation=6 — на сайте стоит 200×400
        $image = ProductImage::sole();
        $this->assertSame([200, 400], [$image->width, $image->height]);

        $info = getimagesizefromstring(Storage::disk('public')->get($image->pathFor('thumb')));
        $this->assertSame([150, 300], [$info[0], $info[1]]);
    }

    // --- Основное фото ---

    public function test_first_upload_becomes_main_and_only_one_main(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg')]);
        $this->upload([$this->image('c.jpg')]);

        $this->assertSame(1, $this->mainCount());
        $this->assertTrue(ProductImage::where('original_name', 'a.jpg')->value('is_main'));
    }

    public function test_admin_changes_main(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')]);
        $c = ProductImage::where('original_name', 'c.jpg')->sole();

        $this->actingAs($this->admin)->post($this->url($c, 'main'))
            ->assertSessionHas('success', 'Основное фото изменено');

        $this->assertSame(1, $this->mainCount());
        $this->assertTrue($c->fresh()->is_main);

        // Повторно — по-прежнему одно основное
        app(ProductImageService::class)->makeMain($c->fresh());
        $this->assertSame(1, $this->mainCount());
    }

    // --- Порядок ---

    public function test_reorder_by_drag_and_drop_saves_without_reload(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')]);
        [$a, $b, $c] = $this->order();

        // Чужой id и дубликаты игнорируются
        $other = Product::create(['name' => 'Other', 'sku' => 'OT-1', 'price' => 1, 'stock' => 1]);
        $foreign = ProductImage::create(['product_id' => $other->id, 'path' => 'x.jpg', 'is_main' => true]);

        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$this->product->id}/images/reorder", ['ids' => [$c, $foreign->id, $a, $c]])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Порядок сохранён']);

        // b не передан — идёт следом
        $this->assertSame([$c, $a, $b], $this->order());
        $this->assertSame($other->id, $foreign->fresh()->product_id);
    }

    public function test_move_up_and_down_without_js(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')]);
        [$a, $b, $c] = $this->order();

        $this->actingAs($this->admin)->post($this->url(ProductImage::find($c), 'move'), ['direction' => 'up'])
            ->assertRedirect("/admin/product/{$this->product->id}#images");
        $this->assertSame([$a, $c, $b], $this->order());

        $this->actingAs($this->admin)->post($this->url(ProductImage::find($a), 'move'), ['direction' => 'down']);
        $this->assertSame([$c, $a, $b], $this->order());

        // За край — без изменений
        $this->actingAs($this->admin)->post($this->url(ProductImage::find($c), 'move'), ['direction' => 'up']);
        $this->assertSame([$c, $a, $b], $this->order());

        $this->actingAs($this->admin)->post($this->url(ProductImage::find($c), 'move'), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');
    }

    // --- Удаление ---

    public function test_delete_removes_all_files_and_reassigns_main(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')]);
        [$a, $b, $c] = $this->order();

        // Порядок c, b, a — после удаления основного (a) основным становится первое по порядку (c)
        $this->actingAs($this->admin)->postJson("/admin/product/{$this->product->id}/images/reorder", ['ids' => [$c, $b, $a]]);

        $main = ProductImage::find($a);
        $paths = $main->paths();

        $this->actingAs($this->admin)->post($this->url($main, 'delete'))
            ->assertSessionHas('success', 'Фото удалено');

        $this->assertNull(ProductImage::find($a));

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertTrue(ProductImage::find($c)->is_main);
        $this->assertSame(1, $this->mainCount());
        $this->assertCount(6, Storage::disk('public')->allFiles());
    }

    public function test_deleting_last_image_leaves_no_files(): void
    {
        $this->upload([$this->image('a.jpg')]);

        $this->actingAs($this->admin)->post($this->url(ProductImage::sole(), 'delete'));

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_page_shows_images_block_with_confirmation(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg')]);

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Изображения')
            ->assertSee('name="images[]"', false)
            ->assertSee('data-main-badge', false)
            ->assertSee('Сделать основным')
            ->assertSee("confirm('Удалить фото «a.jpg»? Файлы всех размеров будут удалены.')", false);
    }

    // --- Замена ---

    public function test_replace_keeps_position_and_main_flag(): void
    {
        $this->upload([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')]);
        $a = ProductImage::where('original_name', 'a.jpg')->sole();
        $oldPaths = $a->paths();
        $order = $this->order();

        $this->actingAs($this->admin)
            ->post($this->url($a, 'replace'), ['image' => $this->image('new.png', 640, 480)])
            ->assertSessionHas('success', 'Фото заменено');

        $a->refresh();
        $this->assertSame('new.png', $a->original_name);
        $this->assertTrue($a->is_main);
        $this->assertSame($order, $this->order());
        $this->assertSame([640, 480], [$a->width, $a->height]);

        foreach ($oldPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        foreach ($a->paths() as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_replace_with_invalid_file_keeps_old_image(): void
    {
        $this->upload([$this->image('a.jpg')]);
        $a = ProductImage::sole();
        $path = $a->path;

        $this->actingAs($this->admin)
            ->post($this->url($a, 'replace'), ['image' => $this->file('bad.webp', 'not an image', 'image/webp')])
            ->assertSessionHasErrors('image');

        $this->assertSame($path, $a->fresh()->path);
        Storage::disk('public')->assertExists($path);
    }

    // --- Доступ ---

    public function test_only_admin_manages_images(): void
    {
        $this->upload([$this->image('a.jpg')]);
        $image = ProductImage::sole();
        $client = User::factory()->create();

        $requests = [
            ["/admin/product/{$this->product->id}/images", ['images' => [$this->image('x.jpg')]]],
            ["/admin/product/{$this->product->id}/images/reorder", ['ids' => [$image->id]]],
            [$this->url($image, 'main'), []],
            [$this->url($image, 'move'), ['direction' => 'up']],
            [$this->url($image, 'replace'), ['image' => $this->image('y.jpg')]],
            [$this->url($image, 'delete'), []],
        ];

        foreach ($requests as [$url, $data]) {
            $this->actingAs($client)->post($url, $data)->assertForbidden();
        }

        auth()->logout();

        foreach ($requests as [$url, $data]) {
            $this->post($url, $data)->assertRedirect('/login');
        }

        $this->assertSame(1, ProductImage::count());
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    public function test_image_of_another_product_is_not_found(): void
    {
        $this->upload([$this->image('a.jpg')]);
        $image = ProductImage::sole();
        $other = Product::create(['name' => 'Other', 'sku' => 'OT-1', 'price' => 1, 'stock' => 1]);

        $this->actingAs($this->admin)->post("/admin/product/{$other->id}/images/{$image->id}/delete")->assertNotFound();

        $this->assertSame(1, ProductImage::count());
    }
}
