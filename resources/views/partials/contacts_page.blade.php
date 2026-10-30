{{--
    Страница «Контакты» (pages/show, slug contacts): под текстом из редактора — блоки
    «Контакты» и «Реквизиты» из «Настроек» (App\Models\Setting). Пустые контакты не выводятся,
    пустые реквизиты — [ЗАПОЛНИТЬ].
--}}
@php
    $phones = \App\Models\Setting::phones();
    $email = \App\Models\Setting::value('contact_email');
    $hours = \App\Models\Setting::value('contact_hours');
    $pickup = \App\Models\Setting::value('contact_pickup_address');
@endphp

@if($phones || $email || $hours || $pickup)
    <section class="mt-8" data-contacts-info>
        <h2 class="mb-3 text-xl font-semibold text-ink">Как с нами связаться</h2>
        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
            @foreach($phones as $phone)
                <dt class="text-ink-muted">{{ $phone['label'] ?: 'Телефон' }}</dt>
                <dd class="sm:col-span-2"><a href="tel:{{ $phone['tel'] }}" class="text-link hover:underline">{{ $phone['number'] }}</a></dd>
            @endforeach
            @if($email)
                <dt class="text-ink-muted">Email</dt>
                <dd class="sm:col-span-2"><a href="mailto:{{ $email }}" class="text-link hover:underline">{{ $email }}</a></dd>
            @endif
            @if($hours)
                <dt class="text-ink-muted">Режим работы</dt>
                <dd class="sm:col-span-2">{{ $hours }}</dd>
            @endif
            @if($pickup)
                <dt class="text-ink-muted">Склад / самовывоз</dt>
                <dd class="whitespace-pre-line sm:col-span-2">{{ $pickup }}</dd>
            @endif
        </dl>
    </section>
@endif

<section class="mt-8" data-requisites>
    <h2 class="mb-3 text-xl font-semibold text-ink">Реквизиты</h2>
    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
        @foreach(\App\Models\Setting::REQUISITES as $key)
            <dt class="text-ink-muted">{{ \App\Models\Setting::FIELDS[$key] }}</dt>
            <dd class="whitespace-pre-line break-words sm:col-span-2">{{ \App\Models\Setting::get($key) }}</dd>
        @endforeach
    </dl>
</section>
