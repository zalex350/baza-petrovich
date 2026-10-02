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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeaderTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create([
            'name'            => 'Иван Клиентов',
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
            'shop.phone'         => '+375 (17) 123-45-67',
            'shop.email'         => 'sales@smesitel.by',
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

        $this->actingAs($client)->get('/')
            ->assertSee('Корзина: 2 поз.')
            ->assertSee('250.50 BYN');
    }

    public function test_empty_cart_for_guest(): void
    {
        $this->get('/')
            ->assertSee('Корзина: 0 поз.')
            ->assertSee('0.00 BYN')
            ->assertDontSee('data-cart-count', false);
    }

    // --- BR-008: активный раздел ---

    public function test_active_catalog_section_is_highlighted(): void
    {
        $this->get('/catalog')
            ->assertOk()
            ->assertSee('bg-blue-600 text-white', false);

        $this->get('/')
            ->assertDontSee('bg-blue-600 text-white', false);
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
