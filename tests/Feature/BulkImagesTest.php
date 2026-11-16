<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageImport;
use App\Models\ImageImport;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Images\BulkImport\BulkImages;
use App\Services\Images\BulkImport\ProductLookup;
use App\Services\Images\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Массовая загрузка фото (BulkImages, ProductLookup, ZIP / папка / CSV со ссылками, админка
 * «Товары → Загрузить фото», очередь ProcessImageImport, artisan images:import).
 */
class BulkImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ProductImage::disk());
        Storage::fake(BulkImages::DISK);
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            is_dir($path) ? array_map('unlink', glob("{$path}/*") ?: []) && @rmdir($path) : @unlink($path);
        }

        parent::tearDown();
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($attributes + ['name' => "Товар {$n}", 'sku' => "SKU-{$n}", 'price' => 1, 'stock' => 1,
            'is_active' => true, 'is_published' => true]);
    }

    /** Содержимое картинки: разные размеры — разные файлы, одинаковые — одинаковые */
    private function png(int $width = 40, int $height = 40): string
    {
        // Файл живёт, пока жив объект — держим его до чтения
        $file = UploadedFile::fake()->image('x.png', $width, $height);

        return (string) file_get_contents($file->getRealPath());
    }

    /** @param  array<string, string>  $files  имя => содержимое */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-zip-');
        $this->temp[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function uploadZip(array $files)
    {
        $file = new UploadedFile($this->zip($files), 'photos.zip', 'application/zip', null, true);

        return $this->actingAs($this->admin)->post('/admin/products/images-import', ['source' => 'zip', 'file' => $file]);
    }

    private function lastImport(): ImageImport
    {
        return ImageImport::query()->latest('id')->firstOrFail();
    }

    // --- Сопоставление ---

    public function test_lookup_by_sku_article_brand_and_suffix(): void
    {
        $grohe = $this->product(['sku' => 'GR-1', 'brand' => 'Grohe', 'article' => '32665001']);
        $hansgrohe = $this->product(['sku' => 'HG-1', 'brand' => 'Hansgrohe', 'article' => '32665001']);
        $base = $this->product(['sku' => 'B-1', 'brand' => 'Lemark', 'article' => 'L4755']);
        $dash = $this->product(['sku' => 'B-2', 'brand' => 'Lemark', 'article' => 'L4755-2']);
        $cyr = $this->product(['sku' => 'C-1', 'brand' => 'Lemark', 'article' => 'AKM-10']);
        $lookup = new ProductLookup;

        $this->assertSame(['product_id' => $grohe->id, 'ambiguous' => [], 'order' => 0], $lookup->forFileName('gr-1'));
        $this->assertSame($dash->id, $lookup->forFileName('l4755-2')['product_id'], 'имя целиком важнее суффикса');
        $this->assertSame(['product_id' => $base->id, 'ambiguous' => [], 'order' => 3], $lookup->forFileName('L4755_3'));
        $this->assertSame(['product_id' => $dash->id, 'ambiguous' => [], 'order' => 1], $lookup->forFileName('L4755-2_1'));
        $this->assertSame($cyr->id, $lookup->forFileName('акм-10')['product_id'], 'кириллические двойники латиницы');

        $this->assertNull($lookup->forFileName('32665001')['product_id']);
        $this->assertEqualsCanonicalizing([$grohe->id, $hansgrohe->id], $lookup->forFileName('32665001')['ambiguous']);
        $this->assertSame(['product_id' => $hansgrohe->id, 'ambiguous' => [], 'order' => 0], $lookup->forFileName('HANSGROHE_32665001'));
        $this->assertSame(['product_id' => $grohe->id, 'ambiguous' => [], 'order' => 2], $lookup->forFileName('grohe_32665001-2'));
        $this->assertSame(['product_id' => null, 'ambiguous' => [], 'order' => 0], $lookup->forFileName('nothing_1'));

        $base->delete();
        $this->assertNull((new ProductLookup)->forFileName('L4755_3')['product_id'], 'удалённые не участвуют');
    }

    // --- ZIP: предпросмотр и применение ---

    public function test_zip_preview_does_not_change_database_and_lists_problems(): void
    {
        $a = $this->product(['article' => 'A-100', 'brand' => 'X']);
        $this->product(['article' => 'DUP', 'brand' => 'X']);
        $this->product(['article' => 'DUP', 'brand' => 'Y']);

        $this->uploadZip([
            'A-100.jpg' => $this->png(), 'photos/a-100_2.PNG' => $this->png(50, 50), 'DUP.jpg' => $this->png(),
            'unknown.webp' => $this->png(), 'readme.txt' => 'hi', '__MACOSX/._A-100.jpg' => 'x', '.DS_Store' => 'x',
        ])->assertRedirect();

        $import = $this->lastImport();
        $this->assertSame(ImageImport::DRAFT, $import->status);
        $this->assertSame(2, $import->files_total);
        $this->assertSame(1, $import->products_total);
        $this->assertSame(0, ProductImage::count());

        $this->actingAs($this->admin)->get("/admin/products/images-import/{$import->id}")->assertOk()
            ->assertSee('data-import-apply', false)
            ->assertSee('data-import-found="'.$a->id.'"', false)
            ->assertSee('A-100.jpg, a-100_2.PNG')
            ->assertSee('unknown.webp')
            ->assertSee('data-import-ambiguous', false)
            ->assertSee('readme.txt')
            ->assertDontSee('DS_Store');

        $csv = $this->actingAs($this->admin)->get("/admin/products/images-import/{$import->id}/not-found.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('unknown.webp;"товар не найден"', $csv);
        $this->assertStringContainsString('DUP.jpg;', $csv);
        $this->assertStringContainsString('БРЕНД_АРТИКУЛ', $csv);
    }

    public function test_apply_append_orders_resizes_skips_duplicates_and_broken(): void
    {
        $product = $this->product(['article' => 'A-100', 'brand' => 'X']);
        $existing = app(ProductImageService::class)->upload($product, UploadedFile::fake()->image('old.png', 30, 30));
        $other = $this->product(['sku' => 'S-2']);

        $this->uploadZip([
            'a-100_2.png' => $this->png(60, 60),
            'A-100.png' => $this->png(2400, 1200),
            'A-100_1.png' => $this->png(30, 30), // такой же, как уже есть
            'A-100_3.png' => $this->png(60, 60), // такой же, как _2
            'A-100_4.jpg' => 'not an image',
            's-2.png' => $this->png(70, 70),
        ]);
        $import = $this->lastImport();

        $this->actingAs($this->admin)->post("/admin/products/images-import/{$import->id}/apply", ['mode' => 'append'])
            ->assertRedirect("/admin/products/images-import/{$import->id}");

        $import->refresh();
        $this->assertSame(ImageImport::DONE, $import->status);
        $this->assertSame([6, 3, 2, 1], [$import->processed, $import->added, $import->duplicates, $import->failed]);
        $this->assertSame('A-100_4.jpg', $import->report['errors'][0]['name']);
        $this->assertFalse(Storage::disk(BulkImages::DISK)->exists($import->path), 'ZIP удалён после загрузки');

        $images = $product->images()->get();
        $this->assertSame([$existing->id], $images->where('is_main', true)->pluck('id')->all(), 'основное не меняется');
        $this->assertSame(['old.png', 'A-100.png', 'a-100_2.png'], $images->pluck('original_name')->all());
        $this->assertSame([1600, 800], [$images[1]->width, $images[1]->height]);
        $this->assertTrue(str_ends_with($images[1]->path, '-large.webp'));
        $this->assertTrue($other->images()->first()->is_main);

        $this->actingAs($this->admin)->get("/admin/products/images-import/{$import->id}")->assertOk()
            ->assertSee('data-import-progress', false)->assertSee('Добавлено фото: 3, дублей: 2, ошибок: 1')
            ->assertSee('data-import-error', false);
        $this->actingAs($this->admin)->get('/admin/products/images-import')->assertOk()
            ->assertSee('data-import-row="'.$import->id.'"', false)->assertSee('добавлено 3, дублей 2, ошибок 1');
    }

    public function test_apply_replace_removes_old_only_when_new_added(): void
    {
        $service = app(ProductImageService::class);
        $replaced = $this->product(['sku' => 'R-1']);
        $service->upload($replaced, UploadedFile::fake()->image('old-1.png', 30, 30));
        $service->upload($replaced, UploadedFile::fake()->image('old-2.png', 31, 31));
        $kept = $this->product(['sku' => 'K-1']);
        $service->upload($kept, UploadedFile::fake()->image('keep.png', 30, 30));

        $this->uploadZip(['R-1.png' => $this->png(30, 30), 'R-1_2.png' => $this->png(80, 80), 'K-1.png' => 'broken']);
        $import = $this->lastImport();
        $this->actingAs($this->admin)->post("/admin/products/images-import/{$import->id}/apply", ['mode' => 'replace']);

        $import->refresh();
        $this->assertSame([2, 0, 1, 2], [$import->added, $import->duplicates, $import->failed, $import->removed]);
        $this->assertSame(['R-1.png', 'R-1_2.png'], $replaced->images()->pluck('original_name')->all());
        $this->assertSame('R-1.png', $replaced->fresh()->mainImage->original_name);
        $this->assertSame(['keep.png'], $kept->images()->pluck('original_name')->all(), 'новое не добавилось — прежние остаются');
    }

    public function test_queue_processes_in_batches_and_resumes(): void
    {
        Queue::fake();
        foreach (range(1, 3) as $i) {
            $this->product(['sku' => "Q-{$i}"]);
        }
        $this->uploadZip(['Q-1.png' => $this->png(31, 31), 'Q-2.png' => $this->png(32, 32), 'Q-3.png' => $this->png(33, 33)]);
        $import = $this->lastImport();

        $this->actingAs($this->admin)->post("/admin/products/images-import/{$import->id}/apply", ['mode' => 'append']);
        Queue::assertPushed(ProcessImageImport::class, fn ($job) => $job->importId === $import->id);
        $this->assertSame(ImageImport::QUEUED, $import->fresh()->status);
        $this->actingAs($this->admin)->post("/admin/products/images-import/{$import->id}/apply", ['mode' => 'append'])->assertSessionHasErrors('mode');

        $bulk = app(BulkImages::class);
        $this->assertFalse($bulk->runBatch($import, 0));
        $this->assertSame([ImageImport::RUNNING, 1], [$import->fresh()->status, $import->fresh()->processed]);
        $this->actingAs($this->admin)->getJson("/admin/products/images-import/{$import->id}/status")
            ->assertOk()->assertJson(['status' => 'running', 'active' => true, 'processed' => 1, 'total' => 3, 'percent' => 33]);

        $this->assertFalse($bulk->runBatch($import, 0));
        $this->assertTrue($bulk->runBatch($import, 0));
        $this->assertSame([ImageImport::DONE, 3, 3], [$import->fresh()->status, $import->fresh()->processed, ProductImage::count()]);

        // Задание ставит продолжение, пока не закончит
        $job = new ProcessImageImport($import->id);
        $job->failed(new \RuntimeException('boom'));
        $this->assertSame(ImageImport::FAILED, $import->fresh()->status);
    }

    // --- CSV со ссылками ---

    public function test_csv_urls_download_with_errors(): void
    {
        $a = $this->product(['sku' => 'U-1']);
        $b = $this->product(['sku' => 'U-2', 'article' => 'ART-2', 'brand' => 'B']);
        $png = $this->png(45, 45);
        Http::preventStrayRequests();
        Http::fake([
            'img.test/a.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'img.test/a2.png*' => Http::response($this->png(46, 46), 200),
            'img.test/missing.png' => Http::response('', 404),
            'img.test/page' => Http::response('<html>no</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $csv = "sku;url\nU-1;https://img.test/a.png\nu-1;https://img.test/a2.png?x=1\nB_ART-2;https://img.test/missing.png\nart-2;https://img.test/page\nNOPE;https://img.test/x.png\nU-2;ftp://img.test/a.png\n";
        $file = UploadedFile::fake()->createWithContent('photos.csv', $csv);
        $this->actingAs($this->admin)->post('/admin/products/images-import', ['source' => 'csv', 'file' => $file])->assertRedirect();

        $import = $this->lastImport();
        $this->assertSame([4, 2, 1, 1], [$import->files_total, $import->products_total, count($import->report['not_found']), count($import->report['skipped'])]);
        Http::assertNothingSent();

        $this->actingAs($this->admin)->post("/admin/products/images-import/{$import->id}/apply", ['mode' => 'append']);

        $import->refresh();
        $this->assertSame([2, 2], [$import->added, $import->failed]);
        $this->assertSame(['a.png', 'a2.png'], $a->images()->pluck('original_name')->all());
        $this->assertSame(0, $b->images()->count());
        $this->assertStringContainsString('404', $import->report['errors'][0]['message']);
        $this->assertSame('Не картинка JPG, PNG или WebP', $import->report['errors'][1]['message']);
    }

    // --- Сервер, доступ, черновики ---

    public function test_artisan_import_from_directory(): void
    {
        $product = $this->product(['sku' => 'D-1']);
        $dir = sys_get_temp_dir().'/bulk-dir-'.uniqid();
        mkdir($dir);
        $this->temp[] = $dir;
        file_put_contents("{$dir}/d-1.png", $this->png(40, 41));
        file_put_contents("{$dir}/d-1_2.png", $this->png(40, 42));
        file_put_contents("{$dir}/zzz.png", $this->png(40, 43));

        $this->artisan('images:import', ['path' => $dir])->expectsOutputToContain('zzz.png')->assertSuccessful()->run();
        $this->assertSame(0, ProductImage::count());
        $this->assertSame(ImageImport::EXPIRED, $this->lastImport()->status);

        $this->artisan('images:import', ['path' => $dir, '--apply' => true])->assertSuccessful()->run();
        $this->assertSame(['d-1.png', 'd-1_2.png'], $product->images()->pluck('original_name')->all());
        $this->assertSame(ImageImport::DONE, $this->lastImport()->status);
        $this->assertFileExists("{$dir}/d-1.png", 'файлы на сервере не удаляются');

        $this->artisan('images:import', ['path' => $dir.'/nope'])->assertFailed();
    }

    public function test_access_validation_discard_and_prune(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/products/images-import')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/products')->assertSee('data-images-import-link', false);
        $this->actingAs($this->admin)->get('/admin/products/images-import')->assertOk()->assertSee('data-upload-zip', false)->assertSee('data-upload-csv', false);

        $this->actingAs($this->admin)->post('/admin/products/images-import', ['source' => 'zip', 'file' => UploadedFile::fake()->create('a.pdf', 10)])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->admin)->post('/admin/products/images-import', ['source' => 'zip', 'file' => UploadedFile::fake()->createWithContent('a.zip', 'not zip')])
            ->assertSessionHasErrors(['file' => 'Файл не похож на ZIP-архив или повреждён']);
        $this->assertSame(0, ImageImport::count());

        $this->uploadZip(['x.png' => $this->png()]);
        $draft = $this->lastImport();
        $this->actingAs($this->admin)->post("/admin/products/images-import/{$draft->id}/apply", ['mode' => 'append'])->assertSessionHasErrors('mode');
        $this->actingAs($this->admin)->post("/admin/products/images-import/{$draft->id}/discard")->assertRedirect('/admin/products/images-import');
        $this->assertSame(ImageImport::EXPIRED, $draft->fresh()->status);
        $this->assertFalse(Storage::disk(BulkImages::DISK)->exists($draft->path));

        $this->uploadZip(['y.png' => $this->png()]);
        $old = $this->lastImport();
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->assertSame(1, app(BulkImages::class)->pruneDrafts());
        $this->assertSame(ImageImport::EXPIRED, $old->fresh()->status);
        $this->assertFalse(Storage::disk(BulkImages::DISK)->exists($old->path));
    }
}
