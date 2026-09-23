<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDraftPublishTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->product = Product::create([
            'name'         => 'Grohe Start',
            'sku'          => 'GR-001',
            'price'        => 199.99,
            'stock'        => 5,
            'category_id'  => $category->id,
            'is_active'    => true,
            'is_published' => true,
            'description'  => 'Старое описание',
        ]);
    }

    // --- saveDraft ---

    public function test_save_draft_creates_product_draft_record(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft", [
            'display_name'    => 'Grohe Start (новое)',
            'description'     => 'Новое описание',
            'specifications'  => 'Новые характеристики',
            'package_contents' => 'Новая комплектация',
        ]);

        $draft = ProductDraft::where('product_id', $this->product->id)->first();

        $this->assertNotNull($draft);
        $this->assertSame('Grohe Start (новое)', $draft->data['display_name']);
        $this->assertSame('Новое описание', $draft->data['description']);
    }

    public function test_save_draft_updates_existing_draft(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => ['display_name' => 'Старый черновик', 'description' => 'Старое'],
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft", [
            'display_name' => 'Обновлённый черновик',
            'description'  => 'Обновлённое описание',
        ]);

        $this->assertCount(1, ProductDraft::where('product_id', $this->product->id)->get());

        $draft = ProductDraft::where('product_id', $this->product->id)->first();
        $this->assertSame('Обновлённый черновик', $draft->data['display_name']);
    }

    public function test_save_draft_does_not_change_published_product_fields(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft", [
            'display_name' => 'Черновик',
            'description'  => 'Черновое описание',
        ]);

        $this->product->refresh();

        $this->assertSame('Старое описание', $this->product->description);
        $this->assertNull($this->product->display_name);
    }

    // --- publish ---

    public function test_publish_copies_draft_fields_to_product(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => [
                'display_name'    => 'Grohe Start Pro',
                'description'     => 'Обновлённое описание',
                'specifications'  => 'Обновлённые характеристики',
                'package_contents' => 'Обновлённая комплектация',
            ],
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/publish");

        $this->product->refresh();

        $this->assertSame('Grohe Start Pro', $this->product->display_name);
        $this->assertSame('Обновлённое описание', $this->product->description);
        $this->assertTrue((bool) $this->product->is_published);
    }

    public function test_publish_deletes_draft(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => ['display_name' => 'Черновик', 'description' => ''],
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/publish");

        $this->assertNull(ProductDraft::where('product_id', $this->product->id)->first());
    }

    public function test_publish_without_draft_sets_is_published_true(): void
    {
        $product = Product::create([
            'name'         => 'Новый товар',
            'sku'          => 'NEW-001',
            'price'        => 10,
            'stock'        => 0,
            'is_published' => false,
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$product->id}/publish");

        $product->refresh();
        $this->assertTrue((bool) $product->is_published);
    }

    // --- deleteDraft ---

    public function test_delete_draft_removes_draft_record(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => ['display_name' => 'Черновик', 'description' => 'Черновое'],
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft/delete");

        $this->assertNull(ProductDraft::where('product_id', $this->product->id)->first());
    }

    public function test_delete_draft_does_not_touch_published_product(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => ['display_name' => 'Черновик', 'description' => 'Черновое'],
        ]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/draft/delete");

        $this->product->refresh();

        $this->assertSame('Старое описание', $this->product->description);
        $this->assertNull($this->product->display_name);
    }

    // --- preview ---

    public function test_preview_renders_draft_values_without_saving(): void
    {
        ProductDraft::create([
            'product_id' => $this->product->id,
            'data'       => [
                'display_name' => 'Предпросмотровое название',
                'description'  => 'Предпросмотровое описание',
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/admin/product/{$this->product->id}/preview");

        $response->assertStatus(200);
        $response->assertSee('Предпросмотровое название');
        $response->assertSee('Предпросмотровое описание');

        // Опубликованная версия не изменилась
        $this->product->refresh();
        $this->assertNull($this->product->display_name);
        $this->assertSame('Старое описание', $this->product->description);
    }

    public function test_preview_shows_published_values_when_no_draft(): void
    {
        $response = $this->actingAs($this->admin)
            ->get("/admin/product/{$this->product->id}/preview");

        $response->assertStatus(200);
        $response->assertSee('Grohe Start');
    }

    // --- is_published в публичном каталоге ---

    public function test_unpublished_product_hidden_from_catalog(): void
    {
        $category = Category::create(['name' => 'Краны', 'slug' => 'krany']);

        Product::create([
            'name'         => 'Скрытый товар',
            'sku'          => 'HID-001',
            'price'        => 50,
            'stock'        => 5,
            'category_id'  => $category->id,
            'is_active'    => true,
            'is_published' => false,
        ]);

        $response = $this->get('/catalog/krany');

        $response->assertStatus(200);
        $response->assertDontSee('Скрытый товар');
    }

    public function test_unpublished_product_hidden_from_search(): void
    {
        Product::create([
            'name'         => 'НеопубликованныйТовар',
            'sku'          => 'NPB-XYZ-UNIQUE',
            'price'        => 50,
            'stock'        => 5,
            'is_active'    => true,
            'is_published' => false,
        ]);

        // Ищем по уникальному SKU — он не должен появиться в результатах,
        // но «ничего не найдено» должно быть
        $response = $this->get('/search?q=NPB-XYZ-UNIQUE');

        $response->assertStatus(200);
        $response->assertSee('ничего не найдено');
    }

    // --- display_name на публичных страницах ---

    public function test_display_name_shown_on_product_page(): void
    {
        $this->product->update(['display_name' => 'Grohe Start Люкс']);

        $response = $this->get("/product/{$this->product->id}");

        $response->assertStatus(200);
        $response->assertSee('Grohe Start Люкс');
    }

    public function test_name_used_as_fallback_when_no_display_name(): void
    {
        $response = $this->get("/product/{$this->product->id}");

        $response->assertStatus(200);
        $response->assertSee('Grohe Start');
    }
}
