<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\UserInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Личный кабинет (SRS §8): меню разделов, реквизиты организации и её пользователи —
 * только просмотр, профиль — только свои ФИО и телефон, смена пароля, заказы организации.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $client;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'ООО «Аква»', 'legal_name' => 'Общество с ограниченной ответственностью «Аква»',
            'unp' => '190123456', 'address' => 'г. Минск, ул. Тестовая, 1', 'phone' => '+375 17 000-00-00', 'email' => 'aqua@example.by',
        ]);
        $this->client = User::factory()->create(['name' => 'Иван Клиентов', 'organization_id' => $this->organization->id]);
        $this->colleague = User::factory()->create(['name' => 'Пётр Коллегин', 'organization_id' => $this->organization->id]);
    }

    public function test_menu_has_all_sections_and_highlights_current(): void
    {
        $html = $this->actingAs($this->client)->get('/account/organization')->assertOk()->getContent();

        $menu = substr($html, strpos($html, 'data-account-menu'), strpos($html, '</nav>', strpos($html, 'data-account-menu')) - strpos($html, 'data-account-menu'));

        foreach (['/orders' => 'Мои заказы', '/account/organization' => 'Организация', '/account/users' => 'Пользователи организации', '/addresses' => 'Адреса доставки', '/profile' => 'Профиль', '/account/password' => 'Смена пароля'] as $url => $label) {
            $this->assertStringContainsString('href="'.$url.'"', $menu);
            $this->assertStringContainsString($label, $menu);
        }

        $this->assertSame(1, substr_count($menu, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="/account/organization"[^>]*aria-current="page"#s', $menu);

        // Тот же раздел подсвечен и на других страницах кабинета
        foreach (['/orders', '/addresses', '/profile', '/account/password', '/account/users'] as $url) {
            $this->actingAs($this->client)->get($url)->assertOk()->assertSee('data-account-menu', false);
        }
    }

    public function test_cabinet_menu_is_in_header_dropdown_and_mobile_panel(): void
    {
        $html = $this->actingAs($this->client)->get('/account/users')->assertOk()->getContent();

        // Шапка (десктоп): меню пользователя — те же 6 разделов, текущий подсвечен
        $this->assertSame(6, substr_count($html, 'data-user-menu-item'));
        $this->assertMatchesRegularExpression('#href="/account/users"[^>]*aria-current="page"[^>]*data-user-menu-item#s', $html);

        // Панель ☰ (телефон) — тоже 6
        $this->assertSame(6, substr_count($html, 'data-mobile-account-item'));

        // Главное меню витрины остаётся над кабинетом
        $this->assertStringContainsString('data-main-menu', $html);

        // Вне кабинета ни один раздел не подсвечен
        $home = $this->actingAs($this->client)->get('/')->getContent();
        $this->assertDoesNotMatchRegularExpression('#aria-current="page"[^>]*data-user-menu-item#s', $home);
    }

    public function test_admin_has_only_profile_and_password_sections(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get('/profile')
            ->assertOk()
            ->assertDontSee('href="/account/organization"', false)
            ->assertDontSee('href="/addresses"', false)
            ->assertSee('href="/account/password"', false)
            ->getContent();

        // Заказов и корзины у администратора нет (BR-121): в меню — только профиль и пароль
        $this->assertSame(2, substr_count($html, 'data-account-item'));
        $this->assertSame(2, substr_count($html, 'data-user-menu-item'));

        $this->actingAs($admin)->get('/account/organization')->assertForbidden();
        $this->actingAs($admin)->get('/account/users')->assertForbidden();
    }

    public function test_organization_requisites_are_read_only(): void
    {
        $html = $this->actingAs($this->client)->get('/account/organization')
            ->assertOk()
            ->assertSee('Общество с ограниченной ответственностью «Аква»')
            ->assertSee('190123456')
            ->assertSee('г. Минск, ул. Тестовая, 1')
            ->assertSee('aqua@example.by')
            ->getContent();

        // В разделе нет форм и полей ввода — только просмотр
        $section = substr($html, strpos($html, 'data-account>'));
        $this->assertStringNotContainsString('<form', substr($section, strpos($section, 'data-organization')));
        $this->assertStringNotContainsString('<input', substr($section, strpos($section, 'data-organization')));

        // Изменять реквизиты клиенту негде
        $this->actingAs($this->client)->post('/account/organization', ['name' => 'Взлом'])->assertStatus(405);
        $this->assertSame('ООО «Аква»', $this->organization->fresh()->name);
    }

    public function test_client_without_organization_gets_404(): void
    {
        $lonely = User::factory()->create(['organization_id' => null]);

        $this->actingAs($lonely)->get('/account/organization')->assertNotFound();
        $this->actingAs($lonely)->get('/account/users')->assertNotFound();
    }

    public function test_users_list_shows_only_own_organization_with_role_and_status(): void
    {
        Notification::fake();

        $blocked = User::factory()->create(['name' => 'Заблокированный Сотрудник', 'organization_id' => $this->organization->id, 'blocked_at' => now()]);
        $invited = User::factory()->create(['name' => 'Новый Сотрудник', 'organization_id' => $this->organization->id]);
        app(UserInvitations::class)->issue($invited);
        User::factory()->create(['name' => 'Чужой Человек', 'organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->client)->get('/account/users')
            ->assertOk()
            ->assertSee('Иван Клиентов')
            ->assertSee('(вы)')
            ->assertSee('Пётр Коллегин')
            ->assertSee($this->colleague->email)
            ->assertSee('Клиент')
            ->assertSeeInOrder(['Заблокированный Сотрудник', 'Заблокирован'])
            ->assertSeeInOrder(['Новый Сотрудник', 'Приглашён, пароль не установлен'])
            ->assertSee('Активен')
            ->assertDontSee('Чужой Человек');

        $html = $this->actingAs($this->client)->get('/account/users')->getContent();
        $this->assertStringNotContainsString('<form', substr($html, strpos($html, 'data-member')));

        $this->assertNotNull($blocked->fresh()->blocked_at);
    }

    public function test_client_cannot_create_or_block_users(): void
    {
        $this->actingAs($this->client)->post('/admin/user', ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->client)->post("/admin/user/{$this->colleague->id}/block")->assertForbidden();
        $this->assertNull($this->colleague->fresh()->blocked_at);
    }

    public function test_profile_shows_email_read_only_and_changes_only_own_data(): void
    {
        $this->actingAs($this->client)->get('/profile')
            ->assertOk()
            ->assertSee('data-profile-email', false)
            ->assertSee($this->client->email)
            ->assertDontSee('name="email"', false)
            ->assertSee('name="phone"', false);

        $this->actingAs($this->client)->patch('/profile', ['name' => '', 'phone' => 'abc'])
            ->assertSessionHasErrors(['name' => 'Укажите ФИО', 'phone']);

        // Чужие данные этим запросом не меняются: всегда текущий пользователь
        $this->actingAs($this->client)->patch('/profile', ['name' => 'Иван Новый', 'phone' => '', 'id' => $this->colleague->id]);
        $this->assertSame('Иван Новый', $this->client->fresh()->name);
        $this->assertNull($this->client->fresh()->phone);
        $this->assertSame('Пётр Коллегин', $this->colleague->fresh()->name);
    }

    public function test_password_change_page_and_rules(): void
    {
        $this->actingAs($this->client)->get('/account/password')
            ->assertOk()
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);

        $this->actingAs($this->client)->from('/account/password')->put('/password', [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'other',
        ])->assertSessionHasErrors('password');

        $this->actingAs($this->client)->put('/password', [
            'current_password' => 'password', 'password' => 'New-pass-123', 'password_confirmation' => 'New-pass-123',
        ])->assertRedirect('/account/password')->assertSessionHas('success');

        $this->post('/logout');
        $this->post('/login', ['email' => $this->client->email, 'password' => 'New-pass-123']);
        $this->assertAuthenticatedAs($this->client);
    }

    public function test_orders_of_whole_organization_with_author_and_foreign_404(): void
    {
        $mine = Order::create(['number' => 'SM-2026-000001', 'user_id' => $this->client->id, 'organization_id' => $this->organization->id, 'status' => 'new', 'total' => 1, 'placed_at' => now()]);
        $colleagues = Order::create(['number' => 'SM-2026-000002', 'user_id' => $this->colleague->id, 'organization_id' => $this->organization->id, 'status' => 'new', 'total' => 1, 'placed_at' => now()]);
        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $foreign = Order::create(['number' => 'SM-2026-000003', 'user_id' => $stranger->id, 'organization_id' => $stranger->organization_id, 'status' => 'new', 'total' => 1, 'placed_at' => now()]);

        $this->actingAs($this->client)->get('/orders')
            ->assertOk()
            ->assertSee($mine->number)
            ->assertSee($colleagues->number)
            ->assertSeeInOrder(['data-ordered-by', 'Пётр Коллегин'], false)
            ->assertDontSee($foreign->number);

        foreach (["/order/{$foreign->id}", "/order/{$foreign->id}/placed", "/order/{$foreign->id}/invoice"] as $url) {
            $this->actingAs($this->client)->get($url)->assertNotFound();
        }

        $this->actingAs($this->client)->post("/order/{$foreign->id}/reorder")->assertNotFound();
    }
}
