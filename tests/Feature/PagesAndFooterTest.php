<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\Html;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Информационные страницы (Page), их админка и подвал с реквизитами из настроек.
 */
class PagesAndFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_menu_pages_exist_with_placeholders(): void
    {
        foreach (['/delivery' => 'Доставка и оплата', '/about' => 'О компании', '/contacts' => 'Контакты'] as $url => $title) {
            $this->get($url)
                ->assertOk()
                ->assertSee($title)
                ->assertSee('[ЗАПОЛНИТЬ]');
        }

        $this->get('/page/delivery')->assertRedirect('/delivery');
    }

    public function test_page_body_is_sanitized_and_seo_used(): void
    {
        Page::where('slug', 'about')->update([
            'body' => '<h2>Кто мы</h2><p onclick="x()">Текст <a href="javascript:alert(1)">плохая</a> <a href="https://smesitel.by">хорошая</a></p><script>alert(1)</script>',
            'seo_title' => 'О нас — сантехника',
            'seo_description' => 'Описание для поиска',
        ]);

        $html = $this->get('/about')->assertOk()->getContent();

        $body = substr($html, strpos($html, 'data-page-body'));
        $this->assertStringContainsString('<h2>Кто мы</h2>', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('href="https://smesitel.by"', $body);
        $this->assertStringContainsString('<title>', $html);
        $this->assertStringContainsString('О нас — сантехника', $html);
        $this->assertStringContainsString('<meta name="description" content="Описание для поиска">', $html);
    }

    public function test_unpublished_page_is_404_for_guest_and_visible_to_admin(): void
    {
        Page::where('slug', 'about')->update(['is_published' => false]);

        $this->get('/about')->assertNotFound();
        $this->actingAs($this->admin)->get('/about')->assertOk()->assertSee('Страница не опубликована');
    }

    public function test_admin_edits_menu_page_but_cannot_change_slug_or_delete(): void
    {
        $page = Page::where('slug', 'delivery')->first();

        $this->actingAs($this->admin)->get("/admin/page/{$page->id}")
            ->assertOk()
            ->assertSee('trix-editor', false)
            ->assertSee('editor-', false);

        $this->actingAs($this->admin)->post("/admin/page/{$page->id}", [
            'title' => 'Доставка и оплата',
            'body' => '<p>Доставляем по Минску</p>',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $this->get('/delivery')->assertSee('Доставляем по Минску');

        $this->actingAs($this->admin)->post("/admin/page/{$page->id}", ['title' => 'X', 'slug' => 'other'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)->post("/admin/page/{$page->id}/delete")->assertSessionHas('error');
        $this->assertNotNull($page->fresh());
    }

    public function test_admin_creates_and_deletes_custom_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/page', [
            'title' => 'Гарантия',
            'slug' => 'garantiya',
            'body' => '<p>Условия</p>',
            'is_published' => '1',
        ])->assertSessionHasNoErrors();

        $page = Page::where('slug', 'garantiya')->firstOrFail();
        $this->get('/page/garantiya')->assertOk()->assertSee('Условия');

        $this->actingAs($this->admin)->post('/admin/page', ['title' => 'Дубль', 'slug' => 'garantiya'])
            ->assertSessionHasErrors(['slug' => 'Страница с таким адресом уже есть']);

        $this->actingAs($this->admin)->post("/admin/page/{$page->id}/delete")->assertRedirect('/admin/pages');
        $this->get('/page/garantiya')->assertNotFound();
    }

    public function test_footer_shows_three_columns_and_requisites_with_placeholders(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-footer-about', $html);
        $this->assertStringContainsString('data-footer-delivery', $html);
        $this->assertStringContainsString('data-footer-contacts', $html);
        $this->assertStringContainsString('href="/delivery"', $html);
        // Контакты не заполнены — не выводятся (ни заглушки, ни номера из config)
        $this->assertStringNotContainsString(config('shop.phone'), $html);
        $this->assertStringNotContainsString('href="tel:', $html);

        $seller = substr($html, strpos($html, 'data-seller'));
        $this->assertSame(4, substr_count(substr($seller, 0, strpos($seller, '</dl>')), Setting::PLACEHOLDER));
    }

    public function test_settings_saved_in_admin_appear_in_footer(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings', [
            'seller_name' => 'ООО «Тест»',
            'seller_unp' => '800013732',
            'seller_address' => 'г. Минск, ул. Тестовая, 1',
            'seller_registry' => '№ 000 от 01.01.2026',
            'footer_about' => 'Кратко о нас',
            'footer_delivery' => '',
        ])->assertSessionHas('success');

        $this->get('/')
            ->assertSee('ООО «Тест»')
            ->assertSee('800013732')
            ->assertSee('г. Минск, ул. Тестовая, 1')
            ->assertSee('№ 000 от 01.01.2026')
            ->assertSee('Кратко о нас');

        $this->assertSame(Setting::PLACEHOLDER, Setting::get('footer_delivery'));
    }

    public function test_html_cleaner_keeps_text_markup_only(): void
    {
        $this->assertSame(
            '<p>Текст <strong>жирный</strong> <a href="/catalog">ссылка</a>в</p>',
            Html::clean('<p style="color:red">Текст <strong>жирный</strong> <a href="/catalog" target="_blank">ссылка</a><span>в</span></p>')
        );
        $this->assertSame('', Html::clean('<script>alert(1)</script>'));
    }
}
