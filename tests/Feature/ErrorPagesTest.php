<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Страницы ошибок в стиле витрины (errors/*.blade.php) и /account → кабинет.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Тестовые маршруты: ошибки отдаются через обработчик исключений, как на сайте
        Route::middleware('web')->get('/_test/abort/{code}', fn (int $code) => abort($code));
        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403, 'Только для клиентов'));
        Route::middleware('web')->get('/_test/crash', fn () => throw new \RuntimeException('boom'));
    }

    public function test_404_in_storefront_layout_with_links(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('data-error-page="404"', false)
            ->assertSee('Страница не найдена')
            ->assertSee('href="/"', false)
            ->assertSee('href="/catalog"', false)
            ->assertSee('data-empty-state', false);
    }

    public function test_403_shows_russian_message_only(): void
    {
        $this->get('/_test/forbidden')->assertForbidden()->assertSee('Только для клиентов')->assertSee('data-error-page="403"', false);

        $this->get('/_test/abort/403')
            ->assertForbidden()
            ->assertSee('У вашей учётной записи нет доступа к этой странице.');
    }

    public function test_419_offers_going_back(): void
    {
        $this->get('/_test/abort/419')
            ->assertStatus(419)
            ->assertSee('Страница устарела')
            ->assertSee('Вернуться назад');
    }

    public function test_500_page_without_header(): void
    {
        config(['app.debug' => false]);

        $this->get('/_test/crash')
            ->assertStatus(500)
            ->assertSee('data-error-page="500"', false)
            ->assertSee('Ошибка на сервере')
            ->assertSee('href="/catalog"', false)
            ->assertDontSee('boom');
    }

    public function test_account_redirects_to_cabinet(): void
    {
        $this->get('/account')->assertRedirect('/login');

        $client = User::factory()->create(['role' => 'client']);
        $this->actingAs($client)->get('/account')->assertRedirect('/orders');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/account')->assertRedirect('/admin');
    }
}
