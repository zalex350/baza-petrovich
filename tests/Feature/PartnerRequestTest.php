<?php

namespace Tests\Feature;

use App\Enums\PartnerRequestStatus;
use App\Models\PartnerRequest;
use App\Models\User;
use App\Notifications\PartnerRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Стать партнёром»: форма, проверка полей, защита от спама (ловушка + частота),
 * письмо администраторам по флагу, список «Заявки» и статусы в админке.
 */
class PartnerRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'organization' => 'ООО «Сантех-Партнёр»',
            'unp' => '190 123 456',
            'contact_name' => 'Петров Пётр',
            'phone' => '+375 (29) 123-45-67',
            'email' => 'Petrov@Partner.by',
            'comment' => 'Хотим закупать смесители',
            'consent' => '1',
        ], $overrides);
    }

    public function test_form_is_shown(): void
    {
        $this->get('/partner')
            ->assertOk()
            ->assertSee('data-partner-form', false)
            ->assertSee('name="website"', false)
            ->assertSee('Согласен на обработку персональных данных');
    }

    public function test_request_is_saved_and_admins_with_flag_are_notified(): void
    {
        $on = User::factory()->admin()->create();
        $off = User::factory()->admin()->create(['notify_partner_request' => false]);
        $blocked = User::factory()->admin()->create(['blocked_at' => now()]);

        $this->post('/partner', $this->payload())
            ->assertRedirect('/partner')
            ->assertSessionHas('partner_sent');

        $request = PartnerRequest::sole();
        $this->assertSame('190123456', $request->unp);
        $this->assertSame('petrov@partner.by', $request->email);
        $this->assertSame(PartnerRequestStatus::New, $request->status);
        $this->assertNotNull($request->consent_at);

        Notification::assertSentTo($on, PartnerRequestNotification::class, function (PartnerRequestNotification $n) use ($on) {
            $mail = $n->toMail($on);
            $this->assertStringContainsString('ООО «Сантех-Партнёр»', $mail->subject);
            $this->assertStringContainsString('**УНП:** 190123456', implode("\n", $mail->introLines));

            return true;
        });
        Notification::assertNotSentTo([$off, $blocked], PartnerRequestNotification::class);

        $this->get('/partner')->assertSee('Заявка отправлена');
    }

    public function test_validation_errors_under_fields(): void
    {
        $this->from('/partner')->post('/partner', $this->payload(['unp' => '123', 'email' => 'нет', 'consent' => null, 'organization' => '']))
            ->assertRedirect('/partner')
            ->assertSessionHasErrors(['unp' => 'УНП — 9 цифр', 'email', 'consent', 'organization']);

        $this->assertSame(0, PartnerRequest::count());
    }

    public function test_unp_check_digit_does_not_block_request(): void
    {
        // Форма вернулась с ошибкой другого поля — у УНП жёлтая подсказка
        $this->withSession(['_old_input' => ['unp' => '190123456']])->get('/partner')
            ->assertSee('Контрольная цифра не сходится — проверьте УНП');
        $this->withSession(['_old_input' => ['unp' => '191234563']])->get('/partner')
            ->assertDontSee('Контрольная цифра не сходится');

        // 190123456 — контрольная цифра не сходится, заявка всё равно принимается
        $this->post('/partner', $this->payload(['unp' => '190 123 456']))->assertSessionHasNoErrors();
        $this->assertSame('190123456', PartnerRequest::sole()->unp);
    }

    public function test_honeypot_silently_drops_request(): void
    {
        User::factory()->admin()->create();

        $this->post('/partner', $this->payload(['website' => 'http://spam.example']))
            ->assertRedirect('/partner')
            ->assertSessionHas('partner_sent');

        $this->assertSame(0, PartnerRequest::count());
        Notification::assertNothingSent();
    }

    public function test_throttle_limits_submissions(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/partner', $this->payload())->assertRedirect('/partner');
        }

        $this->post('/partner', $this->payload())->assertStatus(429);
        $this->assertSame(5, PartnerRequest::count());
    }

    public function test_admin_lists_filters_and_changes_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->post('/partner', $this->payload());
        $request = PartnerRequest::sole();

        $this->actingAs($admin)->get('/admin/partners')
            ->assertOk()
            ->assertSee('ООО «Сантех-Партнёр»')
            ->assertSee('УНП 190123456')
            ->assertSee('Новая');

        $this->actingAs($admin)->from('/admin/partners')
            ->post("/admin/partner/{$request->id}/status", ['status' => 'connected'])
            ->assertRedirect('/admin/partners')
            ->assertSessionHas('success');

        $this->assertSame(PartnerRequestStatus::Connected, $request->fresh()->status);

        $this->actingAs($admin)->get('/admin/partners?status=new')->assertSee('Заявок нет');
        $this->actingAs($admin)->get('/admin/partners?status=connected&q=190123')->assertSee('Петров Пётр');

        $this->actingAs($admin)->post("/admin/partner/{$request->id}/status", ['status' => 'bad'])
            ->assertSessionHasErrors('status');
    }

    public function test_client_cannot_see_requests(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/partners')->assertForbidden();
    }
}
