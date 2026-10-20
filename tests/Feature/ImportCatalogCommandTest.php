<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * catalog:import — сообщения на русском. Файл импорта — во временной папке storage,
 * настоящий storage/app/imports не трогается.
 */
class ImportCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir() . '/import-test-' . uniqid();
        File::ensureDirectoryExists($this->storage . '/app/imports');
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function writeImport(mixed $data): void
    {
        file_put_contents($this->storage . '/app/imports/catalog_import.json', is_string($data) ? $data : json_encode($data));
    }

    public function test_missing_file_message_is_russian(): void
    {
        $this->artisan('catalog:import')
            ->expectsOutputToContain('Файл не найден')
            ->assertFailed();
    }

    public function test_invalid_structure_message_is_russian(): void
    {
        $this->writeImport(['items' => []]);

        $this->artisan('catalog:import')
            ->expectsOutputToContain('Неверная структура JSON')
            ->assertFailed();
    }

    public function test_import_reports_in_russian(): void
    {
        $this->writeImport([
            'categories' => ['Смесители'],
            'products'   => [
                ['sku' => 'A-1', 'name' => 'Смеситель', 'brand' => 'Grohe', 'description' => null, 'category' => 'Смесители'],
                ['sku' => '', 'name' => 'Без артикула', 'brand' => null, 'description' => null, 'category' => null],
            ],
        ]);

        $this->artisan('catalog:import')
            ->expectsOutputToContain('Импорт категорий: 1')
            ->expectsOutputToContain('Импорт товаров: 2')
            ->expectsOutputToContain('Готово. Создано: 1, пропущено (дубли или без артикула): 1')
            ->assertSuccessful();

        $this->assertTrue(Product::where('sku', 'A-1')->exists());
    }
}
