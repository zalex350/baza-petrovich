<?php

namespace Tests\Feature\CatalogImport;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Перенос товаров не в своей категории по артикулам
 * (2026_10_26_120000_move_misplaced_catalog_products, docs/catalog/fix-client-test-1.md, п. 6).
 */
class MisplacedProductsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_26_120000_move_misplaced_catalog_products.php'))->up();
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::create(['name' => $name, 'slug' => 'c'.Category::count(), 'parent_id' => $parent?->id]);
    }

    private function product(string $sku, Category $category): Product
    {
        return Product::create(['name' => $sku, 'sku' => $sku, 'price' => 1, 'stock' => 1, 'category_id' => $category->id]);
    }

    public function test_moves_by_sku_drops_emptied_categories_and_is_idempotent(): void
    {
        $pipes = $this->category('Трубы и фитинги');
        $rings = $this->category('Кольца', $pipes);
        $bath = $this->category('Аксессуары для ванной');
        $curtains = $this->category('Шторы для ванной', $bath);
        $accessories = $this->category('Аксессуары', $bath);
        $sinks = $this->category('Мойки и умывальники');
        $sinkAccessories = $this->category('Аксессуары для моек', $sinks);

        $ring = $this->product('SAVOL-S-YLK002B', $rings);
        $trashed = $this->product('SAVOL-S-YLK003', $rings);
        $trashed->delete();
        $dispenser = $this->product('SAVOL-S-ZY001H', $accessories);
        $colander = $this->product('GERHANS-K30300', $accessories);

        $this->migrate();
        $this->migrate();

        $this->assertSame($curtains->id, $ring->fresh()->category_id);
        $this->assertSame($curtains->id, Product::withTrashed()->find($trashed->id)->category_id);
        $this->assertSame($sinkAccessories->id, $dispenser->fresh()->category_id);
        // Не из списка — на месте; «Аксессуары» не опустела и остаётся
        $this->assertSame($accessories->id, $colander->fresh()->category_id);
        $this->assertModelExists($accessories);
        $this->assertModelMissing($rings);
    }

    public function test_missing_target_category_is_skipped(): void
    {
        $fasteners = $this->category('Крепёж и инструмент');
        $studs = $this->category('Шпильки', $fasteners);
        $stud = $this->product('SAVOL-S09', $studs);

        $this->migrate();

        // «Смесители / Запчасти» нет — товар и категория остаются
        $this->assertSame($studs->id, $stud->fresh()->category_id);
        $this->assertModelExists($studs);
    }
}
