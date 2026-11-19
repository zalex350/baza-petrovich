<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * php artisan petrovich:import — каталог baza-petrovich.by; раздел «Услуги».
 */
class PetrovichImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Импорт назначает фото разделов (petrovich:category-photos) — на фейковый диск
        Storage::fake('public');
    }

    private function import(array $options = [])
    {
        return $this->artisan('petrovich:import', ['file' => base_path('tests/Fixtures/petrovich/catalog.json')] + $options);
    }

    public function test_fresh_import_replaces_old_catalog(): void
    {
        $old = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);
        Product::create(['name' => 'Grohe', 'sku' => 'GR-1', 'price' => 100, 'category_id' => $old->id, 'is_active' => true]);

        $this->import(['--fresh' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Product::withTrashed()->where('sku', 'GR-1')->count());
        $this->assertNull(Category::where('slug', 'smesiteli')->first());
        $this->assertSame(3, Category::count());
        $this->assertSame(3, Product::count());

        $truba = Product::where('source_key', 'catalog/metalloprokat/profilnaya-truba/truba-20x20')->sole();
        $this->assertEquals(3.36, $truba->price);
        $this->assertSame('м.п.', $truba->unit);
        $this->assertTrue($truba->is_hit);
        $this->assertSame(1, $truba->stock);
        $this->assertSame('profilnaya-truba', $truba->category->slug);
        $this->assertSame('metalloprokat', $truba->category->parent->slug);
        $this->assertStringContainsString('<strong>Труба</strong>', $truba->description);

        $attrs = $truba->attributeValues()->with('attribute')->get()->mapWithKeys(fn ($v) => [$v->attribute->name => $v->value]);
        $this->assertSame('1,5 мм', $attrs['Толщина']);
        $this->assertSame('0,95', $attrs['Вес, кг']);
        $this->assertSame('3,20 BYN', $attrs['Цена со скидкой от 100 кг']);

        $request = Product::where('name', 'Сетка рабица ПВХ')->sole();
        $this->assertNull($request->price);
        $this->assertSame('шт', $request->unit);
        $this->assertSame(0, Product::where('name', 'Сетка кладочная')->sole()->stock);

        // Текст раздела очищен
        $this->assertStringNotContainsString('<script>', Category::where('slug', 'metalloprokat')->value('description'));

        $this->assertSame(2, Page::services()->count());
    }

    public function test_repeat_import_updates_without_duplicates(): void
    {
        $this->import()->assertSuccessful();
        $truba = Product::where('name', 'Профильная труба 20x20x1.5')->sole();
        $truba->update(['price' => 1]);

        $this->import()->assertSuccessful();

        $this->assertSame(3, Product::count());
        $this->assertSame(3, Category::count());
        $this->assertEquals(3.36, $truba->fresh()->price);
        $this->assertSame($truba->sku, $truba->fresh()->sku);
    }

    public function test_storefront_shows_imported_catalog_and_services(): void
    {
        $this->import()->assertSuccessful();
        $truba = Product::where('name', 'Профильная труба 20x20x1.5')->sole();

        $this->get('/catalog/metalloprokat')
            ->assertOk()
            ->assertSee('Профильная труба 20x20x1.5')
            ->assertSee('data-category-description', false)
            ->assertSee('Металлопрокат в розницу');

        // Адрес — slug со старого сайта; /product/{id} ведёт на него
        $this->get("/product/{$truba->id}")->assertRedirect($truba->url());
        $this->get($truba->url())
            ->assertOk()
            ->assertSee('<strong>Труба</strong> для забора', false)
            ->assertSee('за м.п.');

        $this->get('/uslugi')->assertOk()->assertSee('Резка металла')->assertSee('/uslugi/rezka-metalla', false);
        $this->get('/uslugi/rezka-metalla')
            ->assertOk()
            ->assertSee('Резка в размер заказчика')
            ->assertSee(route('lead.create', ['type' => 'service', 'subject' => 'Резка металла']), false);
        $this->get('/uslugi/nope')->assertNotFound();

        $this->get(route('lead.create', ['type' => 'service', 'subject' => 'Резка металла']))->assertOk()->assertSee('Резка металла');
        $this->get('/request/unknown')->assertNotFound();
    }
}
