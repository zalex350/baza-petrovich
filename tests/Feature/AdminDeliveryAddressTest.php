<?php

namespace Tests\Feature;

use App\Models\DeliveryAddress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Админка: адреса доставки в карточке клиента.
 */
class AdminDeliveryAddressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    private array $payload = [
        'title'         => 'Склад на Промышленной',
        'address'       => 'г. Минск, ул. Промышленная, 10',
        'contact_name'  => 'Иван Кладовщик',
        'contact_phone' => '+375 29 111-22-33',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->organization = Organization::factory()->create();
    }

    private function url(string $suffix = ''): string
    {
        return "/admin/organization/{$this->organization->id}/address{$suffix}";
    }

    private function address(?Organization $organization = null, string $title = 'Основной'): DeliveryAddress
    {
        return DeliveryAddress::createFor($organization ?? $this->organization, [
            'title'   => $title,
            'address' => 'г. Минск, ул. Тестовая, 1',
        ]);
    }

    public function test_client_and_guest_have_no_access(): void
    {
        $address = $this->address();
        $client = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($client)->get($this->url('/create'))->assertForbidden();
        $this->actingAs($client)->post($this->url(), $this->payload)->assertForbidden();
        $this->actingAs($client)->post($this->url("/{$address->id}/delete"))->assertForbidden();

        auth()->logout();

        $this->post($this->url(), $this->payload)->assertRedirect(route('login'));

        $this->assertSame(1, DeliveryAddress::count());
    }

    public function test_organization_card_lists_addresses(): void
    {
        $this->actingAs($this->admin)->get("/admin/organization/{$this->organization->id}")
            ->assertOk()
            ->assertSee('Адреса доставки')
            ->assertSee('У клиента пока нет адресов доставки')
            ->assertSee($this->url('/create'), false);

        $address = $this->address(null, 'Склад клиента');
        $this->address(Organization::factory()->create(), 'Склад другого клиента');

        $this->actingAs($this->admin)->get("/admin/organization/{$this->organization->id}")
            ->assertOk()
            ->assertSee('Склад клиента')
            ->assertSee('По умолчанию')
            ->assertSee($this->url("/{$address->id}/edit"), false)
            ->assertDontSee('Склад другого клиента');
    }

    public function test_admin_adds_address(): void
    {
        $this->actingAs($this->admin)->get($this->url('/create'))
            ->assertOk()
            ->assertSee('Новый адрес доставки')
            ->assertSee($this->organization->name);

        $this->actingAs($this->admin)
            ->post($this->url(), $this->payload)
            ->assertRedirect("/admin/organization/{$this->organization->id}")
            ->assertSessionHas('success');

        $address = DeliveryAddress::sole();

        $this->assertSame($this->organization->id, $address->organization_id);
        $this->assertSame('Склад на Промышленной', $address->title);
        $this->assertTrue($address->is_default);
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->admin)
            ->from($this->url('/create'))
            ->post($this->url(), ['title' => '', 'address' => ''])
            ->assertRedirect($this->url('/create'))
            ->assertSessionHasErrors(['title', 'address']);

        $this->assertSame(0, DeliveryAddress::count());
    }

    public function test_admin_edits_address(): void
    {
        $address = $this->address();

        $this->actingAs($this->admin)->get($this->url("/{$address->id}/edit"))
            ->assertOk()
            ->assertSee('Редактирование адреса');

        $this->actingAs($this->admin)
            ->post($this->url("/{$address->id}"), $this->payload)
            ->assertRedirect("/admin/organization/{$this->organization->id}");

        $this->assertSame('Склад на Промышленной', $address->fresh()->title);
        $this->assertSame('+375 29 111-22-33', $address->fresh()->contact_phone);
    }

    public function test_admin_changes_default_and_deletes(): void
    {
        $first = $this->address(null, 'Первый');
        $second = $this->address(null, 'Второй');

        $this->actingAs($this->admin)
            ->post($this->url("/{$second->id}/default"))
            ->assertRedirect("/admin/organization/{$this->organization->id}");

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->actingAs($this->admin)
            ->post($this->url("/{$second->id}/delete"))
            ->assertRedirect("/admin/organization/{$this->organization->id}");

        $this->assertModelMissing($second);
        $this->assertTrue($first->fresh()->is_default);
    }

    public function test_address_is_addressed_only_through_its_organization(): void
    {
        $foreign = $this->address(Organization::factory()->create(), 'Чужой');

        $this->actingAs($this->admin)->get($this->url("/{$foreign->id}/edit"))->assertNotFound();
        $this->actingAs($this->admin)->post($this->url("/{$foreign->id}"), $this->payload)->assertNotFound();
        $this->actingAs($this->admin)->post($this->url("/{$foreign->id}/delete"))->assertNotFound();

        $this->assertSame('Чужой', $foreign->fresh()->title);
    }

    public function test_addresses_are_removed_with_organization(): void
    {
        $this->address();

        $this->actingAs($this->admin)
            ->post("/admin/organization/{$this->organization->id}/delete")
            ->assertRedirect('/admin/organizations');

        $this->assertSame(0, DeliveryAddress::count());
    }
}
