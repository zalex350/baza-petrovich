<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductDraft;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Характеристики товара (SRS §4.9, §4.18; BR-058…062, BR-075…079, BR-082; AC-035, AC-036).
 */
class ProductAttributesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.catalog.manual_products' => true]);

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->product = Product::create([
            'name' => 'Grohe Start',
            'sku' => 'GR-001',
            'price' => 100,
            'stock' => 5,
            'category_id' => Category::create(['name' => 'Смесители', 'slug' => 'smesiteli'])->id,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    private function attribute(string $name, array $extra = []): ProductAttribute
    {
        return ProductAttribute::create(['name' => $name] + $extra);
    }

    private function value(ProductAttribute $attribute, string $value, int $order = 0, ?Product $product = null): ProductAttributeValue
    {
        return ProductAttributeValue::create([
            'product_id' => ($product ?? $this->product)->id,
            'attribute_id' => $attribute->id,
            'value' => $value,
            'value_number' => ProductAttribute::parseNumber($value),
            'sort_order' => $order,
        ]);
    }

    /**
     * Сохранение формы товара с характеристиками ($rows — [название, значение]).
     */
    private function save(array $rows, string $action = 'draft')
    {
        return $this->actingAs($this->admin)
            ->from("/admin/product/{$this->product->id}")
            ->post("/admin/product/{$this->product->id}", [
                'action' => $action,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'price' => $this->product->price,
                'stock' => $this->product->stock,
                'attributes_sent' => 1,
                'attributes' => array_map(fn ($r) => ['name' => $r[0], 'value' => $r[1]], $rows),
            ]);
    }

    private function live(): array
    {
        return $this->product->attributeValues()->with('attribute')->get()
            ->map(fn ($v) => [$v->attribute->name, $v->value])->all();
    }

    // --- Справочник: нормализация и уникальность ---

    public function test_names_are_normalized_case_and_spaces_insensitive(): void
    {
        $color = $this->attribute('  Цвет  корпуса: ');

        $this->assertSame('Цвет корпуса', $color->name);
        $this->assertSame('цвет корпуса', $color->name_key);

        foreach (['цвет корпуса', 'ЦВЕТ КОРПУСА', 'Цвет   корпуса:'] as $variant) {
            $this->assertTrue(ProductAttribute::findOrCreateByName($variant)->is($color), $variant);
        }

        $this->assertSame(ProductAttribute::key('Материал корпуса'), ProductAttribute::key('материал  корпуса'));
        $this->assertSame(ProductAttribute::key('Тёплый пол'), ProductAttribute::key('теплый пол'));
        $this->assertSame(1, ProductAttribute::count());
    }

    public function test_dictionary_rejects_duplicate_name_ignoring_case(): void
    {
        $this->attribute('Цвет');

        $this->actingAs($this->admin)->post('/admin/attribute', ['name' => 'ЦВЕТ', 'type' => 'text'])
            ->assertSessionHasErrors('name_key');

        $this->assertSame(1, ProductAttribute::count());
    }

    public function test_one_key_per_product_in_database(): void
    {
        $color = $this->attribute('Цвет');
        $this->value($color, 'Хром');

        $this->expectException(QueryException::class);
        $this->value($color, 'Белый');
    }

    public function test_form_rejects_same_key_twice_for_product(): void
    {
        $this->save([['Цвет', 'Хром'], ['цвет', 'Белый']])
            ->assertSessionHasErrors('attributes');

        $this->assertSame([], $this->live());
        $this->assertNull($this->product->fresh()->draft);
    }

    // --- Форма товара ---

    public function test_new_name_is_added_to_dictionary_existing_is_reused(): void
    {
        $this->attribute('Цвет');

        $this->save([['цвет', 'Хром'], ['Материал', 'Латунь']], 'publish')->assertSessionHasNoErrors();

        $this->assertSame(2, ProductAttribute::count());
        $this->assertSame([['Цвет', 'Хром'], ['Материал', 'Латунь']], $this->live());
    }

    public function test_order_is_kept_and_shown_on_card(): void
    {
        $this->save([['Материал', 'Латунь'], ['Цвет', 'Хром'], ['Высота', '120']], 'publish');

        $this->assertSame([['Материал', 'Латунь'], ['Цвет', 'Хром'], ['Высота', '120']], $this->live());

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSeeInOrder(['Материал', 'Латунь', 'Цвет', 'Хром', 'Высота', '120']);

        // Перестановка — новый порядок
        $this->save([['Высота', '120'], ['Материал', 'Латунь'], ['Цвет', 'Хром']], 'publish');
        $this->assertSame([['Высота', '120'], ['Материал', 'Латунь'], ['Цвет', 'Хром']], $this->live());
    }

    public function test_removed_row_is_deleted_and_blank_rows_are_skipped(): void
    {
        $this->save([['Цвет', 'Хром'], ['Материал', 'Латунь']], 'publish');
        $this->save([['Цвет', 'Хром'], ['', '']], 'publish')->assertSessionHasNoErrors();

        $this->assertSame([['Цвет', 'Хром']], $this->live());
    }

    public function test_name_without_value_and_value_without_name_are_errors(): void
    {
        $this->save([['Цвет', ''], ['', 'Латунь']])
            ->assertSessionHasErrors('attributes');

        $errors = session('errors')->get('attributes');
        $this->assertCount(2, $errors);
    }

    public function test_number_attribute_requires_number_and_stores_value_number(): void
    {
        $this->attribute('Ширина', ['type' => 'number', 'unit' => 'мм']);

        $this->save([['Ширина', 'широкий']], 'publish')->assertSessionHasErrors('attributes');

        $this->save([['Ширина', '150,5']], 'publish')->assertSessionHasNoErrors();
        $this->assertSame(150.5, $this->product->attributeValues()->first()->value_number);
    }

    public function test_imported_long_and_not_number_values_are_saved_unchanged(): void
    {
        $kit = $this->attribute('Комплектация');
        $pressure = $this->attribute('Рабочее давление', ['type' => 'number', 'unit' => 'бар']);
        $long = str_repeat('Смеситель, гибкая подводка, крепёж, ', 20); // ~720 символов, больше 255
        $this->value($kit, trim($long));
        ProductAttributeValue::create([
            'product_id' => $this->product->id, 'attribute_id' => $pressure->id,
            'value' => '6 бар (10 для испытания)', 'value_number' => 6, 'sort_order' => 1,
        ]);

        // В форме — предупреждение у поля
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertOk()
            ->assertSee('data-attribute-warning', false)->assertSee('сохраняется как есть')
            ->assertSee('maxlength="1000"', false);

        // Ничего не меняли — сохраняется как есть, value_number из импорта остаётся
        foreach (['publish', 'draft'] as $action) {
            $this->save([['Комплектация', trim($long)], ['Рабочее давление', '6 бар (10 для испытания)']], $action)
                ->assertSessionHasNoErrors();
        }
        $this->assertSame([['Комплектация', trim($long)], ['Рабочее давление', '6 бар (10 для испытания)']], $this->live());
        $this->assertSame(6.0, (float) $pressure->values()->sole()->value_number);
        $this->assertSame(0, ProductDraft::count());

        // Изменённое — проверяется как раньше: только число
        $this->save([['Комплектация', trim($long)], ['Рабочее давление', '8 бар (10 для испытания)']], 'publish')
            ->assertSessionHasErrors('attributes');
        $this->assertSame('6 бар (10 для испытания)', $pressure->values()->sole()->value);

        // Длиннее 1000 — ошибка
        $this->save([['Комплектация', str_repeat('а', 1001)]], 'publish')->assertSessionHasErrors('attributes');

        $this->save([['Комплектация', trim($long)], ['Рабочее давление', '8']], 'publish')->assertSessionHasNoErrors();
        $this->assertSame(8.0, (float) $pressure->values()->sole()->value_number);
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertDontSee('data-attribute-warning', false);
    }

    public function test_not_number_value_is_unchanged_only_for_same_product(): void
    {
        $pressure = $this->attribute('Рабочее давление', ['type' => 'number']);
        $other = Product::create(['name' => 'Other', 'sku' => 'GR-002', 'price' => 1, 'stock' => 1]);
        $this->value($pressure, '6 бар (10 для испытания)', 0, $other);

        $this->save([['Рабочее давление', '6 бар (10 для испытания)']], 'publish')->assertSessionHasErrors('attributes');
        $this->assertSame([], $this->live());
    }

    public function test_attributes_go_through_draft_until_publish(): void
    {
        $this->save([['Цвет', 'Хром']], 'publish');

        $this->save([['Цвет', 'Белый']], 'draft')->assertSessionHasNoErrors();

        // На сайте — опубликованное, в предпросмотре и форме — черновик
        $this->assertSame([['Цвет', 'Хром']], $this->live());
        $this->actingAs($this->client)->get("/product/{$this->product->id}")->assertSee('Хром')->assertDontSee('Белый');
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}/preview")->assertSee('Белый');
        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->assertSee('value="Белый"', false);

        $this->actingAs($this->admin)->post("/admin/product/{$this->product->id}/publish");

        $this->assertSame([['Цвет', 'Белый']], $this->live());
        $this->assertNull($this->product->fresh()->draft);
    }

    public function test_unchanged_attributes_do_not_create_draft(): void
    {
        $this->save([['Цвет', 'Хром']], 'publish');
        $this->save([['Цвет', 'Хром']], 'draft');

        $this->assertSame(0, ProductDraft::count());
    }

    public function test_new_product_gets_attributes_immediately(): void
    {
        $this->actingAs($this->admin)->post('/admin/product', [
            'action' => 'draft',
            'name' => 'Новый',
            'sku' => 'NEW-1',
            'price' => 10,
            'stock' => 1,
            'attributes_sent' => 1,
            'attributes' => [['name' => 'Цвет', 'value' => 'Хром']],
        ])->assertSessionHasNoErrors();

        $product = Product::where('sku', 'NEW-1')->firstOrFail();
        $this->assertSame('Хром', $product->attributeValues()->first()->value);
    }

    public function test_product_form_has_attributes_block_with_dictionary(): void
    {
        $this->attribute('Цвет');
        $this->save([['Цвет', 'Хром']], 'publish');

        $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-attributes-block', false)
            ->assertSee('list="attribute-names"', false)
            ->assertSee('<option value="Цвет">', false)
            ->assertSee('name="attributes[0][name]"', false)
            ->assertSee('value="Хром"', false)
            ->assertSee('data-drag-handle', false);

        // Заполненная строка — не «запасная» (запасные с JS убираются)
        $html = $this->actingAs($this->admin)->get("/admin/product/{$this->product->id}")->getContent();
        $this->assertMatchesRegularExpression('#<li[^>]*data-attribute-row\s*>\s*<span[^>]*data-drag-handle[\s\S]*?name="attributes\[0\]\[name\]"#', $html);
        $this->assertSame(3, substr_count($html, 'data-spare'));
    }

    // --- Карточка товара ---

    public function test_card_shows_name_value_with_unit(): void
    {
        $this->value($this->attribute('Ширина', ['unit' => 'мм', 'type' => 'number']), '150');

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('data-specs', false)
            ->assertSeeInOrder(['Ширина', '150 мм']);
    }

    public function test_empty_values_are_not_shown(): void
    {
        $this->value($this->attribute('Цвет'), 'Хром', 0);
        $this->value($this->attribute('Материал'), '   ', 1);

        $html = $this->actingAs($this->client)->get("/product/{$this->product->id}")->getContent();

        $this->assertSame(1, substr_count($html, 'data-spec-row'));
        $this->assertStringNotContainsString('Материал', $html);
    }

    public function test_block_is_hidden_without_attributes(): void
    {
        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertDontSee('data-specs', false)
            ->assertDontSee("showTab('chars')", false);
    }

    public function test_broken_attributes_do_not_break_card(): void
    {
        $this->value($this->attribute('Цвет'), 'Хром');

        ProductAttributeValue::retrieved(fn () => throw new RuntimeException('сбой характеристик'));

        $this->actingAs($this->client)->get("/product/{$this->product->id}")
            ->assertOk()
            ->assertSee('Grohe Start')
            ->assertDontSee('data-spec-row', false);
    }

    // --- Объединение дублей ---

    public function test_merge_moves_values_and_deletes_duplicates(): void
    {
        $color = $this->attribute('Цвет');
        $colorBody = $this->attribute('Цвет корпуса');
        $tint = $this->attribute('Оттенок');

        $other = Product::create(['name' => 'Other', 'sku' => 'OT-1', 'price' => 1, 'stock' => 1, 'is_active' => true, 'is_published' => true]);
        $third = Product::create(['name' => 'Third', 'sku' => 'TH-1', 'price' => 1, 'stock' => 1, 'is_active' => true, 'is_published' => true]);

        $this->value($color, 'Хром', 0, $this->product);
        $this->value($colorBody, 'Белый', 1, $this->product); // у товара уже есть «Цвет» — отбрасывается
        $this->value($colorBody, 'Чёрный', 0, $other);
        $this->value($tint, 'Бронза', 0, $third);

        ProductDraft::create(['product_id' => $other->id, 'data' => ['attributes' => [['attribute_id' => $colorBody->id, 'value' => 'Серый']]]]);

        $this->actingAs($this->admin)->post('/admin/attributes/merge', [
            'target_id' => $color->id,
            'source_ids' => [$colorBody->id, $tint->id, $color->id],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Перенесено значений: 2') && str_contains($m, 'отброшено'));

        $this->assertSame(['Цвет'], ProductAttribute::pluck('name')->all());
        $this->assertSame('Хром', $this->product->attributeValues()->first()->value);
        $this->assertSame(1, $this->product->attributeValues()->count());
        $this->assertSame('Чёрный', $other->attributeValues()->first()->value);
        $this->assertSame('Бронза', $third->attributeValues()->first()->value);
        $this->assertSame($color->id, ProductDraft::where('product_id', $other->id)->first()->data['attributes'][0]['attribute_id']);
    }

    // --- Справочник в админке ---

    public function test_admin_manages_dictionary(): void
    {
        $this->actingAs($this->admin)->post('/admin/attribute', [
            'name' => 'Ширина', 'unit' => 'мм', 'type' => 'number', 'is_filterable' => 1,
        ])->assertRedirect('/admin/attributes');

        $width = ProductAttribute::firstOrFail();
        $this->assertTrue($width->is_filterable);
        $this->assertSame('мм', $width->unit);

        $this->actingAs($this->admin)->get('/admin/attributes')
            ->assertOk()
            ->assertSee('Ширина')
            ->assertSee('Объединить');

        $this->actingAs($this->admin)->post("/admin/attribute/{$width->id}", [
            'name' => 'Ширина изделия', 'unit' => 'см', 'type' => 'number',
        ])->assertSessionHasNoErrors();

        $width->refresh();
        $this->assertSame('Ширина изделия', $width->name);
        $this->assertFalse($width->is_filterable);
    }

    public function test_type_change_to_number_is_refused_for_text_values(): void
    {
        $size = $this->attribute('Размер');
        $this->value($size, 'большой');

        $this->actingAs($this->admin)->post("/admin/attribute/{$size->id}", ['name' => 'Размер', 'type' => 'number'])
            ->assertSessionHasErrors('type');

        $this->assertSame('text', $size->fresh()->type);
    }

    public function test_used_attribute_cannot_be_deleted(): void
    {
        $used = $this->attribute('Цвет');
        $this->value($used, 'Хром');
        $unused = $this->attribute('Вес');

        $this->actingAs($this->admin)->post("/admin/attribute/{$used->id}/delete")->assertSessionHas('error');
        $this->actingAs($this->admin)->post("/admin/attribute/{$unused->id}/delete")->assertSessionHas('success');

        $this->assertSame(['Цвет'], ProductAttribute::pluck('name')->all());
    }

    public function test_only_admin_manages_dictionary(): void
    {
        $this->actingAs($this->client)->get('/admin/attributes')->assertForbidden();
        $this->actingAs($this->client)->post('/admin/attribute', ['name' => 'X', 'type' => 'text'])->assertForbidden();
    }

    // --- Перенос из specifications ---

    public function test_specifications_text_is_moved_with_normalized_keys(): void
    {
        $this->product->update(['specifications' => "Цвет: Хром\nМатериал — латунь\nПросто текст\nЦвет: Белый"]);

        $other = Product::create(['name' => 'Other', 'sku' => 'OT-1', 'price' => 1, 'stock' => 1,
            'specifications' => "цвет : Белый\nВысота\t120", 'is_active' => true, 'is_published' => true]);

        $this->artisan('attributes:from-specifications')
            ->expectsOutputToContain('Товаров с характеристиками: 2, значений: 4, ключей: 3')
            ->expectsOutputToContain('Цвет | цвет')
            ->assertSuccessful();

        $this->assertSame(3, ProductAttribute::count());
        $this->assertSame([['Цвет', 'Хром'], ['Материал', 'латунь']], $this->live());
        $this->assertSame(120.0, $other->attributeValues()->get()[1]->value_number);

        // Нераспознанное и повтор ключа остаются в тексте
        $this->assertSame("Просто текст\nЦвет: Белый", $this->product->fresh()->specifications);
        $this->assertNull($other->fresh()->specifications);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->product->update(['specifications' => 'Цвет: Хром']);

        $this->artisan('attributes:from-specifications', ['--dry-run' => true])
            ->expectsOutputToContain('Проверка без записи')
            ->assertSuccessful();

        $this->assertSame(0, ProductAttribute::count());
        $this->assertSame('Цвет: Хром', $this->product->fresh()->specifications);
    }
}
