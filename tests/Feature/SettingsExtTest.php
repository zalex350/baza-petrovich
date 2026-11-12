<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Настройки»: контакты (телефоны с подписями, email, режим работы, склад), полные реквизиты
 * (УНП с контрольной цифрой, IBAN BY mod 97, BIC), вывод на сайте и в письмах, перенос
 * контактов из config миграцией.
 */
class SettingsExtTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'BY13NBRB3600900000002Z00AB00';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function save(array $data)
    {
        return $this->actingAs($this->admin)->from('/admin/settings')->post('/admin/settings', $data);
    }

    // --- УНП ---

    public static function unps(): array
    {
        // [УНП, сохраняется, жёлтая подсказка о контрольной цифре]
        return [
            'МТС' => ['800013732', true, false],
            'контрольная 7' => ['100000007', true, false],
            'не та контрольная' => ['800013733', true, true],
            'остаток 10' => ['000000190', true, true],
            '8 цифр' => ['80001373', false, false],
            '10 цифр' => ['8000137321', false, false],
            'буквы' => ['AB0013732', false, false],
        ];
    }

    #[DataProvider('unps')]
    public function test_unp_nine_digits_check_digit_is_warning(string $unp, bool $saved, bool $warning): void
    {
        $response = $this->save(['seller_unp' => $unp]);

        $saved ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['seller_unp' => 'УНП — ровно 9 цифр.']);
        $this->assertSame($saved ? $unp : null, Setting::value('seller_unp'));

        $html = $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertSame($warning, str_contains($html, 'Контрольная цифра не сходится — проверьте УНП'));
    }

    public function test_unp_spaces_are_removed(): void
    {
        $this->save(['seller_unp' => '800 013 732'])->assertSessionHasNoErrors();
        $this->assertSame('800013732', Setting::value('seller_unp'));
    }

    // --- IBAN, BIC ---

    public static function ibans(): array
    {
        return [
            'верный' => [self::IBAN, true],
            'с пробелами и строчными' => ['by13 nbrb 3600 9000 0000 2z00 ab00', true],
            'контрольные цифры' => ['BY14NBRB3600900000002Z00AB00', false],
            'переставлены символы' => ['BY13NBRB3600900000002Z00BA00', false],
            '27 символов' => ['BY13NBRB3600900000002Z00AB0', false],
            '29 символов' => ['BY13NBRB3600900000002Z00AB000', false],
            'не BY' => ['DE89370400440532013000', false],
        ];
    }

    #[DataProvider('ibans')]
    public function test_iban_format_and_mod97(string $iban, bool $valid): void
    {
        $response = $this->save(['seller_iban' => $iban]);

        if ($valid) {
            $response->assertSessionHasNoErrors();
            // Хранится без пробелов, заглавными
            $this->assertSame(self::IBAN, Setting::value('seller_iban'));
        } else {
            $response->assertSessionHasErrors('seller_iban');
            $this->assertNull(Setting::value('seller_iban'));
        }
    }

    public function test_bic_8_or_11_symbols(): void
    {
        $this->save(['seller_bic' => 'alfaby2x'])->assertSessionHasNoErrors();
        $this->assertSame('ALFABY2X', Setting::value('seller_bic'));

        $this->save(['seller_bic' => 'NBRBBY2XXXX'])->assertSessionHasNoErrors();

        foreach (['ALFABY2', 'ALFABY2XX', '1234BY2X'] as $bic) {
            $this->save(['seller_bic' => $bic])->assertSessionHasErrors('seller_bic');
        }
    }

    public function test_empty_requisites_are_allowed(): void
    {
        $this->save(['seller_unp' => '', 'seller_iban' => '', 'seller_bic' => ''])->assertSessionHasNoErrors();

        $this->assertSame(Setting::PLACEHOLDER, Setting::get('seller_iban'));
    }

    // --- Контакты ---

    public function test_phones_with_labels_are_saved_and_blank_rows_dropped(): void
    {
        $this->save(['phones' => [
            ['label' => 'Отдел продаж', 'number' => '+375 (17) 123-45-67'],
            ['label' => '', 'number' => ''],
            ['label' => '', 'number' => '+375 29 765-43-21'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            ['label' => 'Отдел продаж', 'number' => '+375 (17) 123-45-67', 'tel' => '+375171234567'],
            ['label' => '', 'number' => '+375 29 765-43-21', 'tel' => '+375297654321'],
        ], Setting::phones());

        $html = $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->getContent();
        // Сохранённые + две пустые строки для новых номеров
        $this->assertSame(4, substr_count($html, 'data-phone-row'));
    }

    public function test_phone_validation(): void
    {
        $this->save(['phones' => [['label' => 'Бухгалтерия', 'number' => '']]])
            ->assertSessionHasErrors(['phones.0.number' => 'Укажите номер — подпись без номера не сохраняется.']);
        $this->save(['phones' => [['label' => '', 'number' => 'звоните']]])
            ->assertSessionHasErrors('phones.0.number');
        $this->save(['contact_email' => 'не почта'])->assertSessionHasErrors('contact_email');

        $this->assertSame([], Setting::phones());
    }

    public function test_validation_errors_shown_under_fields(): void
    {
        $this->save(['seller_unp' => '12345', 'phones' => [['label' => 'Склад', 'number' => '']]]);

        $html = $this->actingAs($this->admin)->get('/admin/settings')->getContent();

        $this->assertStringContainsString('data-validation-summary', $html);
        $this->assertStringContainsString('id="f-seller_unp-error"', $html);
        $this->assertStringContainsString('id="f-phone-number-0-error"', $html);
        // Введённое сохранилось
        $this->assertStringContainsString('value="Склад"', $html);
    }

    // --- Сайт ---

    /** Пустые настройки: миграция переноса при RefreshDatabase заполняет контакты из config */
    private function clearSettings(): void
    {
        DB::table('settings')->delete();
        Setting::flush();
    }

    private function fill(): void
    {
        $this->save([
            'phones' => [
                ['label' => 'Отдел продаж', 'number' => '+375 (17) 123-45-67'],
                ['label' => 'Бухгалтерия', 'number' => '+375 (17) 765-43-21'],
            ],
            'contact_email' => 'sales@smesitel.by',
            'contact_hours' => 'Пн–Пт 8:30–17:30',
            'contact_pickup_address' => 'г. Минск, ул. Складская, 5',
            'seller_name' => 'ООО «Смеситель»',
            'seller_full_name' => 'Общество с ограниченной ответственностью «Смеситель»',
            'seller_unp' => '800013732',
            'seller_address' => 'г. Минск, ул. Юридическая, 1',
            'seller_postal_address' => '220000, г. Минск, а/я 1',
            'seller_registration' => 'Минский горисполком, 01.02.2020',
            'seller_registry' => '№ 123456 от 03.03.2020',
            'seller_iban' => self::IBAN,
            'seller_bank' => 'ЗАО «Альфа-Банк»',
            'seller_bic' => 'ALFABY2X',
            'seller_director' => 'Директор Иванов И. И.',
        ])->assertSessionHasNoErrors();
    }

    public function test_header_and_footer_show_contacts_from_settings(): void
    {
        $this->fill();

        $html = $this->get('/')->assertOk()->getContent();

        $topbar = substr($html, strpos($html, 'data-header-topbar'), 2000);
        $this->assertStringContainsString('tel:+375171234567', $topbar);
        $this->assertStringNotContainsString('+375 (17) 765-43-21', $topbar);
        $this->assertStringContainsString('mailto:sales@smesitel.by', $topbar);
        $this->assertStringContainsString('Пн–Пт 8:30–17:30', $topbar);

        $footer = substr($html, strpos($html, 'data-footer-contacts'));
        $this->assertStringContainsString('tel:+375177654321', $footer);
        $this->assertStringContainsString('Бухгалтерия', $footer);

        // Подвал — кратко: наименование, УНП, юр. адрес, торговый реестр; без счёта
        $seller = substr($footer, strpos($footer, 'data-seller'));
        $seller = substr($seller, 0, strpos($seller, '</dl>'));
        foreach (['ООО «Смеситель»', '800013732', 'г. Минск, ул. Юридическая, 1', '№ 123456 от 03.03.2020'] as $text) {
            $this->assertStringContainsString(e($text), $seller);
        }
        $this->assertStringNotContainsString(self::IBAN, $seller);
    }

    public function test_contacts_page_shows_contacts_and_all_requisites(): void
    {
        $this->fill();

        $html = $this->get('/contacts')->assertOk()->getContent();

        $this->assertStringContainsString('data-contacts-info', $html);
        $this->assertStringContainsString('г. Минск, ул. Складская, 5', $html);

        $requisites = substr($html, strpos($html, 'data-requisites'));
        foreach ([self::IBAN, 'ALFABY2X', e('ЗАО «Альфа-Банк»'), 'Директор Иванов И. И.', '220000, г. Минск, а/я 1',
            'Минский горисполком, 01.02.2020', e('Общество с ограниченной ответственностью «Смеситель»')] as $text) {
            $this->assertStringContainsString($text, $requisites);
        }
        $this->assertStringNotContainsString(Setting::PLACEHOLDER, substr($requisites, 0, strpos($requisites, '</section>')));

        // На других страницах блоков нет
        Page::where('slug', 'about')->update(['is_published' => true]);
        $this->get('/about')->assertDontSee('data-requisites', false);
    }

    public function test_empty_contacts_are_hidden_and_requisites_show_placeholder(): void
    {
        $this->clearSettings();

        $html = $this->get('/contacts')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-contacts-info', $html);
        $this->assertStringNotContainsString('href="tel:', $html);
        $this->assertStringContainsString(Setting::PLACEHOLDER, substr($html, strpos($html, 'data-requisites')));
    }

    public function test_mail_signature_has_contacts(): void
    {
        $this->fill();

        $html = (string) (new UserInvitation('token'))->toMail(User::factory()->create())->render();

        $this->assertStringContainsString('Отдел продаж: +375 (17) 123-45-67', $html);
        $this->assertStringContainsString('Бухгалтерия: +375 (17) 765-43-21', $html);
        $this->assertStringContainsString('Email: sales@smesitel.by', $html);
        $this->assertStringContainsString('Пн–Пт 8:30–17:30', $html);
        $this->assertStringContainsString(e('ООО «Смеситель»'), $html);
    }

    public function test_mail_without_contacts_has_no_signature(): void
    {
        $this->clearSettings();

        $this->assertSame([], Setting::mailSignature());

        $html = (string) (new UserInvitation('token'))->toMail(User::factory()->create())->render();
        $this->assertStringNotContainsString('Email:', $html);
    }

    // --- Перенос из config ---

    private function migrateContacts(): void
    {
        (require database_path('migrations/2026_10_30_100000_move_contacts_to_settings.php'))->up();
    }

    public function test_migration_moves_contacts_from_config(): void
    {
        DB::table('settings')->delete();
        Setting::put(['seller_name' => 'ООО «Было»', 'footer_about' => 'Текст со стенда']);
        config(['shop.phone' => '+375 (17) 111-22-33', 'shop.email' => 'office@smesitel.by', 'shop.working_hours' => 'Пн–Пт 9–18']);

        $this->migrateContacts();

        $this->assertSame([['label' => 'Отдел продаж', 'number' => '+375 (17) 111-22-33', 'tel' => '+375171112233']], Setting::phones());
        $this->assertSame('office@smesitel.by', Setting::value('contact_email'));
        $this->assertSame('Пн–Пт 9–18', Setting::value('contact_hours'));
        // Внесённое раньше — на месте
        $this->assertSame('ООО «Было»', Setting::value('seller_name'));
        $this->assertSame('Текст со стенда', Setting::value('footer_about'));
    }

    public function test_migration_keeps_admin_values_and_is_idempotent(): void
    {
        Setting::put(['contact_email' => 'admin@smesitel.by']);
        config(['shop.phone' => '+375 (17) 111-22-33', 'shop.email' => 'office@smesitel.by']);

        $this->migrateContacts();
        $this->migrateContacts();

        $this->assertSame('admin@smesitel.by', Setting::value('contact_email'));
        $this->assertCount(1, Setting::phones());
    }

    public function test_migration_skips_placeholder_phone(): void
    {
        DB::table('settings')->delete();
        Setting::flush();
        config(['shop.phone' => '+375 (29) 000-00-00']);

        $this->migrateContacts();

        $this->assertSame([], Setting::phones());
    }
}
