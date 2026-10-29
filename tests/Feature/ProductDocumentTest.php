<?php

namespace Tests\Feature;

use App\Enums\ProductDocumentType;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Документы товара (SRS §4.8): загрузка PDF в админке (тип, название, до 20 МБ), порядок,
 * изменение, удаление; блок «Документы» в карточке — иконка типа, название, размер,
 * открыть/скачать; нет документов — блока нет.
 */
class ProductDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.images.disk' => 'public']);
        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
        $this->product = Product::create([
            'name' => 'Смеситель Grohe', 'sku' => 'GR-1', 'price' => 100, 'stock' => 5,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    private function pdf(string $name = 'instrukciya_grohe.pdf', int $kb = 0): UploadedFile
    {
        return $kb > 0
            ? UploadedFile::fake()->create($name, $kb, 'application/pdf')
            : UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function upload(array $data = [])
    {
        return $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/documents", $data + [
            'document' => $this->pdf(),
            'type' => 'manual',
            'title' => 'Инструкция по монтажу',
        ]);
    }

    public function test_upload_stores_pdf_with_type_title_and_order(): void
    {
        $this->upload()->assertRedirect("/admin/product/{$this->product->id}#documents")->assertSessionHas('success', 'Документ загружен');
        $this->upload(['type' => 'certificate', 'title' => '', 'document' => $this->pdf('sertifikat-sootvetstviya.pdf')]);

        [$first, $second] = $this->product->documents()->get()->all();

        $this->assertSame('Инструкция по монтажу', $first->title);
        $this->assertSame(ProductDocumentType::Manual, $first->type);
        $this->assertSame(1, $first->sort_order);
        // Пустое название — из имени файла
        $this->assertSame('sertifikat sootvetstviya', $second->title);
        $this->assertSame(2, $second->sort_order);
        $this->assertStringStartsWith('documents/'.$this->product->id.'/', $first->path);
        Storage::disk('public')->assertExists($first->path);
    }

    public function test_validation_pdf_only_size_and_type(): void
    {
        $this->upload(['document' => UploadedFile::fake()->image('photo.jpg')])->assertSessionHasErrors(['document' => 'Документ — только PDF']);
        $this->upload(['document' => $this->pdf('big.pdf', ProductDocument::MAX_KB + 1)])->assertSessionHasErrors(['document' => 'Файл больше 20 МБ']);
        $this->upload(['type' => 'unknown'])->assertSessionHasErrors('type');
        $this->upload(['document' => null])->assertSessionHasErrors(['document' => 'Выберите PDF-файл']);

        $this->assertSame(0, ProductDocument::count());

        // Ровно 20 МБ — можно
        $this->upload(['document' => $this->pdf('max.pdf', ProductDocument::MAX_KB)])->assertSessionHasNoErrors();
    }

    public function test_reorder_by_drag_and_drop_ignores_foreign_ids(): void
    {
        foreach (['А', 'Б', 'В'] as $title) {
            $this->upload(['title' => $title]);
        }
        [$a, $b, $c] = $this->product->documents()->pluck('id')->all();

        $other = Product::create(['name' => 'Другой', 'sku' => 'X-1', 'price' => 1, 'stock' => 1]);
        $foreign = ProductDocument::create(['product_id' => $other->id, 'title' => 'Чужой', 'type' => 'other', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'size' => 1, 'sort_order' => 1]);

        $this->actingAs($this->admin)
            ->postJson("/admin/product/{$this->product->id}/documents/reorder", ['ids' => [$c, $foreign->id, $a]])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Порядок сохранён']);

        $this->assertSame(['В', 'А', 'Б'], $this->product->documents()->pluck('title')->all());
        $this->assertSame(1, $foreign->fresh()->sort_order);
    }

    public function test_update_title_and_type(): void
    {
        $this->upload();
        $document = ProductDocument::sole();

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/documents/{$document->id}", ['title' => 'Паспорт изделия', 'type' => 'passport'])
            ->assertSessionHas('success');

        $this->assertSame('Паспорт изделия', $document->fresh()->title);
        $this->assertSame(ProductDocumentType::Passport, $document->fresh()->type);
    }

    public function test_delete_removes_record_and_file(): void
    {
        $this->upload();
        $document = ProductDocument::sole();

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-admin-documents', false)
            ->assertSee('Удалить документ?');

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/documents/{$document->id}/delete")
            ->assertSessionHas('success', 'Документ удалён');

        $this->assertSame(0, ProductDocument::count());
        Storage::disk('public')->assertMissing($document->path);
    }

    public function test_document_of_another_product_cannot_be_changed(): void
    {
        $this->upload();
        $document = ProductDocument::sole();
        $other = Product::create(['name' => 'Другой', 'sku' => 'X-2', 'price' => 1, 'stock' => 1]);

        $this->actingAs($this->admin)->post("/admin/product/{$other->id}/documents/{$document->id}/delete")->assertNotFound();
        $this->assertSame(1, ProductDocument::count());
    }

    public function test_only_admin_manages_documents(): void
    {
        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($client)->post("/admin/product/{$this->product->id}/documents", ['document' => $this->pdf(), 'type' => 'manual'])->assertForbidden();
        $this->assertSame(0, ProductDocument::count());
    }

    public function test_card_shows_documents_block_with_icon_title_size_and_links(): void
    {
        $this->upload(['title' => 'Сертификат', 'type' => 'certificate']);
        $this->upload(['title' => 'Инструкция', 'type' => 'manual']);
        $document = ProductDocument::where('title', 'Сертификат')->first();

        $html = $this->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-documents', false)
            ->assertSeeInOrder(['Сертификат', 'Инструкция'])
            ->assertSee('Сертификат · PDF, 1 КБ')
            ->assertSee('href="'.$document->url().'"', false)
            ->assertSee('data-document-download', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-document>'));
        $this->assertStringContainsString(' download ', $html);
    }

    public function test_card_without_documents_has_no_block(): void
    {
        $this->get("/product/{$this->product->id}")->assertOk()->assertDontSee('data-documents', false);
    }

    public function test_file_is_available_by_public_link(): void
    {
        $this->upload();
        $document = ProductDocument::sole();

        $this->assertSame('/storage/'.$document->path, $document->url());
    }

    public function test_size_label(): void
    {
        $this->assertSame('PDF, 340 КБ', (new ProductDocument(['size' => 340 * 1024]))->sizeLabel());
        $this->assertSame('PDF, 1,5 МБ', (new ProductDocument(['size' => (int) (1.5 * 1024 * 1024)]))->sizeLabel());
    }
}
