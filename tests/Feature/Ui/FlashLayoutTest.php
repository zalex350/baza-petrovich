<?php

namespace Tests\Feature\Ui;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * x-alert.flash в layouts/app, layouts/admin, layouts/guest: ровно один блок и одно сообщение.
 */
class FlashLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Проверочное сообщение';

    public static function layouts(): array
    {
        return [
            'app (кабинет)' => ['client', '/orders'],
            'admin' => ['admin', '/admin/orders'],
            'guest (вход)' => [null, '/login'],
        ];
    }

    #[DataProvider('layouts')]
    public function test_flash_is_rendered_once(?string $role, string $url): void
    {
        foreach (['success', 'error', 'warning'] as $type) {
            $this->flushSession();
            $html = $this->as($role)->withSession([$type => self::MESSAGE])->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-flash'), "$url: один блок flash");
            $this->assertSame(1, substr_count($html, self::MESSAGE), "$url: сообщение $type — один раз");
            $this->assertSame(1, substr_count($html, 'data-alert="'.($type === 'error' ? 'error' : $type).'"'), "$url: алерт $type");
        }
    }

    #[DataProvider('layouts')]
    public function test_empty_flash_block_has_no_alerts(?string $role, string $url): void
    {
        $html = $this->as($role)->get($url)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-flash'));
        $this->assertStringNotContainsString('data-alert=', $html);
        // Пустой блок не добавляет отступ
        $this->assertDoesNotMatchRegularExpression('/data-flash class="[^"]*mb-4/', $html);
    }

    public function test_validation_errors_listed_once_in_app_and_admin(): void
    {
        // Ключ без поля на странице: проверяется сам список во flash (ошибку поля x-select/x-input
        // дополнительно показывает под полем — это не дубль flash)
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['checkout' => ['Неверный статус заказа']]));

        foreach ([['client', '/orders'], ['admin', '/admin/orders']] as [$role, $url]) {
            $this->flushSession();
            $html = $this->as($role)->withSession(['errors' => $errors])->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'Неверный статус заказа'), $url);
        }
    }

    public function test_guest_layout_leaves_validation_errors_to_fields(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $html = $this->get('/login')->getContent();

        // Ошибка — только у поля (x-input-error), без дубля в блоке flash
        $this->assertSame(1, substr_count($html, __('auth.failed')));
        $this->assertStringNotContainsString('data-alert=', $html);
    }

    private function as(?string $role): static
    {
        return match ($role) {
            'admin' => $this->actingAs(User::factory()->admin()->create()),
            'client' => $this->actingAs(User::factory()->create(['organization_id' => Organization::factory()->create()->id])),
            null => $this,
        };
    }
}
