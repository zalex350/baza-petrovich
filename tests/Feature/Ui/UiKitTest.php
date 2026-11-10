<?php

namespace Tests\Feature\Ui;

use App\Enums\OrderHistoryEvent;
use App\Enums\OrderStatus;
use App\Models\Product;
use Tests\TestCase;

/**
 * Компоненты UI-кита (resources/views/components): разметка, варианты, ошибки, единая карта статусов.
 */
class UiKitTest extends TestCase
{
    // --- x-button ---

    public function test_button_variants_and_sizes(): void
    {
        $this->blade('<x-button>Сохранить</x-button>')
            ->assertSee('<button type="submit"', false)
            ->assertSee('bg-primary', false)
            ->assertSee('px-btn-x py-btn-y', false);

        $this->blade('<x-button variant="danger" size="sm" type="button">Удалить</x-button>')
            ->assertSee('type="button"', false)
            ->assertSee('bg-danger', false)
            ->assertSee('px-btn-x-sm', false);

        $this->blade('<x-button variant="secondary">Отмена</x-button>')->assertSee('border-line-control bg-surface text-primary', false);
    }

    public function test_button_with_href_renders_link(): void
    {
        $view = $this->blade('<x-button variant="link" href="/admin/orders">Сбросить</x-button>');

        $view->assertSee('<a href="/admin/orders"', false)->assertSee('underline', false)->assertDontSee('<button', false);
    }

    // --- x-input / x-select / x-textarea ---

    public function test_input_renders_label_value_and_required_mark(): void
    {
        $this->blade('<x-input name="q" label="Поиск" value="Аквамир" required class="grow" placeholder="Номер" />')
            ->assertSee('<label for="f-q"', false)
            ->assertSee('id="f-q"', false)
            ->assertSee('value="Аквамир"', false)
            ->assertSee('placeholder="Номер"', false)
            ->assertSee('required', false)
            ->assertSee('<span class="text-tone-danger-text" aria-hidden="true">*</span>', false)
            // class — на обёртку, а не на поле
            ->assertSee('<div class="min-w-0 grow">', false)
            ->assertDontSee('aria-invalid', false);
    }

    public function test_input_shows_validation_error(): void
    {
        $this->withViewErrors(['items.0.qty' => 'Количество должно быть больше нуля']);

        $this->blade('<x-input name="items[0][qty]" label="Количество" />')
            ->assertSee('id="f-items-0-qty"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="f-items-0-qty-error"', false)
            ->assertSee('border-tone-danger-border', false)
            ->assertSee('Количество должно быть больше нуля');
    }

    public function test_password_input_never_echoes_value(): void
    {
        $this->blade('<x-input name="password" type="password" value="secret" />')->assertDontSee('secret');
    }

    public function test_select_with_options_marks_selected(): void
    {
        $this->blade('<x-select name="status" placeholder="Все" :options="$options" value="shipped" />', [
            'options' => ['new' => 'Новый', 'shipped' => 'Отгружен'],
        ])
            ->assertSee('<option value="">Все</option>', false)
            ->assertSee('<option value="shipped" selected>Отгружен</option>', false)
            ->assertSee('<option value="new" >Новый</option>', false);
    }

    public function test_select_accepts_options_slot(): void
    {
        $this->blade('<x-select name="role"><option value="admin">Админ</option></x-select>')
            ->assertSee('<option value="admin">Админ</option>', false);
    }

    public function test_textarea_value_and_error(): void
    {
        $this->withViewErrors(['cancel_reason' => 'Укажите причину']);

        $this->blade('<x-textarea name="cancel_reason" label="Причина" rows="2" value="Передумали" />')
            ->assertSee('rows="2"', false)
            ->assertSee('>Передумали</textarea>', false)
            ->assertSee('Укажите причину');
    }

    public function test_checkbox_sends_unchecked_value_and_shows_error(): void
    {
        $this->blade('<x-checkbox name="notify" label="Новый заказ" hint="Письмо при заказе" :checked="true" class="mt-2" />')
            ->assertSee('<input type="hidden" name="notify" value="0">', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('checked', false)
            ->assertSee('aria-describedby="f-notify-hint"', false)
            ->assertSeeInOrder(['Новый заказ', 'Письмо при заказе'])
            ->assertSee('<div class="min-w-0 mt-2">', false);

        $this->blade('<x-checkbox name="notify" label="Новый заказ" />')->assertDontSee(' checked', false);

        // Группа галочек — без скрытого поля
        $this->blade('<x-checkbox name="ids[]" label="Товар" value="5" :unchecked="false" />')
            ->assertDontSee('type="hidden"', false)
            ->assertSee('value="5"', false);

        $this->withViewErrors(['notify' => 'Неверное значение']);
        $this->blade('<x-checkbox name="notify" label="Новый заказ" />')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Неверное значение');
    }

    // --- x-badge ---

    public function test_badge_status_uses_single_color_map(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->blade('<x-badge :status="$status" />', ['status' => $status])
                ->assertSee($status->label())
                ->assertSee($status->color(), false)
                ->assertSee('data-status="'.$status->value.'"', false);
        }

        // Старый partial статуса берёт ту же карту
        $this->view('partials.order_status', ['status' => OrderStatus::Shipped])
            ->assertSee(OrderStatus::Shipped->color(), false);
    }

    public function test_status_colors_are_design_tokens_and_unique(): void
    {
        $colors = array_map(fn (OrderStatus $status) => $status->color(), OrderStatus::cases());

        $this->assertSame($colors, array_unique($colors));

        foreach ([...$colors, ...array_map(fn ($e) => $e->color(), OrderHistoryEvent::cases())] as $classes) {
            $this->assertMatchesRegularExpression('/^bg-(status|tone)-[a-z]+-bg text-(status|tone)-[a-z]+-text$/', $classes);
        }
    }

    public function test_badge_tone_and_custom_text(): void
    {
        $this->blade('<x-badge tone="success">Оплачен</x-badge>')
            ->assertSee('bg-tone-success-bg', false)
            ->assertSee('Оплачен');

        $this->blade('<x-badge :status="$s" size="md">Свой текст</x-badge>', ['s' => OrderStatus::New])
            ->assertSee('Свой текст')
            ->assertDontSee('Новый')
            ->assertSee('px-3 py-1 text-sm', false);
    }

    // --- x-alert ---

    public function test_alert_types(): void
    {
        $this->blade('<x-alert type="error" title="Ошибка">Не сохранено</x-alert>')
            ->assertSee('role="alert"', false)
            ->assertSee('bg-tone-danger-bg', false)
            ->assertSeeInOrder(['Ошибка', 'Не сохранено']);

        $this->blade('<x-alert type="success" dismissible>Готово</x-alert>')
            ->assertSee('role="status"', false)
            ->assertSee('aria-label="Закрыть"', false);
    }

    public function test_flash_shows_session_messages_and_errors(): void
    {
        session()->flash('success', 'Заказ оформлен');
        session()->flash('warning', 'Часть товаров недоступна');
        $this->withViewErrors(['qty' => 'Неверное количество']);

        $this->blade('<x-alert.flash />')
            ->assertSee('data-flash', false)
            ->assertSee('Заказ оформлен')
            ->assertSee('Часть товаров недоступна')
            ->assertSee('Неверное количество');

        $this->blade('<x-alert.flash validation="none" />')->assertDontSee('Неверное количество');
        $this->blade('<x-alert.flash :validation="false" />')->assertDontSee('Неверное количество');
    }

    public function test_floating_flash_is_styled_by_kit_css(): void
    {
        // cart-actions.js только ставит атрибут; положение — в CSS кита (вне @layer: класс из JS не выбрасывается)
        $js = file_get_contents(resource_path('js/cart-actions.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("flash.dataset.floating = ''", $js);
        $this->assertStringNotContainsString('classList.add(', $js);
        // По центру сверху, на телефоне — по ширине экрана с отступами 16px (inset-x-4, top-4)
        $this->assertStringContainsString("[data-flash][data-floating] {\n    @apply fixed inset-x-4 top-4 z-50 mx-auto mb-0 max-w-md;", $css);
    }

    public function test_flash_summary_replaces_error_list(): void
    {
        $this->withViewErrors(['qty' => 'Неверное количество', 'name' => 'Укажите название']);

        $this->blade('<x-alert.flash validation="summary" />')
            ->assertSee('Проверьте выделенные поля')
            ->assertSee('data-validation-summary', false)
            ->assertDontSee('Неверное количество')
            ->assertDontSee('Укажите название')
            ->assertDontSee('<ul', false);

        // Без ошибок сводки нет
        $this->withViewErrors([]);
        $this->blade('<x-alert.flash validation="summary" />')->assertDontSee('Проверьте выделенные поля');
    }

    // --- x-empty-state ---

    public function test_empty_state_with_action(): void
    {
        $this->blade('<x-empty-state title="Заказов нет" icon="magnifying-glass">Пояснение<x-slot:action><x-button href="/x">Сбросить</x-button></x-slot:action></x-empty-state>')
            ->assertSee('data-empty-state', false)
            ->assertSeeInOrder(['<svg', 'Заказов нет', 'Пояснение', 'href="/x"'], false);
    }

    // --- x-table ---

    public function test_table_is_scrollable_and_stacks_on_mobile(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-table stack caption="Заказы">
                <x-slot:head><x-table.th>№</x-table.th><x-table.th align="right">Сумма</x-table.th></x-slot:head>
                <tr><x-table.td label="№">SM-1</x-table.td><x-table.td label="Сумма" align="right" nowrap>10</x-table.td></tr>
            </x-table>
            BLADE);

        $view->assertSee('overflow-x-auto', false)
            ->assertSee('ui-table-stack-sm', false)
            ->assertSee('<caption class="sr-only">Заказы</caption>', false)
            ->assertSee('<th scope="col"', false)
            ->assertSee('data-label="Сумма"', false)
            ->assertSee('text-right whitespace-nowrap', false);

        $this->blade('<x-table><tr><x-table.td>1</x-table.td></tr></x-table>')
            ->assertDontSee('ui-table-stack', false)
            ->assertDontSee('<thead', false);
    }

    public function test_table_stack_breakpoints(): void
    {
        foreach (['md', 'lg', 'xl'] as $breakpoint) {
            $this->blade('<x-table :stack="$bp"><tr><x-table.td label="№">1</x-table.td></tr></x-table>', ['bp' => $breakpoint])
                ->assertSee('ui-table-stack-'.$breakpoint, false);
        }

        // Каждый класс есть в CSS — иначе карточки не включатся
        $css = file_get_contents(resource_path('css/app.css'));
        foreach (['sm' => 639, 'md' => 767, 'lg' => 1023, 'xl' => 1279] as $breakpoint => $maxWidth) {
            $this->assertStringContainsString("@media (max-width: {$maxWidth}px) {\n        .ui-table-stack-{$breakpoint} thead", $css);
        }
    }

    // --- x-confirm ---

    public function test_confirm_renders_modal_form(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-confirm action="/admin/product/5/delete" label="Удалить" title="Удалить товар?" confirm-label="Да, удалить" trigger-size="sm">
                Товар будет удалён.
                <x-slot:fields><x-textarea name="reason" label="Причина" /></x-slot:fields>
            </x-confirm>
            BLADE);

        $view->assertSee('x-data', false)
            ->assertSee('x-teleport="body"', false)
            // Фокус удерживается в окне (@alpinejs/focus), начальный фокус — свой
            ->assertSee('x-trap.inert.noscroll.noautofocus="open"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('action="/admin/product/5/delete"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="reason"', false)
            ->assertSeeInOrder(['Удалить', 'Удалить товар?', 'Товар будет удалён.', 'Отмена', 'Да, удалить'])
            ->assertDontSee('name="_method"', false)
            // Кнопка-триггер не отправляет форму
            ->assertSee('type="button"', false);
    }

    public function test_confirm_spoofs_method(): void
    {
        $this->blade('<x-confirm action="/x" label="Удалить" title="?" method="delete">Текст</x-confirm>')
            ->assertSee('name="_method" value="DELETE"', false);
    }

    // --- x-tabs ---

    public function test_tabs_mark_current_and_show_count(): void
    {
        $tabs = [
            'main' => ['label' => 'Клиент', 'url' => '/a'],
            'codes' => ['label' => 'Коды', 'url' => '/a?tab=codes', 'count' => 7],
        ];

        $html = (string) $this->blade('<x-tabs label="Разделы" :tabs="$tabs" active="codes" />', ['tabs' => $tabs]);

        $this->assertStringContainsString('<nav aria-label="Разделы"', $html);
        $this->assertMatchesRegularExpression('#href="/a\?tab=codes"[^>]*aria-current="page"[^>]*data-tab="codes"#s', $html);
        $this->assertDoesNotMatchRegularExpression('#href="/a"[^>]*aria-current#s', $html);
        $this->assertStringContainsString('>7</span>', $html);
    }

    // --- x-price ---

    public function test_price_formats_amount_and_vat_label(): void
    {
        // Пробелы в сумме — неразрывные (U+00A0)
        $this->blade('<x-price :amount="1234.5" />')->assertSee("1\u{00A0}234,50\u{00A0}BYN")->assertDontSee('НДС');
        $this->blade('<x-price :amount="270.34" vat="net" />')->assertSeeInOrder(["270,34\u{00A0}BYN", 'без НДС']);
        $this->blade('<x-price amount="10" vat="gross" />')->assertSeeInOrder(["10,00\u{00A0}BYN", 'с НДС']);
        $this->blade('<x-price :amount="null" />')->assertSee('Цена уточняется')->assertSee('data-price-tbd', false);
    }

    public function test_price_for_product_shows_vat_mode(): void
    {
        $withVat = new Product(['price' => 100, 'vat_rate' => 20]);
        $exempt = new Product(['price' => 80, 'vat_rate' => null]);
        $noPrice = new Product(['price' => null]);

        $this->blade('<x-price :product="$p" />', ['p' => $withVat])
            ->assertSeeInOrder(["100,00\u{00A0}BYN", 'без НДС', "с НДС: 120,00\u{00A0}BYN"]);

        $this->blade('<x-price :product="$p" />', ['p' => $exempt])
            ->assertSeeInOrder(["80,00\u{00A0}BYN", 'без НДС', 'НДС не облагается']);

        $this->blade('<x-price :product="$p" />', ['p' => $noPrice])->assertSee('Цена уточняется');
    }
}
