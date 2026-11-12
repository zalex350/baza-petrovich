<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Админка «Категории»: создание с родителем, переименование (slug сохраняется), перемещение
 * без циклов, порядок (вверх / вниз → меню и каталог), скрыть, удаление только пустой, кэш меню.
 */
class AdminCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function category(string $name, ?Category $parent = null, int $order = 0): Category
    {
        static $n = 0;
        $n++;

        return Category::create(['name' => $name, 'slug' => "c-{$n}", 'parent_id' => $parent?->id, 'sort_order' => $order]);
    }

    private function product(Category $category): Product
    {
        static $n = 0;
        $n++;

        return Product::create(['name' => "Товар {$n}", 'sku' => "P-{$n}", 'price' => 10, 'stock' => 1,
            'is_active' => true, 'is_published' => true, 'category_id' => $category->id]);
    }

    /** Кэш меню заполнен — действие должно его сбросить */
    private function warmMenu(): void
    {
        app(CatalogMenu::class)->tree();
        $this->assertTrue(Cache::has(CatalogMenu::CACHE_KEY));
    }

    public function test_index_shows_tree_with_counts_and_actions(): void
    {
        $root = $this->category('Смесители');
        $child = $this->category('Для кухни', $root);
        $this->product($child);

        $html = $this->actingAs($this->admin)->get('/admin/categories')->assertOk()
            ->assertSeeInOrder(['Смесители', 'Для кухни'])
            ->assertSee('Добавить категорию')
            ->getContent();

        $this->assertStringContainsString('data-category-row="'.$child->id.'" data-depth="1"', $html);
        $this->assertStringContainsString('action="/admin/category/'.$root->id.'/delete"', $html);
        $this->assertStringContainsString('action="/admin/category/'.$root->id.'/toggle-visibility"', $html);
        // Главная колонка и «Действия» закреплены при прокрутке вбок
        $this->assertSame(2 * 2, substr_count($html, 'data-sticky='));

        // Поиск — плоский список с путём к найденной
        $this->actingAs($this->admin)->get('/admin/categories?q=кухни')->assertOk()
            ->assertSee('Смесители')
            ->assertSee('Для кухни')
            ->assertDontSee('data-category-move', false);
    }

    public function test_create_with_parent_and_unique_slug(): void
    {
        $root = $this->category('Смесители');
        $this->category('Старая', $root, 5);
        // Адрес из названия уже занят — с суффиксом
        $base = Str::slug('Для кухни');
        Category::create(['name' => 'X', 'slug' => $base]);

        $this->actingAs($this->admin)->get("/admin/category/create?parent={$root->id}")->assertOk()
            ->assertSee('Родительская категория');

        $this->warmMenu();
        $this->actingAs($this->admin)->post('/admin/category', ['name' => '  Для кухни ', 'parent_id' => $root->id])
            ->assertRedirect('/admin/categories')->assertSessionHas('success', 'Категория «Для кухни» создана');

        $created = Category::where('name', 'Для кухни')->sole();
        $this->assertSame([$root->id, $base.'-2', 6, false], [$created->parent_id, $created->slug, $created->sort_order, $created->is_hidden]);
        $this->assertFalse(Cache::has(CatalogMenu::CACHE_KEY));

        // Верхний уровень и свой адрес
        $this->actingAs($this->admin)->post('/admin/category', ['name' => 'Ванны', 'slug' => 'baths'])->assertSessionHasNoErrors();
        $this->assertNull(Category::where('slug', 'baths')->sole()->parent_id);

        $this->actingAs($this->admin)->post('/admin/category', ['name' => '', 'slug' => 'Не Латиница'])
            ->assertSessionHasErrors(['name' => 'Укажите название', 'slug' => 'Адрес — латиница в нижнем регистре, цифры и дефисы']);
    }

    public function test_rename_keeps_slug_and_move_goes_last_without_cycles(): void
    {
        $a = $this->category('Раздел A');
        $b = $this->category('Раздел B');
        $this->category('Подраздел B1', $b, 0);
        $child = $this->category('Подраздел A1', $a);
        $grandchild = $this->category('Подраздел A1a', $child);
        $slug = $child->slug;

        $this->warmMenu();
        $this->actingAs($this->admin)->post("/admin/category/{$child->id}", ['name' => 'Новое имя', 'parent_id' => $a->id, 'slug' => $slug])
            ->assertRedirect('/admin/categories');
        $this->assertSame(['Новое имя', $slug], [$child->fresh()->name, $child->fresh()->slug]);
        $this->assertFalse(Cache::has(CatalogMenu::CACHE_KEY));

        // Перемещение — в конец соседей нового родителя, вместе с подкатегориями
        $this->actingAs($this->admin)->post("/admin/category/{$child->id}", ['name' => 'Новое имя', 'parent_id' => $b->id]);
        $this->assertSame([$b->id, 1], [$child->fresh()->parent_id, $child->fresh()->sort_order]);
        $this->assertSame($child->id, $grandchild->fresh()->parent_id);

        // В себя и в свою подкатегорию — нельзя; в форме таких вариантов нет
        foreach ([$child->id, $grandchild->id] as $parent) {
            $this->actingAs($this->admin)->post("/admin/category/{$child->id}", ['name' => 'Новое имя', 'parent_id' => $parent])
                ->assertSessionHasErrors(['parent_id' => 'Нельзя перенести категорию в саму себя или в её подкатегорию']);
        }
        $this->actingAs($this->admin)->get("/admin/category/{$child->id}/edit")->assertOk()
            ->assertDontSee('value="'.$grandchild->id.'"', false)
            ->assertSee('value="'.$a->id.'"', false);

        // На верхний уровень
        $this->actingAs($this->admin)->post("/admin/category/{$child->id}", ['name' => 'Новое имя', 'parent_id' => '']);
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_order_up_down_changes_menu_and_catalog(): void
    {
        $first = $this->category('Яблоки');
        $second = $this->category('Апельсины');
        $this->product($first);
        $this->product($second);

        // Равный порядок — по названию
        $names = fn () => array_column(app(CatalogMenu::class)->build(), 'name');
        $this->assertSame(['Апельсины', 'Яблоки'], $names());

        $this->warmMenu();
        $this->actingAs($this->admin)->from('/admin/categories')
            ->post("/admin/category/{$first->id}/move", ['direction' => 'up'])
            ->assertRedirect('/admin/categories');

        $this->assertSame(['Яблоки', 'Апельсины'], $names());
        $this->assertFalse(Cache::has(CatalogMenu::CACHE_KEY));
        $this->actingAs(User::factory()->create())->get('/catalog')->assertOk()->assertSeeInOrder(['Яблоки', 'Апельсины']);

        // Крайний — без изменений; кнопка отключена
        $this->actingAs($this->admin)->post("/admin/category/{$first->id}/move", ['direction' => 'up']);
        $this->assertSame(['Яблоки', 'Апельсины'], $names());
        $html = $this->actingAs($this->admin)->get('/admin/categories')->getContent();
        $this->assertMatchesRegularExpression('#/admin/category/'.$first->id.'/move.*?<button[^>]*disabled[^>]*data-category-move="up"#s', $html);

        $this->actingAs($this->admin)->post("/admin/category/{$first->id}/move", ['direction' => 'down']);
        $this->assertSame(['Апельсины', 'Яблоки'], $names());
    }

    public function test_hide_and_show(): void
    {
        $category = $this->category('Смесители');
        $child = $this->category('Для кухни', $category);
        $this->product($child);
        $this->assertCount(1, app(CatalogMenu::class)->build());

        $this->warmMenu();
        $this->actingAs($this->admin)->post("/admin/category/{$category->id}/toggle-visibility")
            ->assertSessionHas('success', 'Категория «Смесители» скрыта — нет в меню и каталоге вместе с подкатегориями');
        $this->assertTrue($category->fresh()->is_hidden);
        $this->assertFalse(Cache::has(CatalogMenu::CACHE_KEY));
        $this->assertSame([], app(CatalogMenu::class)->build());
        $this->actingAs($this->admin)->get('/admin/categories')->assertSee('скрыта')->assertSee('Показать');

        $this->actingAs($this->admin)->post("/admin/category/{$category->id}/toggle-visibility");
        $this->assertFalse($category->fresh()->is_hidden);

        // Через форму
        $this->actingAs($this->admin)->post("/admin/category/{$category->id}", ['name' => 'Смесители', 'is_hidden' => '1']);
        $this->assertTrue($category->fresh()->is_hidden);
    }

    public function test_delete_only_empty(): void
    {
        $root = $this->category('Смесители');
        $child = $this->category('Для кухни', $root);
        $this->product($root);
        $this->product($root);

        $this->actingAs($this->admin)->from('/admin/categories')->post("/admin/category/{$root->id}/delete")
            ->assertRedirect('/admin/categories')
            ->assertSessionHas('error', 'Нельзя удалить «Смесители»: товаров — 2, подкатегорий — 1. Сначала перенесите их в другую категорию.');
        $this->assertNotNull($root->fresh());

        $this->actingAs($this->admin)->get("/admin/category/{$root->id}/edit")->assertSee('Удалить нельзя: товаров — 2, подкатегорий — 1');

        $this->warmMenu();
        $this->actingAs($this->admin)->post("/admin/category/{$child->id}/delete")
            ->assertRedirect('/admin/categories')->assertSessionHas('success', 'Категория «Для кухни» удалена');
        $this->assertNull(Category::find($child->id));
        $this->assertFalse(Cache::has(CatalogMenu::CACHE_KEY));
    }

    public function test_edit_page_has_image_form_and_guests_cannot_manage(): void
    {
        $category = $this->category('Смесители');

        $this->actingAs($this->admin)->get("/admin/category/{$category->id}/edit")->assertOk()
            ->assertSee('action="/admin/category/'.$category->id.'/image"', false)
            ->assertSee('data-category-delete', false);

        $client = User::factory()->create();
        $this->actingAs($client)->post('/admin/category', ['name' => 'X'])->assertForbidden();
        $this->actingAs($client)->post("/admin/category/{$category->id}/delete")->assertForbidden();
        $this->assertNotNull($category->fresh());
    }
}
