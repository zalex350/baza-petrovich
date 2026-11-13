<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Единая форма товара (создание и редактирование) и режим shop.catalog.manual_products.
 */
class AdminProductFormTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['shop.catalog.manual_products' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::create(['name' => 'Смесители', 'slug' => 'smesiteli']);

        $this->product = Product::create([
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'brand' => 'Grohe',
            'price' => 100,
            'stock' => 5,
            'category_id' => $this->category->id,
            'description' => 'Старое описание',
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($im, null, 85);
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, ob_get_clean());

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function notImage(string $name = 'virus.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'not an image');

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function newProduct(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hansgrohe Logis',
            'sku' => 'HG-100',
            'brand' => 'Hansgrohe',
            'price' => 250,
            'vat_rate' => 20,
            'stock' => 3,
            'category_id' => $this->category->id,
            'description' => 'Описание нового',
            'is_active' => 1,
        ], $overrides);
    }

    private function edit(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'brand' => 'Grohe',
            'price' => 100,
            'vat_rate' => 20,
            'stock' => 5,
            'category_id' => $this->category->id,
            'display_name' => '',
            'slug' => '',
            'description' => 'Старое описание',
            'specifications' => '',
            'package_contents' => '',
        ], $overrides);
    }

    // --- Одна форма для создания и редактирования ---

    public function test_create_and_edit_use_same_form_with_two_blocks_and_three_buttons(): void
    {
        foreach (['/admin/product/create', "/admin/product/{$this->product->id}"] as $url) {
            $this->actingAs($this->admin)->get($url)
                ->assertOk()
                ->assertSee('id="product-form"', false)
                ->assertSeeInOrder(['Данные учётной системы (1С)', 'Данные сайта', 'Публикация'])
                ->assertSee('value="draft"', false)
                ->assertSee('value="preview"', false)
                ->assertSee('value="publish"', false)
                ->assertSee('Сохранить черновик')
                ->assertSee('Сохранить и предпросмотр')
                ->assertSee('Опубликовать')
                ->assertSee('data-image-queue', false)
                ->assertSee('form="product-form"', false);
        }
    }

    public function test_photo_queue_belongs_to_product_form_on_edit(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->getContent();

        // Очередь в форме товара, кнопка «Загрузить» — та же форма, но сразу в блок фото
        $this->assertMatchesRegularExpression('#name="images\[\]"[^>]*form="product-form"#s', $html);
        $this->assertStringContainsString('formaction="/admin/product/'.$this->product->id.'/images"', $html);
    }

    // --- Создание ---

    public function test_save_draft_creates_unpublished_product(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/product', $this->newProduct(['action' => 'draft']))
            ->assertSessionHasNoErrors();

        $product = Product::where('sku', 'HG-100')->firstOrFail();

        $this->assertFalse((bool) $product->is_published);
        $this->assertTrue((bool) $product->is_active);
        $this->assertSame('Описание нового', $product->description);
    }

    public function test_publish_on_create_publishes_product(): void
    {
        $this->actingAs($this->admin)->post('/admin/product', $this->newProduct(['action' => 'publish']));

        $product = Product::where('sku', 'HG-100')->firstOrFail();

        $this->assertTrue((bool) $product->is_published);
        $this->get("/product/{$product->id}")->assertOk();
    }

    public function test_save_and_preview_on_create_opens_preview(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', $this->newProduct(['action' => 'preview']));

        $product = Product::where('sku', 'HG-100')->firstOrFail();

        $response->assertRedirect("/admin/product/{$product->id}/preview");
        $this->assertFalse((bool) $product->is_published);
    }

    public function test_photos_from_queue_are_uploaded_on_create(): void
    {
        $this->actingAs($this->admin)->post('/admin/product', $this->newProduct([
            'action' => 'draft',
            'images' => [$this->image('a.jpg'), $this->image('b.jpg')],
        ]))->assertSessionHasNoErrors();

        $product = Product::where('sku', 'HG-100')->firstOrFail();

        $this->assertSame(['a.jpg', 'b.jpg'], $product->images()->pluck('original_name')->all());
        $this->assertTrue((bool) $product->images()->first()->is_main);
    }

    public function test_bad_photo_does_not_block_product_creation(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/product', $this->newProduct([
            'action' => 'publish',
            'images' => [$this->image('good.jpg'), $this->notImage('bad.jpg')],
        ]));

        $product = Product::where('sku', 'HG-100')->firstOrFail();

        $response->assertRedirect("/admin/product/{$product->id}#images")
            ->assertSessionHasErrors('images');
        $this->assertSame(['good.jpg'], $product->images()->pluck('original_name')->all());
        $this->assertTrue((bool) $product->is_published);
    }

    public function test_invalid_fields_create_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/product', $this->newProduct(['price' => -1, 'images' => [$this->image()]]))
            ->assertSessionHasErrors('price');

        $this->assertDatabaseMissing('products', ['sku' => 'HG-100']);
    }

    // --- Редактирование ---

    public function test_save_draft_applies_erp_fields_and_keeps_texts_in_draft(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft',
            'price' => 120,
            'description' => 'Новое описание',
        ]))->assertSessionHasNoErrors();

        $this->product->refresh();

        $this->assertEquals(120, $this->product->price);
        $this->assertSame('Старое описание', $this->product->description);
        $this->assertSame('Новое описание', $this->product->draft->data['description']);
    }

    public function test_unchanged_texts_do_not_create_draft(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->product->id}", $this->edit(['action' => 'draft', 'stock' => 7]))
            ->assertSessionHasNoErrors();

        $this->assertSame(7, (int) $this->product->fresh()->stock);
        $this->assertSame(0, ProductDraft::count());
    }

    public function test_publish_applies_texts_and_removes_draft(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'publish',
            'display_name' => 'Смеситель Grohe Start',
            'description' => 'Новое описание',
        ]))->assertSessionHasNoErrors();

        $this->product->refresh();

        $this->assertSame('Смеситель Grohe Start', $this->product->display_name);
        $this->assertSame('Новое описание', $this->product->description);
        $this->assertNull($this->product->draft);
    }

    public function test_publish_unpublished_product(): void
    {
        $this->product->update(['is_published' => false]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit(['action' => 'publish']));

        $this->assertTrue((bool) $this->product->fresh()->is_published);
    }

    public function test_save_and_preview_shows_draft_with_back_link(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->product->id}", $this->edit(['action' => 'preview', 'description' => 'Черновое описание']))
            ->assertRedirect("/admin/product/{$this->product->id}/preview");

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}/preview")
            ->assertOk()
            ->assertSee('Черновое описание')
            ->assertSee('data-preview-bar', false)
            ->assertSee('href="/admin/product/'.$this->product->id.'"', false);

        $this->assertSame('Старое описание', $this->product->fresh()->description);
    }

    public function test_photos_from_queue_are_uploaded_on_save(): void
    {
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft',
            'images' => [$this->image('new.jpg')],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['new.jpg'], $this->product->images()->pluck('original_name')->all());
    }

    public function test_min_not_multiple_of_step_saves_with_hint_default_must_be_allowed(): void
    {
        // min 2 не кратно 3 — сохраняется, в форме подсказка «будет округлено до 3»
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft', 'min_qty' => 2, 'qty_step' => 3, 'default_qty' => 3,
        ]))->assertSessionHasNoErrors();
        $this->assertSame([2, 3, 3], [$this->product->fresh()->min_qty, $this->product->fresh()->qty_step, $this->product->fresh()->default_qty]);

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()
            ->assertSee('Не кратно шагу 3 — будет округлено до 3')
            ->assertSee('Допустимое: 3, 6, 9, …');

        // default_qty 2 и 5 (прежние «мин., мин. + шаг») — уже не допустимые
        foreach ([2, 5, 4] as $default) {
            $this->actingAs($this->admin)->from("/admin/product/{$this->product->id}")
                ->post("/admin/product/{$this->product->id}", $this->edit([
                    'action' => 'draft', 'min_qty' => 2, 'qty_step' => 3, 'default_qty' => $default,
                ]))->assertSessionHasErrors(['default_qty' => 'Количество по умолчанию — допустимое количество: 3, 6, 9, … (кратно 3, не меньше 3)']);
        }

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft', 'min_qty' => 2, 'qty_step' => 3, 'default_qty' => 6,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(6, $this->product->fresh()->default_qty);

        // Кратный минимум — без подсказки
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft', 'min_qty' => 4, 'qty_step' => 2, 'default_qty' => 4,
        ]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertDontSee('Не кратно шагу');
    }

    public function test_slug_must_be_unique(): void
    {
        Product::create(['name' => 'Other', 'sku' => 'OT-1', 'slug' => 'taken', 'price' => 1, 'stock' => 1]);

        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->product->id}", $this->edit(['slug' => 'taken']))
            ->assertSessionHasErrors('slug');

        // Свой slug — не конфликт
        $this->product->update(['slug' => 'grohe-start']);
        $this->actingAs($this->admin)
            ->post("/admin/product/{$this->product->id}", $this->edit(['slug' => 'grohe-start']))
            ->assertSessionHasNoErrors();
    }

    // --- Товары приходят из 1С (manual_products = false) ---

    public function test_without_manual_products_creation_is_forbidden(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $this->actingAs($this->admin)->get('/admin/product/create')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/product', $this->newProduct())->assertForbidden();
        $this->assertDatabaseMissing('products', ['sku' => 'HG-100']);

        $this->actingAs($this->admin)->get('/admin/products')
            ->assertOk()
            ->assertDontSee('/admin/product/create');
    }

    public function test_without_manual_products_erp_fields_are_read_only(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Приходят из 1С и здесь не редактируются')
            ->getContent();

        // Поля кита пишут disabled="disabled"
        foreach (['name', 'sku', 'brand', 'price', 'stock'] as $field) {
            $this->assertMatchesRegularExpression('#<input[^>]*name="'.$field.'"[^>]*\sdisabled[\s>=]#s', $html, $field);
        }

        $this->assertMatchesRegularExpression('#<select[^>]*name="vat_rate"[^>]*\sdisabled[\s>]#s', $html);
        $this->assertDoesNotMatchRegularExpression('#<textarea[^>]*name="description"[^>]*\sdisabled[\s>]#s', $html);
    }

    public function test_without_manual_products_server_ignores_erp_fields(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", [
            'action' => 'publish',
            'name' => 'Взломанное имя',
            'sku' => 'HACK',
            'brand' => 'Other',
            'price' => -5,
            'vat_rate' => 'abc',
            'stock' => 999,
            'description' => 'Новое описание',
        ])->assertSessionHasNoErrors();

        $this->product->refresh();

        $this->assertSame('Grohe Start', $this->product->name);
        $this->assertSame('GR-001', $this->product->sku);
        $this->assertSame('Grohe', $this->product->brand);
        $this->assertEquals(100, $this->product->price);
        $this->assertSame(5, (int) $this->product->stock);

        // Данные сайта при этом сохраняются
        $this->assertSame('Новое описание', $this->product->description);
    }

    /**
     * SRS §11.3: категория принадлежит сайту, производитель — 1С.
     */
    public function test_category_is_site_data_and_editable_without_manual_products(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->getContent();

        // Поле категории — в блоке «Данные сайта», не заблокировано; бренд — в блоке 1С
        $site = substr($html, strpos($html, 'data-site-block'));
        $erp = substr($html, strpos($html, 'data-erp-block'), strpos($html, 'data-site-block') - strpos($html, 'data-erp-block'));

        $this->assertStringContainsString('name="category_id"', $site);
        $this->assertStringNotContainsString('name="category_id"', $erp);
        $this->assertStringContainsString('name="brand"', $erp);
        $this->assertDoesNotMatchRegularExpression('#<select[^>]*name="category_id"[^>]*\sdisabled[\s>]#s', $html);

        $other = Category::create(['name' => 'Ванны', 'slug' => 'vanny']);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", [
            'action' => 'draft',
            'category_id' => $other->id,
            'brand' => 'Other',
        ])->assertSessionHasNoErrors();

        $this->product->refresh();
        $this->assertSame($other->id, $this->product->category_id);
        $this->assertSame('Grohe', $this->product->brand);

        // Несуществующая категория — ошибка
        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", ['category_id' => 999999])
            ->assertSessionHasErrors('category_id');
    }

    public function test_without_manual_products_photos_still_upload(): void
    {
        config(['shop.catalog.manual_products' => false]);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", [
            'action' => 'draft',
            'images' => [$this->image('site.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['site.jpg'], $this->product->images()->pluck('original_name')->all());
    }

    // --- Товар с GUID 1С: поля 1С только для чтения (docs/1C_EXCHANGE_SPEC.md, раздел 4, правило 5) ---

    private function linkTo1c(): void
    {
        $this->product->forceFill(['guid' => 'p-guid-1', 'article' => 'START-1', 'unit' => 'компл'])->save();
    }

    public function test_product_with_guid_shows_1c_fields_read_only(): void
    {
        $this->linkTo1c();

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()->getContent();

        $this->assertStringContainsString('поля с подписью «из 1С»', $html);
        foreach (['f-name', 'f-brand', 'f-price', 'f-vat', 'f-stock', 'f-article', 'f-unit'] as $id) {
            $this->assertMatchesRegularExpression('/id="'.$id.'-hint"[^>]*>\s*из 1С/u', $html, $id);
        }
        foreach (['f-name', 'f-brand', 'f-price', 'f-stock', 'f-article', 'f-unit'] as $id) {
            $this->assertMatchesRegularExpression('/<input[^>]*id="'.$id.'"[^>]*\sdisabled(?=[\s=>\/])/s', $html, $id);
        }
        $this->assertMatchesRegularExpression('/<select[^>]*id="f-vat"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        $this->assertStringContainsString('value="START-1"', $html);
        $this->assertStringContainsString('value="компл"', $html);
        // SKU у товара с GUID — только чтение: код сайта 1С хранит у себя
        $this->assertMatchesRegularExpression('/<input[^>]*id="f-sku"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        $this->assertMatchesRegularExpression('/id="f-sku-hint"[^>]*>\s*код обмена с 1С/u', $html);
        // Не из 1С — редактируются
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-min_qty"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
    }

    public function test_product_with_guid_ignores_1c_fields_on_save(): void
    {
        $this->linkTo1c();

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft',
            'name' => 'Подмена', 'brand' => 'Hansgrohe', 'price' => 1, 'vat_rate' => 10, 'stock' => 99,
            'sku' => 'GR-002', 'min_qty' => 2, 'qty_step' => 2, 'default_qty' => 2,
        ]))->assertSessionHasNoErrors();

        $product = $this->product->fresh();
        $this->assertSame(['Grohe Start', 'Grohe', 100.0, 20.0, 5], [$product->name, $product->brand, (float) $product->price, (float) $product->vat_rate, $product->stock]);
        // SKU у товара с GUID не меняется; поля не из списка 1С — как прежде, сразу
        $this->assertSame(['GR-001', 2, 2], [$product->sku, $product->min_qty, $product->qty_step]);
    }

    public function test_product_with_guid_saves_without_1c_fields_in_request(): void
    {
        $this->linkTo1c();
        // Отключённые поля браузер не отправляет — обязательность к ним не применяется
        $data = array_diff_key($this->edit(['action' => 'draft', 'description' => 'Новое']), array_flip(['name', 'brand', 'price', 'vat_rate', 'stock']));

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $data)->assertSessionHasNoErrors();

        $this->assertSame('Новое', $this->product->fresh()->draft->data['description']);
    }

    public function test_managed_fields_come_from_config(): void
    {
        $this->linkTo1c();
        // 1С присылает название, цену не присылает; кратность в списке — всё равно не из 1С (ведёт сайт)
        config(['shop.exchange_1c.managed_fields' => ['name', 'min_qty', 'qty_step']]);

        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="f-name-hint"[^>]*>\s*из 1С/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="f-min_qty-hint"[^>]*>\s*из 1С/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-min_qty"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-price"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
        // Артикул не из списка 1С — редактируется
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-article"[^>]*\sdisabled(?=[\s=>\/])/s', $html);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}", $this->edit([
            'action' => 'draft', 'name' => 'Подмена', 'price' => 120, 'min_qty' => 3, 'qty_step' => 3, 'default_qty' => 3,
        ]))->assertSessionHasNoErrors();

        $product = $this->product->fresh();
        $this->assertSame(['Grohe Start', 120.0, 3, 3], [$product->name, (float) $product->price, $product->min_qty, $product->qty_step]);
    }

    public function test_default_managed_fields(): void
    {
        $this->assertSame(['article', 'name', 'brand', 'unit', 'vat_rate', 'price', 'stock'], config('shop.exchange_1c.managed_fields'));
    }

    public function test_product_without_guid_has_no_1c_hints(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/-hint"[^>]*>\s*из 1С/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="f-name"[^>]*\sdisabled(?=[\s=>\/])/s', $html);
    }
}
