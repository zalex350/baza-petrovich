{{--
    Плитка раздела каталога (главная, /catalog): фото типичного товара (CategoryTiles — своя картинка,
    выбранный товар или, в режиме «автоматически», самый продаваемый с фото и в наличии; заглушки не в счёт),
    без фото или если файл не загрузился — иконка категории, без неё —
    пустое поле той же высоты. Название, число товаров; слот — список подразделов (/catalog).
    <x-category-tile :node="$node" :tile="$tiles[$node['id']] ?? null" />   — узел CatalogMenu::tree() (icon, icon_svg, name, url, count),
                                                                           tile — URL фото (CategoryTiles::forTree) или null
    <x-category-tile :node="$node" :tile="…">…подразделы…</x-category-tile>
--}}
@props(['node', 'tile' => null])

<article {{ $attributes->class('flex h-full min-w-0 flex-col overflow-hidden rounded-card border border-line bg-surface') }} data-category-tile="{{ $node['id'] }}">
    <a href="{{ $node['url'] }}" class="group flex flex-col gap-3 p-4 hover:text-link">
        {{-- Фото — на фоне медиа; без фото — иконка ~120 px на фоне --ic-fill с прозрачностью 40 % (.bg-ic-tile, app.css) --}}
        <span @class([
            'flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-control',
            'bg-media' => $tile,
            'bg-ic-tile' => ! $tile,
        ])>
            @if($tile)
                {{-- Файл не загрузился — вместо «битой» картинки иконка раздела --}}
                <img src="{{ $tile }}" alt="" loading="lazy" class="h-full w-full object-contain p-3 transition group-hover:scale-105" data-tile-photo
                     onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                <span hidden><x-category-icon :icon="$node['icon'] ?? null" :svg="$node['icon_svg'] ?? null" class="h-[120px] w-[120px] max-h-[75%] max-w-[75%]" /></span>
            @else
                <x-category-icon :icon="$node['icon'] ?? null" :svg="$node['icon_svg'] ?? null" class="h-[120px] w-[120px] max-h-[75%] max-w-[75%]" />
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
