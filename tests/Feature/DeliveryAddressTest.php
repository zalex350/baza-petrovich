<?php

namespace Tests\Feature;

use App\Models\DeliveryAddress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кабинет клиента: адреса доставки своей организации (UC-032).
 */
class DeliveryAddressTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $client;

    private array $payload = [
        'title'         => 'Склад на Промышленной',
        'address'       => 'г. Минск, ул. Промышленная, 10',
        'contact_name'  => 'Иван Кладовщик',
        'contact_phone' => '+375 29 111-22-33',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'ООО Сантехторг']);
        $this->client = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function address(?Organization $organization = null, array $attributes = []): DeliveryAddress
    {
        return DeliveryAddress::createFor($organization ?? $this->organization, array_merge([
            'title'   => 'Адрес ' . (DeliveryAddress::count() + 1),
            'address' => 'г. Минск, ул. Тестовая, ' . (DeliveryAddress::count() + 1),
        ], $attributes));
    }

    // --- Доступ ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/addresses')->assertRedirect(route('login'));
        $this->post('/addresses', $this->payload)->assertRedirect(route('login'));

        $this->assertSame(0, DeliveryAddress::count());
    }

    public function test_admin_has_no_cabinet_addresses(): void
    {
        $admin = User::factory()->admin()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($admin)->get('/addresses')->assertForbidden();
        $this->actingAs($admin)->post('/addresses', $this->payload)->assertForbidden();

        $this->assertSame(0, DeliveryAddress::count());
    }

    public function test_client_without_organization_sees_notice_and_cannot_add(): void
    {
        $loner = User::factory()->create(['organization_id' => null]);

        $this->actingAs($loner)->get('/addresses')
            ->assertOk()
            ->assertSee('Пользователь не привязан к организации')
            ->assertDontSee('Добавить адрес');

        $this->actingAs($loner)->get('/addresses/create')->assertForbidden();
        $this->actingAs($loner)->post('/addresses', $this->payload)->assertForbidden();

        $this->assertSame(0, DeliveryAddress::count());
    }

    public function test_cabinet_menu_has_addresses_link_for_client_only(): void
    {
        $this->actingAs($this->client)->get('/')
            ->assertSee('href="/addresses"', false)
            ->assertSeeInOrder(['Мои заказы', 'Адреса доставки', 'Профиль']);

        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertDontSee('href="/addresses"', false);
    }

    // --- Просмотр ---

    public function test_client_sees_addresses_of_own_organization_only(): void
    {
        $own = $this->address(null, ['title' => 'Наш склад', 'address' => 'г. Минск, ул. Наша, 1']);
        $foreign = $this->address(Organization::factory()->create(), ['title' => 'Чужой склад', 'address' => 'г. Брест, ул. Чужая, 2']);

        $this->actingAs($this->client)->get('/addresses')
            ->assertOk()
            ->assertSee('ООО Сантехторг')
            ->assertSee('Наш склад')
            ->assertSee('г. Минск, ул. Наша, 1')
            ->assertSee('По умолчанию')
            ->assertDontSee('Чужой склад')
            ->assertDontSee('г. Брест, ул. Чужая, 2');
    }

    public function test_colleague_sees_same_addresses(): void
    {
        $this->address(null, ['title' => 'Наш склад']);

        $colleague = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($colleague)->get('/addresses')->assertOk()->assertSee('Наш склад');
    }

    public function test_empty_list_shows_hint(): void
    {
        $this->actingAs($this->client)->get('/addresses')
            ->assertOk()
            ->assertSee('Адресов пока нет')
            ->assertSee('Добавить адрес');
    }

    // --- Добавление ---

    public function test_client_adds_address_to_own_organization(): void
    {
        $this->actingAs($this->client)->get('/addresses/create')->assertOk()->assertSee('Новый адрес доставки');

        // Подмена organization_id в форме игнорируется
        $other = Organization::factory()->create();

        $this->actingAs($this->client)
            ->post('/addresses', $this->payload + ['organization_id' => $other->id])
            ->assertRedirect('/addresses')
            ->assertSessionHas('success');

        $address = DeliveryAddress::sole();

        $this->assertSame($this->organization->id, $address->organization_id);
        $this->assertSame('Склад на Промышленной', $address->title);
        $this->assertSame('г. Минск, ул. Промышленная, 10', $address->address);
        $this->assertSame('Иван Кладовщик', $address->contact_name);
        $this->assertSame('+375 29 111-22-33', $address->contact_phone);
    }

    public function test_first_address_becomes_default_and_next_does_not(): void
    {
        $this->actingAs($this->client)->post('/addresses', $this->payload);
        $this->actingAs($this->client)->post('/addresses', ['title' => 'Второй', 'address' => 'г. Гомель']);

        [$first, $second] = DeliveryAddress::orderBy('id')->get()->all();

        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);
    }

    public function test_new_address_can_be_added_as_default(): void
    {
        $first = $this->address();

        $this->actingAs($this->client)->post('/addresses', $this->payload + ['is_default' => 1]);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue(DeliveryAddress::where('title', 'Склад на Промышленной')->sole()->is_default);
        $this->assertSame(1, DeliveryAddress::where('is_default', true)->count());
    }

    public function test_title_and_address_are_required(): void
    {
        $this->actingAs($this->client)
            ->from('/addresses/create')
            ->post('/addresses', ['title' => '', 'address' => ''])
            ->assertRedirect('/addresses/create')
            ->assertSessionHasErrors([
                'title'   => 'Укажите название адреса',
                'address' => 'Укажите адрес',
            ]);

        $this->actingAs($this->client)
            ->post('/addresses', ['title' => 'Склад', 'address' => str_repeat('а', 501)])
            ->assertSessionHasErrors('address');

        $this->assertSame(0, DeliveryAddress::count());
    }

    // --- Редактирование ---

    public function test_client_edits_own_address(): void
    {
        $address = $this->address();

        $this->actingAs($this->client)->get("/addresses/{$address->id}/edit")
            ->assertOk()
            ->assertSee('Редактирование адреса')
            ->assertSee($address->title);

        $this->actingAs($this->client)
            ->post("/addresses/{$address->id}", $this->payload)
            ->assertRedirect('/addresses')
            ->assertSessionHas('success');

        $address->refresh();

        $this->assertSame('Склад на Промышленной', $address->title);
        $this->assertSame('Иван Кладовщик', $address->contact_name);
        // Адрес по умолчанию остаётся им: снять галочкой нельзя
        $this->assertTrue($address->is_default);
    }

    // --- По умолчанию ---

    public function test_client_chooses_default_address(): void
    {
        $first = $this->address();
        $second = $this->address();

        $this->actingAs($this->client)
            ->post("/addresses/{$second->id}/default")
            ->assertRedirect('/addresses')
            ->assertSessionHas('success');

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_default_of_one_organization_does_not_affect_another(): void
    {
        $foreign = $this->address(Organization::factory()->create());
        $first = $this->address();
        $second = $this->address();

        $this->actingAs($this->client)->post("/addresses/{$second->id}/default");

        $this->assertTrue($foreign->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    // --- Удаление ---

    public function test_client_deletes_address_and_default_moves_to_remaining(): void
    {
        $first = $this->address();
        $second = $this->address();
        $third = $this->address();

        $this->actingAs($this->client)
            ->post("/addresses/{$first->id}/delete")
            ->assertRedirect('/addresses')
            ->assertSessionHas('success');

        $this->assertModelMissing($first);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($third->fresh()->is_default);

        // Удаление не-умолчательного адреса умолчание не трогает
        $this->actingAs($this->client)->post("/addresses/{$third->id}/delete");

        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, DeliveryAddress::count());
    }

    public function test_delete_asks_for_confirmation(): void
    {
        $this->address(null, ['title' => 'Наш склад']);

        $this->actingAs($this->client)->get('/addresses')
            ->assertSee("confirm('Удалить адрес «Наш склад»?')", false);
    }

    // --- Чужая организация ---

    public function test_client_cannot_see_or_change_foreign_address(): void
    {
        $foreign = $this->address(Organization::factory()->create(), ['title' => 'Чужой склад']);
        $own = $this->address();

        $this->actingAs($this->client)->get("/addresses/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($this->client)->post("/addresses/{$foreign->id}", $this->payload)->assertNotFound();
        $this->actingAs($this->client)->post("/addresses/{$foreign->id}/default")->assertNotFound();
        $this->actingAs($this->client)->post("/addresses/{$foreign->id}/delete")->assertNotFound();

        $foreign->refresh();

        $this->assertSame('Чужой склад', $foreign->title);
        $this->assertTrue($foreign->is_default);
        $this->assertTrue($own->fresh()->is_default);
        $this->assertSame(2, DeliveryAddress::count());
    }
}
