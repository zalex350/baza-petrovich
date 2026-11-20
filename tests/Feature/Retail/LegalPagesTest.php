<?php

namespace Tests\Feature\Retail;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Юридические страницы: черновики из petrovich:legal, реквизиты из «Настроек» метками [[…]],
 * ссылки в подвале, формах и баннере cookie — только у опубликованных.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_drafts_and_keeps_existing(): void
    {
        Artisan::call('petrovich:legal');

        foreach (array_keys(Page::LEGAL) as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();
            $this->assertFalse($page->is_published, "{$slug} — черновик");
        }

        // Черновик посетителю не виден, ссылок на него нет
        $this->get('/page/privacy')->assertNotFound();
        $this->get('/')->assertDontSee('data-footer-legal', false);

        Page::where('slug', 'privacy')->update(['body' => '<p>Свой текст</p>', 'is_published' => true]);
        Artisan::call('petrovich:legal');
        $this->assertSame('<p>Свой текст</p>', Page::where('slug', 'privacy')->value('body'), 'без --force не перезаписывается');
    }

    public function test_tokens_filled_from_settings(): void
    {
        Artisan::call('petrovich:legal', ['--publish' => true]);
        Setting::put([
            'seller_full_name' => 'ООО «Петрович СТД»',
            'seller_unp' => '191234567',
            'contact_email' => 'info@example.by',
            'contact_phones' => json_encode([['label' => '', 'number' => '+375 29 000-00-00']]),
        ]);

        $this->get('/page/privacy')
            ->assertOk()
            ->assertSee('ООО «Петрович СТД», УНП 191234567')
            ->assertSee('info@example.by')
            ->assertSee('+375 29 000-00-00')
            ->assertSee(Setting::PLACEHOLDER) // ответственный за ПД не заполнен
            ->assertDontSee('[[', false);

        $this->get('/page/oferta')->assertOk()->assertSee('Торговом реестре');
        $this->get('/page/vozvrat')->assertOk()->assertSee('14 дней');

        // Метка не из списка настроек — не раскрывает ничего лишнего
        $this->assertSame(Setting::PLACEHOLDER, Page::fillTokens('[[app_key]]'));
    }

    public function test_links_in_footer_forms_and_cookie_banner(): void
    {
        Artisan::call('petrovich:legal', ['--publish' => true]);

        $this->get('/')->assertSee('href="/page/privacy"', false)->assertSee('data-footer-legal', false);
        $this->get('/request/callback')->assertSee('data-legal-note', false)->assertSee('политике обработки персональных данных');

        Setting::put(['analytics_ga4_id' => 'G-TEST1234']);
        $this->get('/')->assertSee('data-cookie-banner="required"', false)->assertSee('Подробнее');
    }
}
