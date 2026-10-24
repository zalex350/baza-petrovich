{{--
    Хлебные крошки.
    <x-breadcrumbs :items="[['label' => 'Каталог', 'url' => '/catalog'], ['label' => 'Смесители', 'url' => '/catalog/mixers'], ['label' => $product->name]]" />
    items: [['label' => …, 'url' => …], …]; последний пункт без url — текущая страница (aria-current),
    с url — ссылка (карточка товара: последней стоит категория). Пункт без url в середине — просто текст.
    Пустой список — ничего не выводится.
--}}
@props(['items' => []])

@php
    $items = array_values($items);
    $last = count($items) - 1;
@endphp

@if($items)
    <nav aria-label="Хлебные крошки" {{ $attributes->class('min-w-0 text-sm text-ink-muted') }} data-breadcrumbs>
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @foreach($items as $i => $item)
                <li class="flex min-w-0 items-center gap-2">
                    @if($i > 0)
                        <span class="select-none opacity-50" aria-hidden="true">/</span>
                    @endif

                    @if($i === $last && empty($item['url']))
                        <span class="truncate text-ink" aria-current="page">{{ $item['label'] }}</span>
                    @elseif(! empty($item['url']))
                        <a href="{{ $item['url'] }}" class="truncate hover:text-link hover:underline">{{ $item['label'] }}</a>
                    @else
                        <span class="truncate">{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
