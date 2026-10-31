{{--
    Вкладки-ссылки: каждая вкладка — свой адрес (обычно ?tab=…), страница целиком с сервера.
    <x-tabs label="Разделы клиента" :active="$tab" :tabs="[
        'main'  => ['label' => 'Клиент', 'url' => '/admin/organization/5'],
        'codes' => ['label' => 'Коды товаров', 'url' => '/admin/organization/5?tab=codes', 'count' => 12],
    ]" />
    active — ключ текущей вкладки (aria-current="page"); count — число рядом с подписью (необязательно);
    label — aria-label навигации.
--}}
@props(['tabs', 'active', 'label' => 'Вкладки'])

<nav aria-label="{{ $label }}" {{ $attributes->class('mb-6 border-b border-line') }} data-tabs>
    <ul class="-mb-px flex flex-wrap gap-x-6">
        @foreach($tabs as $key => $tab)
            @php $current = (string) $key === (string) $active; @endphp
            <li>
                <a
                    href="{{ $tab['url'] }}"
                    @class([
                        'inline-flex items-center gap-2 border-b-2 py-3 text-sm font-semibold transition',
                        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                        'border-primary text-ink' => $current,
                        'border-transparent text-ink-muted hover:border-line-strong hover:text-ink' => ! $current,
                    ])
                    @if($current) aria-current="page" @endif
                    data-tab="{{ $key }}"
                >
                    {{ $tab['label'] }}
                    @isset($tab['count'])
                        <span class="rounded-full bg-tone-neutral-bg px-2 text-meta text-tone-neutral-text">{{ $tab['count'] }}</span>
                    @endisset
                </a>
            </li>
        @endforeach
    </ul>
</nav>
