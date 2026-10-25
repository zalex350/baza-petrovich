<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeaderTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create([
            'name' => 'Иван Клиентов',
            'organization_id' => Organization::factory()->create(['name' => 'ООО Сантехторг'])->id,
        ]);
    }

    // --- BR-007: гость / авторизованный ---

    public function test_guest_sees_login_and_not_cabinet(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Войти')
            ->assertSee(route('login'), false)
            ->assertDontSee('Личный кабинет')
            ->assertDontSee('Мои заказы');
    }

    public function test_client_sees_cabinet_and_not_login(): void
    {
        $this->actingAs($this->client())->get('/')
            ->assertOk()
            ->assertSee('Личный кабинет')
            ->assertDontSee('Войти')
            ->assertSee('Иван Клиентов')
            ->assertSee('ООО Сантехторг')
            ->assertSeeInOrder(['Кабинет', 'Мои заказы', 'Профиль', 'Выход']);
    }

    public function test_admin_sees_admin_panel_link(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')
            ->assertSee('data-admin-link', false)
            ->assertSee('Админ-панель');
    }

    public function test_client_and_guest_do_not_see_admin_panel_link(): void
    {
        $this->actingAs($this->client())->get('/')
            ->assertDontSee('data-admin-link', false)
            ->assertDontSee('Админ-панель')
            ->assertDontSee('/admin/', false);

        $this->post('/logout');

        $this->get('/')->assertDontSee('Админ-панель')->assertDontSee('/admin/', false);
    }

    public function test_admin_sections_are_not_in_storefront_header(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')
            ->assertDontSee('href="/admin/orders"', false)
            ->assertDontSee('href="/admin/organizations"', false);
    }

    // --- Состав шапки ---

    public function test_header_has_contacts_and_info_links(): void
    {
        config([
            'shop.phone' => '+375 (17) 123-45-67',
            'shop.email' => 'sales@smesitel.by',
            'shop.working_hours' => 'Пн–Сб 10:00–19:00',
        ]);

        $this->get('/')
            ->assertSee('+375 (17) 123-45-67')
            ->assertSee('tel:+375171234567', false)
            ->assertSee('mailto:sales@smesitel.by', false)
            ->assertSee('Пн–Сб 10:00–19:00')
            ->assertSee('Доставка')
            ->assertSee('Контакты');
    }

    public function test_brands_renamed_to_manufacturers(): void
    {
        $this->get('/')
            ->assertSee('Производители')
            ->assertSee('href="#brands"', false)
            ->assertDontSee('Бренды');
    }

    public function test_header_has_catalog_search_cart_and_mobile_controls(): void
    {
        $this->get('/')
            ->assertSee('aria-controls="mega-menu"', false)
            ->assertSee('id="search-desktop"', false)
            ->assertSee('id="search-mobile"', false)
            ->assertSee('aria-controls="mobile-panel"', false)
            ->assertSee('catalog\/menu.json', false)
            ->assertSee('Корзина');
    }

    public function test_mobile_burger_is_left_of_logo(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Мобильная шапка (макет CartMobile): ☰ — логотип — корзина, поиск — строкой ниже
        $mobile = substr($html, strpos($html, 'x-data="mobileMenu('));

        $burger = strpos($mobile, 'x-ref="burger"');
        $logo = strpos($mobile, 'aria-label="'.config('shop.name').' — на главную"');
        $cart = strpos($mobile, 'aria-label="Корзина:');
        $search = strpos($mobile, 'id="mobile-search"');

        $this->assertNotFalse($burger);
        $this->assertLessThan($logo, $burger);
        $this->assertLessThan($cart, $logo);
        $this->assertLessThan($search, $cart);

        // Поиск виден всегда, без переключателя
        $this->assertStringNotContainsString('toggleSearch', $mobile);
        $this->assertDoesNotMatchRegularExpression('#id="mobile-search"[^>]*x-show#', $mobile);
    }

    public function test_cart_shows_positions_count_and_sum(): void
    {
        $client = $this->client();
        $cart = Cart::create(['user_id' => $client->id]);

        foreach ([['A-1', 2, 100], ['A-2', 1, 50.5]] as [$sku, $qty, $price]) {
            $product = Product::create(['name' => $sku, 'sku' => $sku, 'price' => $price, 'stock' => 10]);
            CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $qty, 'price_at_add' => $price]);
        }

        // Оформленный заказ в корзину не входит
        $done = Order::create(['user_id' => $client->id, 'status' => OrderStatus::New]);
        OrderItem::create(['order_id' => $done->id, 'product_id' => $product->id, 'quantity' => 5, 'price' => 1000]);

        // Сумма с НДС 20%: 200.00 + 40.00 и 50.50 + 10.10
        $this->actingAs($client)->get('/')
            ->assertSee('Корзина: 2 поз.')
            ->assertSee(Money::format(300.60))
            ->assertSee('title="с НДС, в т.ч. НДС '.Money::format(50.10).'"', false);
    }

    public function test_cart_sum_is_with_vat_by_lines_and_matches_cart_page(): void
    {
        $client = $this->client();
        $cart = Cart::create(['user_id' => $client->id]);

        // 20%: 99.99 + 20.00 (19.998); 10%: 70.35 + 7.04 (7.035); «Без НДС»: 100.00 + 0
        foreach ([['B-1', 3, 33.33, 20], ['B-2', 7, 10.05, 10], ['B-3', 2, 50, null]] as [$sku, $qty, $price, $rate]) {
            $product = Product::create(['name' => $sku, 'sku' => $sku, 'price' => $price, 'vat_rate' => $rate, 'stock' => 10]);
            CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $qty, 'price_at_add' => $price]);
        }

        // Недоступный товар в сумму не входит
        $hidden = Product::create(['name' => 'B-4', 'sku' => 'B-4', 'price' => 999, 'stock' => 10, 'is_active' => false]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $hidden->id, 'quantity' => 1, 'price_at_add' => 999]);

        $this->actingAs($client)->get('/')
            ->assertSee('Корзина: 3 поз.')
            ->assertSee(Money::format(297.38))
            ->assertSee('title="с НДС, в т.ч. НДС '.Money::format(27.04).'"', false);

        // Та же сумма, что «Итого с НДС» на странице корзины
        $this->actingAs($client)->get('/order')
            ->assertSeeInOrder(['Итого с НДС', Money::format(297.38)]);
    }

    public function test_empty_cart_for_guest(): void
    {
        $html = $this->get('/')
            ->assertSee('Корзина: 0 поз.')
            ->assertSee(Money::format(0))
            ->assertSee('title="с НДС, в т.ч. НДС '.Money::format(0).'"', false)
            ->getContent();

        // Счётчик в разметке есть (его показывает cart-actions.js), но скрыт
        $this->assertMatchesRegularExpression('#data-cart-count\s+hidden#', $html);
        $this->assertMatchesRegularExpression('#data-cart-count-mobile\s+hidden#', $html);
    }

    // --- BR-008: активный раздел ---

    public function test_active_catalog_section_is_highlighted(): void
    {
        $active = '#bg-link text-white[^>]*data-mega-button#s';

        $this->assertMatchesRegularExpression($active, $this->get('/catalog')->assertOk()->getContent());
        $this->assertDoesNotMatchRegularExpression($active, $this->get('/')->getContent());
    }

    public function test_active_cart_section_is_highlighted(): void
    {
        $this->actingAs($this->client())->get('/order')
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    public function test_storefront_does_not_use_admin_layout(): void
    {
        $this->get('/')->assertDontSee('data-layout="admin"', false);
    }
}
