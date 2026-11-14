{{--
    Выбор иконки категории (админка, форма категории): поиск по имени и словам, сетка иконок
    (Material Symbols и свои — CategoryIcons::library()), предпросмотр выбранной, «Без иконки»
    и загрузка своей SVG (поле icon_file; форма — multipart). Значение — скрытое поле icon.
    <x-category-icon-picker :category="$category" />
    Загруженная SVG у категории — вариант «upload» (своя, предпросмотр — очищенная разметка).
--}}
@props(['category'])

@php
    $icons = app(\App\Services\CategoryIcons::class);
    $value = old('icon', $category->icon);
    $uploaded = $category->icon === \App\Services\CategoryIcons::UPLOAD ? $icons->render('upload', $category->icon_svg, 'h-6 w-6') : null;
    $groups = collect($icons->library())->groupBy('set');
    $titles = ['material' => 'Material Symbols', 'custom' => 'Свои (сантехника)'];
@endphp

<fieldset
    {{ $attributes->class('space-y-3') }}
    x-data="{
        value: @js((string) $value),
        q: '',
        matches(words) { const q = this.q.trim().toLowerCase(); return q === '' || words.includes(q); },
        preview() {
            const el = this.value ? this.$root.querySelector('[data-icon-option=&quot;' + this.value + '&quot;] svg') : null;
            return el ? el.outerHTML : '';
        },
    }"
    data-icon-picker
>
    <legend class="mb-1 font-medium text-ink">Иконка</legend>
    <p class="text-sm text-ink-muted">
        Плитки на главной и в каталоге, мегаменю. Цвет — из темы сайта. Нет иконки — категория показывается без неё.
    </p>

    <input type="hidden" name="icon" :value="value" value="{{ $value }}">

    <div class="flex flex-wrap items-center gap-4">
        <span class="flex h-16 w-16 items-center justify-center rounded-control border border-line bg-media text-link [&>svg]:h-10 [&>svg]:w-10" x-html="preview()" data-icon-preview aria-hidden="true">
            @if($value && ($html = $icons->render($value, $category->icon_svg, 'h-10 w-10')))
                {{ $html }}
            @endif
        </span>
        <div class="min-w-0">
            <div class="text-sm font-medium text-ink" x-text="value || 'Без иконки'" data-icon-value>{{ $value ?: 'Без иконки' }}</div>
            <x-button type="button" variant="link" size="sm" x-on:click="value = ''" x-show="value !== ''" data-icon-clear>Без иконки</x-button>
        </div>
    </div>

    <x-input name="icon_search" type="search" label="Поиск иконки" placeholder="кран, фильтр, сифон, valve…" x-model="q" autocomplete="off" class="max-w-sm" />

    <div class="max-h-80 space-y-4 overflow-y-auto rounded-control border border-line p-3" data-icon-grid>
        @if($uploaded)
            <div>
                <div class="mb-2 text-meta font-semibold uppercase text-ink-muted">Загруженная</div>
                <button type="button" data-icon-option="upload" data-words="upload своя загруженная" title="Загруженная SVG"
                        x-show="matches($el.dataset.words)" @click="value = 'upload'"
                        :aria-pressed="(value === 'upload').toString()"
                        :class="value === 'upload' ? 'border-primary bg-tone-info-bg text-primary' : 'border-line text-ink hover:border-primary'"
                        class="flex h-11 w-11 items-center justify-center rounded-control border">
                    {{ $uploaded }}
                </button>
            </div>
        @endif

        @foreach($groups as $set => $items)
            <div>
                <div class="mb-2 text-meta font-semibold uppercase text-ink-muted">{{ $titles[$set] ?? $set }}</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($items as $icon)
                        <button type="button"
                                data-icon-option="{{ $icon['key'] }}"
                                data-words="{{ mb_strtolower($icon['name'].' '.$icon['words']) }}"
                                title="{{ $icon['name'] }} — {{ $icon['words'] }}"
                                aria-label="{{ $icon['name'] }}"
                                x-show="matches($el.dataset.words)"
                                @click="value = '{{ $icon['key'] }}'"
                                :aria-pressed="(value === '{{ $icon['key'] }}').toString()"
                                :class="value === '{{ $icon['key'] }}' ? 'border-primary bg-tone-info-bg text-primary' : 'border-line text-ink hover:border-primary'"
                                class="flex h-11 w-11 items-center justify-center rounded-control border">
                            {{ $icons->render($icon['key'], null, 'h-6 w-6') }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <x-file name="icon_file" label="Своя SVG" accept=".svg,image/svg+xml"
            hint="Вместо выбранной выше. До {{ intdiv(\App\Services\CategoryIcons::MAX_UPLOAD, 1024) }} КБ; цвета заменяются цветом темы, скрипты и ссылки удаляются" class="max-w-sm" />
</fieldset>
