{{--
    Текст с кнопкой «копировать» (артикул в карточке товара).
    <x-copy :text="$product->sku" label="Скопировать артикул" />
    Копирование — copy-text.js: navigator.clipboard, запасной вариант — выделение текста и
    execCommand('copy'); подпись «скопировано» на 1,5 с. class — на обёртку, текст — в слоте
    (по умолчанию — сам text).
--}}
@props(['text', 'label' => 'Скопировать'])

<span x-data="copyText(@js((string) $text))" {{ $attributes }} data-copy>
    <span x-ref="text" class="font-medium text-ink">{{ $slot->isEmpty() ? $text : $slot }}</span>
    <button
        type="button"
        class="px-1 text-link hover:underline"
        @click="copy()"
        x-text="label || 'копировать'"
        aria-label="{{ $label }}"
    >копировать</button>
    <span class="sr-only" role="status" x-text="label"></span>
</span>
