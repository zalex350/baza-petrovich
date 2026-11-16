{{--
    Плитка раздела каталога (главная, /catalog): фото типичного товара (CategoryTiles — своя картинка,
    выбранный товар или самый популярный с фото и в наличии), без фото — иконка категории, без неё —
    пустое поле той же высоты. Название, число товаров; слот — список подразделов (/catalog).
    <x-category-tile :node="$node" :tile="$tiles[$node['id']] ?? null" />   — узел CatalogMenu::tree() (icon, icon_svg, name, url, count),
                                                                           tile — URL фото (CategoryTiles::forTree) или null
    <x-category-tile :node="$node" :tile="…">…подразделы…</x-category-tile>
--}}
@props(['node', 'tile' => null])

<article {{ $attributes->class('flex h-full min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface') }} data-category-tile="{{ $node['id'] }}">
    <a href="{{ $node['url'] }}" class="group flex flex-col gap-3 p-4 hover:text-link">
        <span class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-control bg-media">
            @if($tile)
                <img src="{{ $tile }}" alt="" loading="lazy" class="h-full w-full object-contain p-3 transition group-hover:scale-105" data-tile-photo>
            @else
                <x-category-icon :icon="$node['icon'] ?? null" :svg="$node['icon_svg'] ?? null" class="h-24 w-24" />
            @endif
        </span>
        <span class="flex items-baseline justify-between gap-2">
            <span class="text-base font-semibold leading-tight text-ink group-hover:text-link">{{ $node['name'] }}</span>
            <span class="shrink-0 text-meta text-ink-muted tabular-nums">{{ $node['count'] }}</span>
        </span>
    </a>

    @if(trim((string) $slot) !== '')
        <div class="border-t border-line px-4 py-3">{{ $slot }}</div>
    @endif
</article>
