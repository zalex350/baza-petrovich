<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductDraft;
use App\Models\User;
use App\Services\ProductDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Черновик текстов товара — только если сайтовые поля реально отличаются от опубликованных.
 * Сохранение без правок (браузер шлёт переносы строк как \r\n) черновик не создаёт; черновик,
 * совпавший с опубликованным, удаляется; очистка уже накопленных — идемпотентна.
 */
class ProductDraftOnSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.catalog.manual_products' => false]);

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::create([
            'name' => 'Смеситель', 'sku' => 'D-1', 'price' => 10, 'stock' => 1,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true, 'is_published' => true,
            'description' => "Первая строка\nВторая строка\n\nТретья",
            'specifications' => 'Хром',
        ]);

        $color = ProductAttribute::create(['name' => 'Цвет']);
        ProductAttributeValue::create(['product_id' => $this->product->id, 'attribute_id' => $color->id, 'value' => 'хром', 'sort_order' => 0]);
    }

    /**
     * Форма как из браузера: тексты с \r\n, характеристики строками.
     */
    private function save(array $overrides = [], string $action = 'draft')
    {
        return $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", array_merge([
            'display_name' => '',
            'description' => "Первая строка\r\nВторая строка\r\n\r\nТретья",
            'specifications' => 'Хром ',
            'package_contents' => '',
            'attributes_sent' => '1',
            'attributes' => [['name' => 'Цвет', 'value' => ' хром ']],
            'action' => $action,
        ], $overrides));
    }

    public function test_save_without_changes_does_not_create_draft(): void
    {
        foreach (['draft', 'preview'] as $action) {
            $this->save([], $action)->assertSessionHasNoErrors();
        }

        $this->assertSame(0, ProductDraft::count());
    }

    public function test_real_change_creates_draft_with_normalized_newlines(): void
    {
        $this->save(['description' => "Новое\r\nописание"])->assertSessionHasNoErrors();

        $draft = ProductDraft::sole();
        $this->assertSame("Новое\nописание", $draft->data['description']);
        // Опубликованное не изменилось
        $this->assertSame("Первая строка\nВторая строка\n\nТретья", $this->product->fresh()->description);
    }

    public function test_attribute_change_creates_draft(): void
    {
        $this->save(['attributes' => [['name' => 'Цвет', 'value' => 'чёрный']]]);

        $this->assertSame(1, ProductDraft::count());
    }

    public function test_draft_reverted_to_published_is_deleted(): void
    {
        $this->save(['specifications' => 'Латунь']);
        $this->assertSame(1, ProductDraft::count());

        // Правку вернули — черновик больше не нужен
        $this->save();
        $this->assertSame(0, ProductDraft::count());

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk();
    }

    public function test_publish_still_applies_real_draft(): void
    {
        $this->save(['specifications' => 'Латунь']);
        $this->save(['specifications' => 'Латунь'], 'publish')->assertSessionHasNoErrors();

        $this->assertSame('Латунь', $this->product->fresh()->specifications);
        $this->assertSame(0, ProductDraft::count());
    }

    public function test_separate_draft_endpoint_follows_same_rule(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft", [
            'description' => "Первая строка\r\nВторая строка\r\n\r\nТретья",
            'specifications' => 'Хром',
        ])->assertSessionHas('success', 'Изменений нет — черновик не нужен');

        $this->assertSame(0, ProductDraft::count());
    }

    public function test_prune_removes_only_unchanged_drafts_and_is_idempotent(): void
    {
        $other = Product::create(['name' => 'Другой', 'sku' => 'D-2', 'price' => 1, 'stock' => 1, 'description' => 'Текст']);
        $trashed = Product::create(['name' => 'Удалённый', 'sku' => 'D-3', 'price' => 1, 'stock' => 1, 'description' => 'Текст']);

        $colorId = ProductAttribute::where('name', 'Цвет')->value('id');

        // Пустой: совпадает с опубликованным (с \r\n и пробелами)
        ProductDraft::create(['product_id' => $this->product->id, 'data' => [
            'display_name' => null, 'description' => "Первая строка\r\nВторая строка\r\n\r\nТретья ",
            'specifications' => 'Хром', 'package_contents' => '',
            'attributes' => [['attribute_id' => $colorId, 'value' => 'хром']],
        ]]);
        // С правкой
        ProductDraft::create(['product_id' => $other->id, 'data' => ['description' => 'Правка']]);
        // Удалённого товара — не трогать (товар могут восстановить)
        ProductDraft::create(['product_id' => $trashed->id, 'data' => ['description' => 'Текст']]);
        $trashed->delete();

        $this->assertSame(1, app(ProductDrafts::class)->pruneUnchanged());
        $this->assertSame(0, app(ProductDrafts::class)->pruneUnchanged());

        $this->assertNull(ProductDraft::where('product_id', $this->product->id)->first());
        $this->assertNotNull(ProductDraft::where('product_id', $other->id)->first());
        $this->assertNotNull(ProductDraft::where('product_id', $trashed->id)->first());
    }

    public function test_cleanup_migration_runs_prune(): void
    {
        ProductDraft::create(['product_id' => $this->product->id, 'data' => ['description' => "Первая строка\nВторая строка\n\nТретья"]]);

        $migration = require database_path('migrations/2026_10_29_150000_prune_unchanged_product_drafts.php');
        $migration->up();
        $migration->up();

        $this->assertSame(0, ProductDraft::count());
    }
}
