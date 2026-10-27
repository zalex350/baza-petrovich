<?php

namespace Tests\Feature\Ui;

use App\Models\Product;
use App\Support\Plural;
use Tests\TestCase;

/**
 * Компоненты кита для витрины: x-breadcrumbs, x-stock, x-qty, x-product-badge.
 */
class ProductKitTest extends TestCase
{
    // --- x-checkbox: количество справа, галочка в конце ---

    public function test_checkbox_meta_and_end_position(): void
    {
        $this->blade('<x-checkbox name="brand[]" id="f-brand-0" value="Grohe" label="Grohe" :meta="12" :unchecked="false" />')
            ->assertSee('id="f-brand-0"', false)
            ->assertSeeInOrder(['Grohe', '12'])
            ->assertDontSee('type="hidden"', false);

        $this->blade('<x-checkbox name="stock[]" value="in_stock" label="Только в наличии" position="end" :unchecked="false" />')
            ->assertSee('flex-row-reverse justify-between', false);
    }

    // --- App\Support\Plural ---

    public function test_plural_ru(): void
    {
        $forms = ['товар', 'товара', 'товаров'];

        foreach ([0 => 'товаров', 1 => 'товар', 2 => 'товара', 5 => 'товаров', 11 => 'товаров', 14 => 'товаров', 21 => 'товар', 24 => 'товара', 112 => 'товаров'] as $n => $word) {
            $this->assertSame("{$n} {$word}", Plural::ru($n, $forms));
        }
    }

    // --- x-logo ---

    public function test_logo_highlights_domain_zone(): void
    {
        config(['shop.name' => 'SMESITEL.BY']);

        $this->blade('<x-logo />')
            ->assertSee('href="/" aria-label="SMESITEL.BY — на главную"', false)
            ->assertSee('SMESITEL<span class="text-link">.BY</span>', false)
            ->assertSee('text-logo', false);

        $this->blade('<x-logo size="sm" :href="null" />')
            ->assertDontSee('<a ', false)
            ->assertSee('text-logo-sm', false);
    }

    // --- x-breadcrumbs ---

    public function test_breadcrumbs_last_without_url_is_current_page(): void
    {
        $view = $this->blade('<x-breadcrumbs :items="$items" />', ['items' => [
            ['label' => 'Каталог', 'url' => '/catalog'],
            ['label' => 'Смесители', 'url' => '/catalog/mixers'],
            ['label' => 'Grohe Start'],
        ]]);

        $view->assertSee('aria-label="Хлебные крошки"', false)
            ->assertSee('<a href="/catalog"', false)
            ->assertSee('<a href="/catalog/mixers"', false)
            ->assertSee('aria-current="page">Grohe Start</span>', false)
            ->assertSeeInOrder(['Каталог', 'Смесители', 'Grohe Start']);
    }

    public function test_breadcrumbs_last_with_url_is_link(): void
    {
        // Карточка товара: последней стоит категория — ссылкой
        $this->blade('<x-breadcrumbs :items="$items" />', ['items' => [
            ['label' => 'Каталог', 'url' => '/catalog'],
            ['label' => 'Смесители', 'url' => '/catalog/mixers?page=2'],
        ]])
            ->assertSee('<a href="/catalog/mixers?page=2"', false)
            ->assertDontSee('aria-current', false);
    }

    public function test_empty_breadcrumbs_render_nothing(): void
    {
        $this->blade('<x-breadcrumbs :items="[]" />')->assertDontSee('<nav', false);
    }

    // --- x-stock ---

    public function test_stock_levels_follow_config_thresholds(): void
    {
        config(['shop.stock_many' => 50, 'shop.stock_few' => 5]);

        $cases = [
            [120, false, 'many', 'Много', 3],
            [50, false, 'many', 'Много', 3],
            [49, false, 'few', 'Мало', 2],
            [6, false, 'few', 'Мало', 2],
            [5, false, 'count', '5 шт.', 1],
            [1, false, 'count', '1 шт.', 1],
            [0, true, 'on_order', 'Под заказ', 0],
            [0, false, 'out_of_stock', 'Нет в наличии', 0],
        ];

        foreach ($cases as [$stock, $onOrder, $level, $label, $bars]) {
            $html = (string) $this->blade('<x-stock :stock="$s" :on-order="$o" />', ['s' => $stock, 'o' => $onOrder]);

            $this->assertStringContainsString('data-stock="'.$level.'"', $html, "stock $stock");
            $this->assertStringContainsString($label, $html, "stock $stock");
            $this->assertSame(3, substr_count($html, 'w-1 rounded-bar'), "stock $stock");
            $this->assertSame($bars, preg_match_all('/w-1 rounded-bar h-\d bg-(success|warning)/', $html), "stock $stock");
        }

        // Пороги — из конфига
        config(['shop.stock_many' => 10]);
        $this->blade('<x-stock :stock="12" />')->assertSee('data-stock="many"', false);
    }

    public function test_stock_thresholds_default_to_10_and_3(): void
    {
        // config/shop.php: SHOP_STOCK_MANY / SHOP_STOCK_FEW, по умолчанию 10 и 3
        $this->assertSame(10, config('shop.stock_many'));
        $this->assertSame(3, config('shop.stock_few'));

        $this->blade('<x-stock :stock="10" />')->assertSee('data-stock="many"', false);
        $this->blade('<x-stock :stock="9" />')->assertSee('data-stock="few"', false);
        $this->blade('<x-stock :stock="3" />')->assertSee('3 шт.');
    }

    public function test_stock_from_product(): void
    {
        config(['shop.stock_many' => 50, 'shop.stock_few' => 5]);

        $product = new Product(['stock' => 0, 'on_order' => true]);

        $this->blade('<x-stock :product="$p" />', ['p' => $product])->assertSee('Под заказ');
    }

    // --- x-qty ---

    public function test_qty_uses_product_rules_and_labels_buttons(): void
    {
        $product = new Product(['min_qty' => 2, 'qty_step' => 2, 'default_qty' => 4]);

        $this->blade('<x-qty :product="$p" />', ['p' => $product])
            ->assertSee('name="qty"', false)
            ->assertSee('value="4"', false)
            ->assertSee('min="2"', false)
            ->assertSee('step="2"', false)
            ->assertSee('aria-label="Уменьшить количество"', false)
            ->assertSee('aria-label="Увеличить количество"', false)
            ->assertSee('<label for="f-qty" class="sr-only">Количество</label>', false);
    }

    public function test_qty_snaps_initial_value_and_disables_buttons_at_bounds(): void
    {
        // 3 при min 2 и шаге 2 → 4
        $this->blade('<x-qty name="items[5][qty]" :value="3" :min="2" :step="2" />')
            ->assertSee('id="f-items-5-qty"', false)
            ->assertSee('value="4"', false);

        // На минимуме «−» отключён; на максимуме — «+»
        $html = (string) $this->blade('<x-qty :value="1" :max="1" />');
        $this->assertSame(2, preg_match_all('/<button[^>]*\sdisabled[\s>=]/', $html));

        $html = (string) $this->blade('<x-qty :value="5" :max="10" />');
        $this->assertSame(0, preg_match_all('/<button[^>]*\sdisabled[\s>=]/', $html));
    }

    public function test_qty_keeps_value_above_max_and_has_hint(): void
    {
        // Остаток уменьшился на сервере: в корзине 8 при остатке 5 — показывается 8, не урезается
        $html = (string) $this->blade('<x-qty :value="8" :min="2" :step="3" :max="5" />');
        $this->assertStringContainsString('value="8"', $html);
        $this->assertStringContainsString('max="5"', $html);
        // «+» отключён, «−» — нет
        $this->assertSame(1, preg_match_all('/<button[^>]*\sdisabled[\s>=]/', $html));

        // Подсказка (округление, «Доступно N шт.»), ручной ввод — по Enter и уходу из поля
        $this->assertStringContainsString('data-qty-hint', $html);
        $this->assertStringContainsString('@keydown.enter="apply($event)"', $html);
        $this->assertStringContainsString("'Округлено до '", $html);
        $this->assertStringContainsString("'Доступно '", $html);
    }

    // --- x-copy ---

    public function test_copy_renders_text_and_button(): void
    {
        $this->blade('<x-copy text="LEDEME-L1402" label="Скопировать артикул" />')
            ->assertSee('x-data="copyText(\'LEDEME-L1402\')"', false)
            ->assertSee('<span x-ref="text" class="font-medium text-ink">LEDEME-L1402</span>', false)
            ->assertSee('aria-label="Скопировать артикул"', false)
            ->assertSee('копировать');
    }

    public function test_copy_field_renders_readonly_input(): void
    {
        $this->blade('<x-copy field id="invite-url" text="https://x.by/reset?a=1&b=2" label="Скопировать ссылку" />')
            ->assertSee('<input', false)
            ->assertSee('x-ref="text"', false)
            ->assertSee('readonly', false)
            ->assertSee('id="invite-url"', false)
            ->assertSee('value="https://x.by/reset?a=1&amp;b=2"', false)
            ->assertSee('aria-label="Скопировать ссылку"', false)
            ->assertDontSee('font-medium text-ink', false);
    }

    // --- x-product-badge ---

    public function test_product_badges(): void
    {
        $this->blade('<x-product-badge type="hit" />')->assertSee('data-product-badge="hit"', false)->assertSee('ХИТ');
        $this->blade('<x-product-badge type="new" />')->assertSee('НОВИНКА');

        $both = new Product(['is_hit' => true, 'is_new' => true]);
        $this->blade('<x-product-badge :product="$p" />', ['p' => $both])->assertSeeInOrder(['НОВИНКА', 'ХИТ']);

        $none = new Product;
        $this->blade('<x-product-badge :product="$p" />', ['p' => $none])->assertDontSee('data-product-badge', false);
    }
}
