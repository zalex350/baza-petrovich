<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'ООО «Сантехторг»',
            'legal_name' => 'Общество с ограниченной ответственностью «Сантехторг»',
            'unp'        => '190123456',
            'address'    => 'г. Минск, ул. Примерная, 1',
            'phone'      => '+375 29 000-00-00',
            'email'      => 'info@santeh.by',
            'comment'    => 'Постоянный клиент',
        ], $overrides);
    }

    // --- Доступ ---

    public static function adminUrlsProvider(): array
    {
        return [
            'список'       => ['get', '/admin/organizations'],
            'форма'        => ['get', '/admin/organization/create'],
            'создание'     => ['post', '/admin/organization'],
            'карточка'     => ['get', '/admin/organization/{id}'],
            'редактирование' => ['get', '/admin/organization/{id}/edit'],
            'сохранение'   => ['post', '/admin/organization/{id}'],
            'удаление'     => ['post', '/admin/organization/{id}/delete'],
        ];
    }

    #[DataProvider('adminUrlsProvider')]
    public function test_guest_is_redirected_to_login(string $method, string $url): void
    {
        $organization = Organization::factory()->create();

        $this->{$method}(str_replace('{id}', $organization->id, $url))
            ->assertRedirect(route('login'));

        $this->assertModelExists($organization);
    }

    #[DataProvider('adminUrlsProvider')]
    public function test_client_gets_403(string $method, string $url): void
    {
        $organization = Organization::factory()->create();
        $client = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($client)
            ->{$method}(str_replace('{id}', $organization->id, $url))
            ->assertForbidden();

        $this->assertModelExists($organization);
    }

    public function test_admin_gets_200_on_pages(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin)->get('/admin/organizations')->assertOk();
        $this->actingAs($this->admin)->get('/admin/organization/create')->assertOk();
        $this->actingAs($this->admin)->get("/admin/organization/{$organization->id}")->assertOk();
        $this->actingAs($this->admin)->get("/admin/organization/{$organization->id}/edit")->assertOk();
    }

    public function test_admin_menu_has_organizations_link(): void
    {
        $this->actingAs($this->admin)->get('/admin/products')
            ->assertSee('/admin/organizations', false)
            ->assertSee('Клиенты');
    }

    public function test_client_menu_has_no_organizations_link(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/')
            ->assertDontSee('/admin/organizations', false);
    }

    public function test_interface_uses_clients_wording(): void
    {
        $organization = Organization::factory()->create();

        $pages = [
            '/admin/organizations'                          => ['Админка — Клиенты', 'Добавить клиента'],
            '/admin/organization/create'                    => ['Новый клиент', 'Назад к списку клиентов', 'Создать клиента'],
            "/admin/organization/{$organization->id}"      => ['Клиент — ', 'Назад к списку клиентов', 'Удалить клиента'],
            "/admin/organization/{$organization->id}/edit" => ['Редактирование клиента', 'Назад к карточке клиента'],
        ];

        foreach ($pages as $url => $texts) {
            $response = $this->actingAs($this->admin)->get($url)->assertOk();

            foreach ($texts as $text) {
                $response->assertSee($text);
            }

            $this->assertDoesNotMatchRegularExpression('/[Оо]рганизац/u', $response->getContent(), $url);
        }
    }

    public function test_flash_messages_use_clients_wording(): void
    {
        $this->actingAs($this->admin)->post('/admin/organization', ['name' => 'Новый'])
            ->assertSessionHas('success', 'Клиент создан');

        $organization = Organization::first();

        $this->actingAs($this->admin)->post("/admin/organization/{$organization->id}", ['name' => 'Изменён'])
            ->assertSessionHas('success', 'Клиент сохранён');

        User::factory()->create(['organization_id' => $organization->id]);
        $this->actingAs($this->admin)->post("/admin/organization/{$organization->id}/delete")
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Нельзя удалить клиента «Изменён»'));

        User::where('organization_id', $organization->id)->update(['organization_id' => null]);
        $this->actingAs($this->admin)->post("/admin/organization/{$organization->id}/delete")
            ->assertSessionHas('success', 'Клиент удалён');
    }

    // --- Список ---

    public function test_list_shows_organizations_with_users_count(): void
    {
        $organization = Organization::factory()->create(['name' => 'Альфа']);
        User::factory()->count(3)->create(['organization_id' => $organization->id]);
        Organization::factory()->create(['name' => 'Бета']);

        $this->actingAs($this->admin)->get('/admin/organizations')
            ->assertOk()
            ->assertSee('Пользователей')
            ->assertSeeInOrder(['Альфа', 'Бета'])
            ->assertViewHas('organizations', fn ($p) => $p->pluck('users_count', 'name')->all() === ['Альфа' => 3, 'Бета' => 0]);
    }

    public function test_list_is_paginated(): void
    {
        Organization::factory()->count(51)->create();

        $this->actingAs($this->admin)->get('/admin/organizations')
            ->assertOk()
            ->assertViewHas('organizations', fn ($p) => $p->count() === 50 && $p->total() === 51);

        $this->actingAs($this->admin)->get('/admin/organizations?page=2')
            ->assertViewHas('organizations', fn ($p) => $p->count() === 1);
    }

    // --- Поиск (§10.6.6) ---

    public static function searchProvider(): array
    {
        return [
            'по наименованию' => ['Сантех'],
            'по УНП'          => ['1901234'],
            'по email'        => ['santeh.by'],
        ];
    }

    #[DataProvider('searchProvider')]
    public function test_search(string $query): void
    {
        Organization::factory()->create($this->validPayload());
        Organization::factory()->create([
            'name'  => 'ИП Другой',
            'unp'   => '290000000',
            'email' => 'other@mail.by',
        ]);

        $this->actingAs($this->admin)->get('/admin/organizations?q='.urlencode($query))
            ->assertOk()
            ->assertSee('ООО «Сантехторг»', false)
            ->assertDontSee('ИП Другой');
    }

    public function test_search_keeps_query_in_pagination_links(): void
    {
        Organization::factory()->count(55)->sequence(fn ($s) => ['name' => 'Поиск '.$s->index])->create();
        Organization::factory()->count(3)->create();

        $this->actingAs($this->admin)->get('/admin/organizations?q=Поиск')
            ->assertViewHas('organizations', fn ($p) => $p->total() === 55)
            ->assertSee('q='.urlencode('Поиск').'&amp;page=2', false);
    }

    public function test_search_without_results_shows_empty_state(): void
    {
        Organization::factory()->create();

        $this->actingAs($this->admin)->get('/admin/organizations?q=нет-такой')
            ->assertOk()
            ->assertSee('клиентов не найдено');
    }

    // --- Создание / редактирование ---

    public function test_admin_can_create_organization(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/organization', $this->validPayload());

        $organization = Organization::where('unp', '190123456')->first();

        $this->assertNotNull($organization);
        $response->assertRedirect("/admin/organization/{$organization->id}");
        $this->assertSame('ООО «Сантехторг»', $organization->name);
        $this->assertSame('info@santeh.by', $organization->email);
    }

    public function test_create_with_name_only(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', ['name' => 'Только имя'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organizations', ['name' => 'Только имя', 'unp' => null]);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Organization::count());
    }

    public function test_email_must_be_valid(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_guid_cannot_be_set_from_admin(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['guid' => 'hacked-guid']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Organization::first()->guid);
    }

    public function test_admin_can_update_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}", $this->validPayload(['name' => 'Новое имя']))
            ->assertRedirect("/admin/organization/{$organization->id}");

        $organization->refresh();
        $this->assertSame('Новое имя', $organization->name);
        $this->assertSame('190123456', $organization->unp);
    }

    public function test_update_does_not_touch_guid(): void
    {
        $organization = Organization::factory()->create();
        $organization->forceFill(['guid' => 'guid-from-1c'])->save();

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}", $this->validPayload(['guid' => 'changed']))
            ->assertSessionHasNoErrors();

        $this->assertSame('guid-from-1c', $organization->fresh()->guid);
    }

    // --- УНП ---

    public static function invalidUnpProvider(): array
    {
        return [
            '8 цифр'       => ['12345678'],
            '10 цифр'      => ['1234567890'],
            'буквы'        => ['12345678A'],
            'с пробелом'   => ['123 45678'],
            'с дефисом'    => ['1234-5678'],
        ];
    }

    #[DataProvider('invalidUnpProvider')]
    public function test_unp_format_is_validated(string $unp): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['unp' => $unp]))
            ->assertSessionHasErrors(['unp' => 'УНП должен состоять ровно из 9 цифр.']);

        $this->assertSame(0, Organization::count());
    }

    public function test_unp_must_be_unique_on_create(): void
    {
        Organization::factory()->create(['unp' => '190123456']);

        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['unp' => '190123456']))
            ->assertSessionHasErrors(['unp' => 'Клиент с таким УНП уже существует.']);

        $this->assertSame(1, Organization::count());
    }

    public function test_unp_must_be_unique_on_update(): void
    {
        Organization::factory()->create(['unp' => '190123456']);
        $other = Organization::factory()->create(['unp' => '290000000']);

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$other->id}", $this->validPayload(['unp' => '190123456']))
            ->assertSessionHasErrors('unp');

        $this->assertSame('290000000', $other->fresh()->unp);
    }

    public function test_update_keeps_own_unp(): void
    {
        $organization = Organization::factory()->create(['unp' => '190123456']);

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}", $this->validPayload(['unp' => '190123456']))
            ->assertSessionHasNoErrors();
    }

    public function test_several_organizations_without_unp_are_allowed(): void
    {
        $this->actingAs($this->admin)->post('/admin/organization', ['name' => 'Первая', 'unp' => ''])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/organization', ['name' => 'Вторая', 'unp' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Organization::whereNull('unp')->count());
    }

    // --- Карточка ---

    public function test_card_shows_details_guid_and_users(): void
    {
        $organization = Organization::factory()->create($this->validPayload());
        $organization->forceFill(['guid' => 'a1b2c3d4-guid'])->save();

        $client = User::factory()->create(['name' => 'Иван Клиент', 'email' => 'ivan@santeh.by', 'organization_id' => $organization->id]);
        User::factory()->admin()->create(['name' => 'Анна Админ', 'email' => 'anna@santeh.by', 'organization_id' => $organization->id]);

        $this->actingAs($this->admin)->get("/admin/organization/{$organization->id}")
            ->assertOk()
            ->assertSee('190123456')
            ->assertSee('г. Минск, ул. Примерная, 1')
            ->assertSee('a1b2c3d4-guid')
            ->assertSee('только чтение')
            ->assertSee('Иван Клиент')
            ->assertSee('ivan@santeh.by')
            ->assertSee('Клиент')
            ->assertSee('Анна Админ')
            ->assertSee('Администратор');
    }

    public function test_edit_form_shows_guid_read_only(): void
    {
        $organization = Organization::factory()->create();
        $organization->forceFill(['guid' => 'guid-ro'])->save();

        $this->actingAs($this->admin)->get("/admin/organization/{$organization->id}/edit")
            ->assertSee('guid-ro')
            ->assertDontSee('name="guid"', false);
    }

    public function test_missing_organization_returns_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/organization/999999')->assertNotFound();
    }

    // --- Удаление ---

    public function test_admin_can_delete_organization_without_users_and_orders(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}/delete")
            ->assertRedirect('/admin/organizations')
            ->assertSessionHas('success');

        $this->assertModelMissing($organization);
    }

    public function test_cannot_delete_organization_with_users(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->admin)
            ->from("/admin/organization/{$organization->id}")
            ->post("/admin/organization/{$organization->id}/delete")
            ->assertRedirect("/admin/organization/{$organization->id}")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Нельзя удалить') && str_contains($m, 'пользователей — 1'));

        $this->assertModelExists($organization);
    }

    public function test_cannot_delete_organization_with_orders(): void
    {
        $organization = Organization::factory()->create();
        $buyer = User::factory()->create();
        $order = Order::create(['user_id' => $buyer->id, 'status' => OrderStatus::New, 'total' => 100]);
        $order->forceFill(['organization_id' => $organization->id])->save();

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}/delete")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'заказов — 1'));

        $this->assertModelExists($organization);
    }
}
