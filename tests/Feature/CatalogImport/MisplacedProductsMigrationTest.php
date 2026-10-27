<?php

namespace Tests\Feature\CatalogImport;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Перенос товаров не в своей категории (docs/catalog/fix-client-test-1.md, п. 6):
 * по артикулам (2026_10_26_120000_move_misplaced_catalog_products), вся «Аксессуары»
 * (2026_10_27_100000_move_bath_accessories_to_sink_accessories) и «Для душевых»
 * (2026_10_27_120000_split_shower_category, docs/catalog/shower.csv).
 */
class MisplacedProductsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(string $file = '2026_10_26_120000_move_misplaced_catalog_products'): void
    {
        (require database_path("migrations/{$file}.php"))->up();
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

    public function test_all_bath_accessories_move_to_sink_accessories(): void
    {
        $bath = $this->category('Аксессуары для ванной');
        $accessories = $this->category('Аксессуары', $bath);
        $holders = $this->category('Держатели и стойки', $bath);
        $sinkAccessories = $this->category('Аксессуары для моек', $this->category('Мойки и умывальники'));

        $colander = $this->product('GERHANS-K30300', $accessories);
        $trashed = $this->product('SAVOL-S-ZY001H', $accessories);
        $trashed->delete();
        $holder = $this->product('LEDEME-L926', $holders);

        $this->migrate('2026_10_27_100000_move_bath_accessories_to_sink_accessories');
        $this->migrate('2026_10_27_100000_move_bath_accessories_to_sink_accessories');

        $this->assertSame($sinkAccessories->id, $colander->fresh()->category_id);
        $this->assertSame($sinkAccessories->id, Product::withTrashed()->find($trashed->id)->category_id);
        $this->assertSame($holders->id, $holder->fresh()->category_id);
        $this->assertModelMissing($accessories);
        $this->assertModelExists($bath);
    }

    public function test_bath_accessories_stay_without_target(): void
    {
        $accessories = $this->category('Аксессуары', $this->category('Аксессуары для ванной'));
        $colander = $this->product('GERHANS-K30300', $accessories);

        $this->migrate('2026_10_27_100000_move_bath_accessories_to_sink_accessories');

        $this->assertSame($accessories->id, $colander->fresh()->category_id);
        $this->assertModelExists($accessories);
    }

    private const SHOWER = '2026_10_27_120000_split_shower_category';

    public function test_shower_category_is_split_renamed_and_dropped(): void
    {
        $root = $this->category('Душевые системы и трапы');
        $mixed = $this->category('Для душевых', $root);
        $stands = $this->category('Душевые стойки', $root);
        $heads = $this->category('Лейки для душа', $root);
        $builtIn = $this->category('Душевая система (встраиваемая)', $root);
        $single = $this->category('Душевая система', $root);
        $bathMixers = $this->category('Для ванны и душа', $this->category('Смесители'));

        $mixer = $this->product('GERHANS-K19129', $mixed);
        $stand = $this->product('LEDEME-L2425B-1', $mixed);
        $trashed = $this->product('WISENT-W3501', $mixed);
        $trashed->delete();
        $head = $this->product('LEDEME-L2417', $mixed);
        $wall = $this->product('WISENT-WP8577', $mixed);
        $oldBuiltIn = $this->product('L24101-2', $builtIn);
        $loffrey = $this->product('LOFFREY-LF82427', $single);

        $this->migrate(self::SHOWER);
        $this->migrate(self::SHOWER);

        $this->assertSame($bathMixers->id, $mixer->fresh()->category_id);
        $this->assertSame($stands->id, $stand->fresh()->category_id);
        $this->assertSame($stands->id, Product::withTrashed()->find($trashed->id)->category_id);
        $this->assertSame($heads->id, $head->fresh()->category_id);
        $this->assertSame($stands->id, $loffrey->fresh()->category_id);

        // Переименована — та же запись и адрес; туда же встраиваемая из «Для душевых»
        $this->assertSame('Встраиваемые душевые системы', $builtIn->fresh()->name);
        $this->assertSame($builtIn->slug, $builtIn->fresh()->slug);
        $this->assertSame($builtIn->id, $wall->fresh()->category_id);
        $this->assertSame($builtIn->id, $oldBuiltIn->fresh()->category_id);

        $this->assertModelMissing($mixed);
        $this->assertModelMissing($single);
    }

    public function test_shower_built_in_merges_into_existing_new_name(): void
    {
        $root = $this->category('Душевые системы и трапы');
        $old = $this->category('Душевая система (встраиваемая)', $root);
        $new = $this->category('Встраиваемые душевые системы', $root);
        $product = $this->product('L24101-2', $old);

        $this->migrate(self::SHOWER);

        $this->assertSame($new->id, $product->fresh()->category_id);
        $this->assertModelMissing($old);
    }

    public function test_shower_category_stays_when_not_emptied(): void
    {
        $mixed = $this->category('Для душевых', $this->category('Душевые системы и трапы'));
        $unknown = $this->product('NEW-SKU-1', $mixed);
        // Цели нет — товар из списка тоже остаётся
        $listed = $this->product('LEDEME-L2425B-1', $mixed);

        $this->migrate(self::SHOWER);

        $this->assertSame($mixed->id, $unknown->fresh()->category_id);
        $this->assertSame($mixed->id, $listed->fresh()->category_id);
        $this->assertModelExists($mixed);
    }
}
