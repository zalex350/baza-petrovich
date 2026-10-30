{{--
    Подвал витрины: три колонки — о компании, доставка (кратко + ссылка), контакты;
    ниже реквизиты продавца (кратко: Setting::FOOTER_REQUISITES). Всё — «Настройки» в админке
    (App\Models\Setting): пустые тексты и реквизиты — [ЗАПОЛНИТЬ], пустые контакты не выводятся.
--}}
@php
    $phones = \App\Models\Setting::phones();
    $email = \App\Models\Setting::value('contact_email');
    $hours = \App\Models\Setting::value('contact_hours');
@endphp

<footer class="mt-10 border-t border-line bg-surface text-sm" data-footer>

    <div class="max-w-page mx-auto grid gap-8 px-4 py-8 sm:grid-cols-2 lg:grid-cols-3 lg:px-6">

        <section data-footer-about>
            <h2 class="mb-2 text-base font-semibold text-ink">О компании</h2>
            <p class="whitespace-pre-line text-ink-muted">{{ \App\Models\Setting::get('footer_about') }}</p>
            <a href="/about" class="mt-2 inline-block text-link hover:underline">Подробнее</a>
        </section>

        <section data-footer-delivery>
            <h2 class="mb-2 text-base font-semibold text-ink">Доставка и оплата</h2>
            <p class="whitespace-pre-line text-ink-muted">{{ \App\Models\Setting::get('footer_delivery') }}</p>
            <a href="/delivery" class="mt-2 inline-block text-link hover:underline">Условия доставки и оплаты</a>
        </section>

        <section data-footer-contacts>
            <h2 class="mb-2 text-base font-semibold text-ink">Контакты</h2>
            <ul class="space-y-1.5 text-ink-muted">
                @foreach($phones as $phone)
                    <li class="flex items-center gap-2">
                        @svg('heroicon-o-phone', 'h-4 w-4 shrink-0')
                        <a href="tel:{{ $phone['tel'] }}" class="hover:text-link">{{ $phone['number'] }}</a>
                        @if($phone['label'])
                            <span class="text-meta">{{ $phone['label'] }}</span>
                        @endif
                    </li>
                @endforeach
                @if($email)
                    <li class="flex items-center gap-2">
                        @svg('heroicon-o-envelope', 'h-4 w-4 shrink-0')
                        <a href="mailto:{{ $email }}" class="hover:text-link">{{ $email }}</a>
                    </li>
                @endif
                @if($hours)
                    <li class="flex items-center gap-2">
                        @svg('heroicon-o-clock', 'h-4 w-4 shrink-0')
                        {{ $hours }}
                    </li>
                @endif
            </ul>
            <a href="/contacts" class="mt-2 inline-block text-link hover:underline">Все контакты</a>
        </section>

    </div>

    {{-- Реквизиты продавца (кратко) --}}
    <div class="border-t border-line-soft">
        <dl class="max-w-page mx-auto grid gap-x-6 gap-y-1 px-4 py-4 text-meta text-ink-muted sm:grid-cols-2 lg:px-6" data-seller>
            @foreach(\App\Models\Setting::FOOTER_REQUISITES as $key => $label)
                <div class="flex gap-1.5">
                    <dt class="shrink-0">{{ $label }}:</dt>
                    <dd>{{ \App\Models\Setting::get($key) }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="max-w-page mx-auto px-4 pb-4 text-meta text-ink-muted lg:px-6">© {{ date('Y') }} {{ config('shop.name') }}</p>
    </div>

</footer>
