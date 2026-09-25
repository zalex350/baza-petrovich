<?php

namespace Tests\Feature\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureUserHasRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'role:admin'])->get('/_test/admin-only', fn () => 'admin ok');
        Route::middleware(['web', 'role:client'])->get('/_test/client-only', fn () => 'client ok');
        Route::middleware(['web', 'role:admin,client'])->get('/_test/any-role', fn () => 'any ok');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/_test/admin-only')->assertRedirect(route('login'));
        $this->get('/_test/any-role')->assertRedirect(route('login'));
    }

    public function test_client_gets_403_on_admin_route(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/_test/admin-only')->assertForbidden();
    }

    public function test_admin_gets_200_on_admin_route(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/_test/admin-only')
            ->assertOk()
            ->assertSee('admin ok');
    }

    public function test_admin_gets_403_on_client_route(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/_test/client-only')->assertForbidden();
    }

    public function test_multi_role_allows_admin_and_client(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($admin)->get('/_test/any-role')->assertOk()->assertSee('any ok');
        $this->actingAs($client)->get('/_test/any-role')->assertOk()->assertSee('any ok');
    }

    public function test_real_admin_routes_use_role_middleware(): void
    {
        $client = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->get('/admin/products')->assertRedirect(route('login'));
        $this->actingAs($client)->get('/admin/products')->assertForbidden();
        $this->actingAs($admin)->get('/admin/products')->assertOk();
    }

    public function test_user_role_helpers(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isClient());
        $this->assertTrue($client->isClient());
        $this->assertFalse($client->isAdmin());
        $this->assertSame(UserRole::Client, $client->fresh()->role);
        $this->assertSame('Администратор', $admin->role->label());
        $this->assertSame('Клиент', $client->role->label());
    }
}
