<?php

namespace Tests\Feature\Storefront;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Главное меню под шапкой (SRS §3.5): пункты, подсветка активного, те же пункты в меню ☰,
 * «Стать партнёром» — только гостю.
 */
class MainMenuTest extends TestCase
{
    use RefreshDatabase;

    private const ITEMS = ['Главная', 'Каталог', 'Акции', 'Новинки', 'Доставка и оплата', 'О компании', 'Контакты'];

    public function test_menu_has_all_items_in_order(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $menu = $this->between($html, 'data-main-menu', '</nav>');

        $this->assertSame(self::ITEMS, array_map('trim', $this->texts($menu)));
    }

    public function test_active_item_is_highlighted(): void
    {
        foreach (['/' => 'Главная', '/promo' => 'Акции', '/new' => 'Новинки', '/delivery' => 'Доставка и оплата', '/about' => 'О компании', '/contacts' => 'Контакты', '/catalog' => 'Каталог'] as $url => $label) {
            $menu = $this->between($this->get($url)->assertOk()->getContent(), 'data-main-menu', '</nav>');

            $this->assertMatchesRegularExpression('#aria-current="page"\s*>'.preg_quote($label, '#').'</a>#u', $menu, $url);
            $this->assertSame(1, substr_count($menu, 'aria-current="page"'), $url);
        }
    }

    public function test_mobile_panel_has_menu_items(): void
    {
        $html = $this->get('/promo')->getContent();

        $this->assertSame(6, substr_count($html, 'data-mobile-menu-item'));
        $this->assertMatchesRegularExpression('#aria-current="page"\s+data-mobile-menu-item\s*>Акции</a>#u', $html);
    }

    public function test_partner_button_only_for_guest(): void
    {
        $this->get('/')->assertSee('href="/partner"', false)->assertSee('Стать партнёром');

        $client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($client)->get('/')->assertDontSee('data-partner-link', false);
    }

    public function test_topbar_links_lead_to_pages(): void
    {
        $this->get('/')
            ->assertSee('href="/delivery"', false)
            ->assertSee('href="/contacts"', false)
            ->assertDontSee('href="#delivery"', false);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "Нет {$from}");

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }

    /**
     * @return list<string>
     */
    private function texts(string $html): array
    {
        preg_match_all('#<a[^>]*>([^<]+)</a>#u', $html, $m);

        return $m[1];
    }
}
