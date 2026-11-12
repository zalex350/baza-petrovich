<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Rules\Unp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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
            'name' => 'ООО «Сантехторг»',
            'legal_name' => 'Общество с ограниченной ответственностью «Сантехторг»',
            'unp' => '191234563',
            'address' => 'г. Минск, ул. Примерная, 1',
            'phone' => '+375 29 000-00-00',
            'email' => 'info@santeh.by',
            'comment' => 'Постоянный клиент',
        ], $overrides);
    }

    // --- Доступ ---

    public static function adminUrlsProvider(): array
    {
        return [
            'список' => ['get', '/admin/organizations'],
            'форма' => ['get', '/admin/organization/create'],
            'создание' => ['post', '/admin/organization'],
            'карточка' => ['get', '/admin/organization/{id}'],
            'редактирование' => ['get', '/admin/organization/{id}/edit'],
            'сохранение' => ['post', '/admin/organization/{id}'],
            'удаление' => ['post', '/admin/organization/{id}/delete'],
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
            '/admin/organizations' => ['Админка — Клиенты', 'Добавить клиента'],
            '/admin/organization/create' => ['Новый клиент', 'Назад к списку клиентов', 'Создать клиента'],
            "/admin/organization/{$organization->id}" => ['Клиент — ', 'Назад к списку клиентов', 'Удалить клиента'],
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
            'по УНП' => ['1912345'],
            'по email' => ['santeh.by'],
        ];
    }

    #[DataProvider('searchProvider')]
    public function test_search(string $query): void
    {
        Organization::factory()->create($this->validPayload());
        Organization::factory()->create([
            'name' => 'ИП Другой',
            'unp' => '290000001',
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

        $organization = Organization::where('unp', '191234563')->first();

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
        $this->assertSame('191234563', $organization->unp);
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
        $format = 'УНП — ровно 9 цифр.';
        $checksum = 'УНП с ошибкой: не сходится контрольная цифра.';

        return [
            '8 цифр' => ['12345678', $format],
            '10 цифр' => ['1234567890', $format],
            'буквы' => ['12345678A', $format],
            '8 цифр с пробелом' => ['123 45678', $format],
            'с дефисом' => ['1234-5678', $format],
            'контрольная цифра' => ['191234564', $checksum],
            'прежний «любой» УНП' => ['190123456', $checksum],
            // сумма с весами даёт остаток 10 — такого УНП не бывает
            'остаток 10' => [self::remainderTen(), $checksum],
        ];
    }

    /** Первые 8 цифр, у которых контрольная цифра не существует, + любая девятая */
    private static function remainderTen(): string
    {
        for ($n = 10000000; Unp::checkDigit((string) $n) !== null; $n++);

        return $n.'0';
    }

    #[DataProvider('invalidUnpProvider')]
    public function test_unp_format_is_validated(string $unp, string $message): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['unp' => $unp]))
            ->assertSessionHasErrors(['unp' => $message]);

        $this->assertSame(0, Organization::count());
    }

    public function test_unp_checksum_examples_and_spaces(): void
    {
        foreach (['100000007', '190000005', '191234563', '100582333'] as $valid) {
            $this->assertTrue(Unp::valid($valid), $valid);
        }

        // Пробелы при вводе убираются
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['unp' => ' 191 234 563 ']))
            ->assertSessionHasNoErrors();
        $this->assertSame('191234563', Organization::sole()->unp);
    }

    public function test_unp_error_is_under_field(): void
    {
        $this->actingAs($this->admin)->from('/admin/organization/create')
            ->post('/admin/organization', $this->validPayload(['unp' => '191234564']))
            ->assertRedirect('/admin/organization/create');

        $this->actingAs($this->admin)
            ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['unp' => 'УНП с ошибкой: не сходится контрольная цифра.']))])
            ->get('/admin/organization/create')
            ->assertSee('id="f-unp-error"', false)
            ->assertSee('УНП с ошибкой: не сходится контрольная цифра.')
            ->assertSee('Проверьте выделенные поля');
    }

    // --- Банковские реквизиты ---

    public function test_bank_details_are_optional_normalized_and_shown(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload([
                'bank_name' => 'ЗАО «Альфа-Банк»', 'bic' => 'alfaby2x',
                'iban' => 'by43 alfa 3012 2c7e 2a00 1027 0000', 'bank_address' => 'г. Минск, ул. Сурганова, 43-47',
            ]))
            ->assertSessionHasNoErrors();

        $organization = Organization::sole();
        $this->assertSame(['ALFABY2X', 'BY43ALFA30122C7E2A0010270000'], [$organization->bic, $organization->iban]);

        // Карточка клиента в админке — IBAN группами по 4
        $this->actingAs($this->admin)->get("/admin/organization/{$organization->id}")->assertOk()
            ->assertSee('ЗАО «Альфа-Банк»')
            ->assertSee('ALFABY2X')
            ->assertSee('BY43 ALFA 3012 2C7E 2A00 1027 0000')
            ->assertSee('г. Минск, ул. Сурганова, 43-47');

        // Без реквизитов — тоже можно
        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}", $this->validPayload(['bank_name' => '', 'bic' => '', 'iban' => '', 'bank_address' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull($organization->fresh()->iban);
    }

    public static function invalidBankProvider(): array
    {
        return [
            'BIC 9 символов' => ['bic', 'ALFABY2XA', 'BIC — 8 или 11 латинских букв и цифр, например ALFABY2X.'],
            'BIC кириллица' => ['bic', 'АЛФАBY2X', 'BIC — 8 или 11 латинских букв и цифр, например ALFABY2X.'],
            'IBAN короткий' => ['iban', 'BY43ALFA30122C7E2A001027000', 'IBAN — 28 символов: BY, 2 цифры, код банка (4 символа), 4 цифры, 16 символов счёта.'],
            'IBAN не BY' => ['iban', 'DE43ALFA30122C7E2A0010270000', 'IBAN — 28 символов: BY, 2 цифры, код банка (4 символа), 4 цифры, 16 символов счёта.'],
            'IBAN контрольная сумма' => ['iban', 'BY44ALFA30122C7E2A0010270000', 'IBAN с ошибкой: не сходятся контрольные цифры.'],
        ];
    }

    #[DataProvider('invalidBankProvider')]
    public function test_bank_details_are_validated(string $field, string $value, string $message): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload([$field => $value]))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(0, Organization::count());
    }

    public function test_client_sees_bank_details_read_only(): void
    {
        $organization = Organization::factory()->create([
            'bank_name' => 'ОАО «Банк»', 'bic' => 'NBRBBY2X', 'iban' => 'BY58NBRB36000000000000000000', 'bank_address' => 'Минск',
        ]);
        $client = User::factory()->create(['organization_id' => $organization->id]);

        $html = $this->actingAs($client)->get('/account/organization')->assertOk()
            ->assertSee('Реквизиты')
            ->assertSee('ОАО «Банк»')
            ->assertSee('NBRBBY2X')
            ->assertSee('BY58 NBRB 3600 0000 0000 0000 0000')
            ->getContent();
        // Только чтение: формы нет
        $this->assertStringNotContainsString('name="iban"', $html);
    }

    public function test_unp_must_be_unique_on_create(): void
    {
        Organization::factory()->create(['unp' => '191234563']);

        $this->actingAs($this->admin)
            ->post('/admin/organization', $this->validPayload(['unp' => '191234563']))
            ->assertSessionHasErrors(['unp' => 'Клиент с таким УНП уже существует.']);

        $this->assertSame(1, Organization::count());
    }

    public function test_unp_must_be_unique_on_update(): void
    {
        Organization::factory()->create(['unp' => '191234563']);
        $other = Organization::factory()->create(['unp' => '290000001']);

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$other->id}", $this->validPayload(['unp' => '191234563']))
            ->assertSessionHasErrors('unp');

        $this->assertSame('290000001', $other->fresh()->unp);
    }

    public function test_update_keeps_own_unp(): void
    {
        $organization = Organization::factory()->create(['unp' => '191234563']);

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$organization->id}", $this->validPayload(['unp' => '191234563']))
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
            ->assertSee('191234563')
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
