{{--
    Настройки сайта (AdminSettingsController): контакты, реквизиты продавца, тексты подвала.
    Данные: $values — сохранённые значения (ключ → значение), $phones — строки телефонов
    (сохранённые и пустые для новых номеров).
--}}
@extends('layouts.admin')

@section('title', 'Настройки')

@section('flash-validation', 'summary')

@php
    $f = \App\Models\Setting::FIELDS;
    $v = fn (string $key) => $values[$key] ?? '';
@endphp

@section('content')

<h1 class="text-3xl font-bold mb-2">Настройки</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Контакты — в тёмной полосе шапки, мобильном меню, подвале, на странице «Контакты» и в подписи писем;
    незаполненные не показываются. Реквизиты — в подвале (кратко) и на странице «Контакты» (целиком);
    пустой реквизит на сайте показывается как {{ \App\Models\Setting::PLACEHOLDER }}.
</p>

<form method="POST" action="/admin/settings" class="max-w-3xl space-y-8 rounded-card border border-line bg-surface p-card" data-settings-form novalidate>
    @csrf

    <fieldset class="space-y-4" data-settings-contacts>
        <legend class="mb-1 text-lg font-semibold text-ink">Контакты</legend>

        <div data-settings-phones>
            <p class="mb-1 text-sm font-medium text-ink-muted">Телефоны</p>
            <p class="mb-3 text-sm text-ink-muted">
                Первый — в шапке сайта. Подпись — например, «Отдел продаж», «Бухгалтерия». Пустая строка не сохраняется;
                нужно больше строк — сохраните, появятся новые.
            </p>
            <div class="space-y-3">
                @foreach($phones as $i => $phone)
                    <div class="grid gap-3 sm:grid-cols-2" data-phone-row>
                        <x-input name="phones[{{ $i }}][label]" :id="'f-phone-label-'.$i" :value="$phone['label'] ?? ''" placeholder="Отдел продаж" aria-label="Подпись телефона {{ $i + 1 }}" maxlength="100" />
                        <x-input name="phones[{{ $i }}][number]" :id="'f-phone-number-'.$i" type="tel" :value="$phone['number'] ?? ''" placeholder="+375 (17) 123-45-67" aria-label="Номер телефона {{ $i + 1 }}" maxlength="30" />
                    </div>
                @endforeach
            </div>
        </div>

        <x-input name="contact_email" type="email" :label="$f['contact_email']" :value="$v('contact_email')" placeholder="info@smesitel.by" />
        <x-input name="contact_hours" :label="$f['contact_hours']" :value="$v('contact_hours')" placeholder="Пн–Пт 9:00–18:00" />
        <x-textarea name="contact_pickup_address" :label="$f['contact_pickup_address']" :value="$v('contact_pickup_address')" rows="2" hint="На странице «Контакты»." />
    </fieldset>

    <fieldset class="space-y-4" data-settings-requisites>
        <legend class="mb-1 text-lg font-semibold text-ink">Реквизиты продавца</legend>
        <x-input name="seller_name" :label="$f['seller_name']" :value="$v('seller_name')" placeholder="ООО «…»" hint="В подвале и шапке документов." />
        <x-input name="seller_full_name" :label="$f['seller_full_name']" :value="$v('seller_full_name')" placeholder="Общество с ограниченной ответственностью «…»" />
        <x-input name="seller_unp" :label="$f['seller_unp']" :value="$v('seller_unp')" inputmode="numeric" maxlength="9" hint="9 цифр." :warning="\App\Rules\Unp::warning(old('seller_unp', $v('seller_unp')))" class="max-w-xs" />
        <x-input name="seller_address" :label="$f['seller_address']" :value="$v('seller_address')" />
        <x-input name="seller_postal_address" :label="$f['seller_postal_address']" :value="$v('seller_postal_address')" />
        <x-textarea name="seller_registration" :label="$f['seller_registration']" :value="$v('seller_registration')" rows="2" hint="Кем и когда зарегистрировано, например: Минский горисполком, 01.02.2020, № 193000000." />
        <x-input name="seller_registry" :label="$f['seller_registry']" :value="$v('seller_registry')" hint="Номер и дата регистрации в Торговом реестре Республики Беларусь." />
        <x-input name="seller_iban" :label="$f['seller_iban']" :value="$v('seller_iban')" placeholder="BY00 XXXX 0000 0000 0000 0000 0000" hint="28 символов, начинается с BY; пробелы можно — сохраняется без них." />
        <x-input name="seller_bank" :label="$f['seller_bank']" :value="$v('seller_bank')" />
        <x-input name="seller_bic" :label="$f['seller_bic']" :value="$v('seller_bic')" placeholder="ALFABY2X" hint="8 или 11 символов." class="max-w-xs" />
        <x-input name="seller_director" :label="$f['seller_director']" :value="$v('seller_director')" placeholder="Директор Иванов Иван Иванович" />
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-1 text-lg font-semibold text-ink">Подвал сайта</legend>
        <x-textarea name="footer_about" :label="$f['footer_about']" :value="$v('footer_about')" rows="3" hint="Две-три строки; подробно — страница «О компании»." />
        <x-textarea name="footer_delivery" :label="$f['footer_delivery']" :value="$v('footer_delivery')" rows="3" hint="Кратко; подробно — страница «Доставка и оплата»." />
    </fieldset>

    <fieldset class="space-y-4" data-settings-seo>
        <legend class="mb-1 text-lg font-semibold text-ink">SEO</legend>
        <p class="text-sm text-ink-muted">
            Заголовок и описание страницы в Google и Яндексе. Пустое поле — текст по умолчанию.
            У раздела и товара можно задать свои на их странице в админке — они важнее шаблона.
            В шаблонах: <code>{name}</code> — название, <code>{price}</code> — цена («от …» у раздела),
            <code>{category}</code> — раздел товара, <code>{city}</code> — город, <code>{site}</code> — название магазина,
            <code>{count}</code> — число товаров раздела.
        </p>
        @php($defaults = \App\Support\SeoTemplates::DEFAULTS)
        <x-input name="seo_home_title" :label="$f['seo_home_title']" :value="$v('seo_home_title')" :placeholder="app(\App\Support\Seo::class)->homeTitle()" maxlength="200" />
        <x-textarea name="seo_home_description" :label="$f['seo_home_description']" :value="$v('seo_home_description')" rows="2" :placeholder="config('seo.description')" hint="150–160 символов: что продаёте, где, чем лучше." />
        <x-input name="seo_category_title" :label="$f['seo_category_title']" :value="$v('seo_category_title')" :placeholder="$defaults['seo_category_title']" />
        <x-textarea name="seo_category_description" :label="$f['seo_category_description']" :value="$v('seo_category_description')" rows="2" :placeholder="$defaults['seo_category_description']" />
        <x-input name="seo_product_title" :label="$f['seo_product_title']" :value="$v('seo_product_title')" :placeholder="$defaults['seo_product_title']" />
        <x-textarea name="seo_product_description" :label="$f['seo_product_description']" :value="$v('seo_product_description')" rows="2" :placeholder="$defaults['seo_product_description']" />
        <x-input name="seo_og_image" :label="$f['seo_og_image']" :value="$v('seo_og_image')" :placeholder="config('seo.og_image')" hint="1200×630, JPG. Показывается, когда ссылку на главную или страницу без фото отправляют в Viber, Telegram, соцсети." />
        <x-input name="google_site_verification" :label="$f['google_site_verification']" :value="$v('google_site_verification')" hint="Search Console → Добавить ресурс → «Тег HTML»: можно вставить весь тег, сохранится код." />
        <x-input name="yandex_verification" :label="$f['yandex_verification']" :value="$v('yandex_verification')" hint="Яндекс Вебмастер → Подтверждение прав → «Мета-тег»." />
        <p class="text-sm text-ink-muted">
            Карта сайта — <a href="/sitemap.xml" class="text-primary hover:underline" target="_blank">/sitemap.xml</a>,
            правила для роботов — <a href="/robots.txt" class="text-primary hover:underline" target="_blank">/robots.txt</a>
            (обновляются сами). Карту сайта добавьте в Search Console и Вебмастер.
        </p>
    </fieldset>

    <fieldset class="space-y-4" data-settings-analytics>
        <legend class="mb-1 text-lg font-semibold text-ink">Аналитика и реклама</legend>
        <p class="text-sm text-ink-muted">
            Заполненный ID — счётчик подключается на все страницы сайта (кроме админки и визитов администраторов),
            события магазина отправляются сами: просмотр товара и раздела, корзина, оформление, покупка, заявки, квизы,
            клики по телефону и мессенджерам. Если подключён Tag Manager, Google Analytics и Google Ads настраиваются в нём
            (события — в dataLayer), поля GA4 и Ads тогда можно не заполнять.
        </p>
        <x-input name="analytics_gtm_id" :label="$f['analytics_gtm_id']" :value="$v('analytics_gtm_id')" placeholder="GTM-XXXXXXX" class="max-w-xs" />
        <x-input name="analytics_ga4_id" :label="$f['analytics_ga4_id']" :value="$v('analytics_ga4_id')" placeholder="G-XXXXXXXXXX" class="max-w-xs" hint="GA4 → Администратор → Потоки данных. Отчёты «Страницы входа», «Время вовлечения», «Пути» — там же." />
        <x-input name="analytics_ads_id" :label="$f['analytics_ads_id']" :value="$v('analytics_ads_id')" placeholder="AW-123456789" class="max-w-xs" />
        <x-input name="analytics_ads_purchase_label" :label="$f['analytics_ads_purchase_label']" :value="$v('analytics_ads_purchase_label')" hint="Google Ads → Цели → Конверсии → «Покупка» → Тег: часть после «/» в send_to." />
        <x-input name="analytics_ads_lead_label" :label="$f['analytics_ads_lead_label']" :value="$v('analytics_ads_lead_label')" hint="Конверсия «Заявка»: формы «Узнать цену», «Заказать услугу», «Обратный звонок», квизы." />
        <x-input name="analytics_ym_id" :label="$f['analytics_ym_id']" :value="$v('analytics_ym_id')" placeholder="12345678" class="max-w-xs" hint="С Вебвизором: запись визитов — куда кликали, где ушли." />
        <x-input name="analytics_clarity_id" :label="$f['analytics_clarity_id']" :value="$v('analytics_clarity_id')" placeholder="abcdef1234" class="max-w-xs" hint="Бесплатные записи сессий и тепловые карты." />
        <x-select name="analytics_consent" :label="$f['analytics_consent']" :options="\App\Models\Setting::CONSENT_MODES" :value="$v('analytics_consent') ?: 'notice'" />
    </fieldset>

    <x-button>Сохранить</x-button>
</form>

@endsection
