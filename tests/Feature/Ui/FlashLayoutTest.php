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

    public function test_validation_errors_listed_once_on_screens_without_kit_forms(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['checkout' => ['Неверный статус заказа']]));

        // Формы этих экранов ещё не на x-input/x-select — во flash полный список
        foreach ([['client', '/orders'], ['admin', '/admin/users']] as [$role, $url]) {
            $this->flushSession();
            $html = $this->as($role)->withSession(['errors' => $errors])->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'Неверный статус заказа'), $url);
            $this->assertStringNotContainsString('Проверьте выделенные поля', $html, $url);
        }
    }

    public function test_kit_form_shows_summary_and_error_at_field(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['status' => ['Неверный статус заказа']]));

        $html = $this->as('admin')->withSession(['errors' => $errors])->get('/admin/orders')->assertOk()->getContent();

        // Сводка — одна, сам текст ошибки — только у поля «Статус»
        $this->assertSame(1, substr_count($html, 'Проверьте выделенные поля'));
        $this->assertSame(1, substr_count($html, 'Неверный статус заказа'));
        $this->assertMatchesRegularExpression('#id="status-error"[^>]*data-field-error>\s*Неверный статус заказа#', $html);
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
