{{--
    Текст с кнопкой «копировать» (артикул в карточке товара).
    <x-copy :text="$product->sku" label="Скопировать артикул" />
    field — текст в поле только для чтения во всю ширину (ссылка приглашения); подпись поля —
    x-field вокруг, id — для её label:
    <x-field id="invite-url" label="Ссылка"><x-copy field id="invite-url" :text="$url" label="Скопировать ссылку" /></x-field>
    Копирование — copy-text.js: navigator.clipboard, запасной вариант — выделение текста и
    execCommand('copy'); подпись «скопировано» на 1,5 с. class — на обёртку, текст — в слоте
    (по умолчанию — сам text).
--}}
@props(['text', 'label' => 'Скопировать', 'field' => false, 'id' => null])

<span x-data="copyText(@js((string) $text))" {{ $attributes->class(['flex items-center gap-2' => $field]) }} data-copy>
    @if($field)
        <input
            x-ref="text"
            type="text"
            readonly
            value="{{ $text }}"
            @if($id) id="{{ $id }}" @endif
            @focus="$el.select()"
            class="block w-full min-w-0 rounded-control border-line-strong bg-canvas px-control-x py-control-y text-ink focus:border-primary focus:ring-primary"
        >
    @else
        <span x-ref="text" class="font-medium text-ink">{{ $slot->isEmpty() ? $text : $slot }}</span>
    @endif
    <button
        type="button"
        class="px-1 text-link hover:underline whitespace-nowrap"
        @click="copy()"
        x-text="label || 'копировать'"
        aria-label="{{ $label }}"
    >копировать</button>
    <span class="sr-only" role="status" x-text="label"></span>
</span>
