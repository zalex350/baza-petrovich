{{--
    Пагинация (макет docs/design/Catalog): «Назад», номера страниц (текущая — тёмная), «Далее».
    <x-pagination :paginator="$products" />
    paginator — LengthAwarePaginator (параметры запроса — через ->withQueryString() у вызывающего).
    Одна страница — ничего не выводится. Номера: первая, последняя и по side соседних с текущей,
    пропуски — «…». На телефоне вместо номеров — «N из M».
    Каталог перехватывает ссылки внутри [data-pagination] (catalog-filters.js) — обёртку ставит вызывающий.
--}}
@props(['paginator', 'side' => 1])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // Номера: 1, …, current−side…current+side, …, last
    $pages = collect([1, $last])
        ->merge(range(max(1, $current - $side), min($last, $current + $side)))
        ->unique()->sort()->values();

    $items = [];
    foreach ($pages as $i => $page) {
        if ($i > 0 && $page - $pages[$i - 1] > 1) {
            $items[] = null; // пропуск
        }
        $items[] = $page;
    }

    $box = 'inline-flex h-10 min-w-10 items-center justify-center rounded-control px-3 tabular-nums';
    $idle = $box.' border border-line-strong bg-surface text-ink hover:border-primary hover:text-primary';
@endphp

@if($last > 1)
    <nav aria-label="Страницы" {{ $attributes->class('flex flex-wrap items-center justify-center gap-1.5') }} data-ui-pagination>
        @if($paginator->onFirstPage())
            <span class="{{ $idle }} pointer-events-none opacity-40" aria-disabled="true">Назад</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="{{ $idle }}" rel="prev">Назад</a>
        @endif

        {{-- Телефон: «N из M» --}}
        <span class="px-2 text-sm text-ink-muted tabular-nums sm:hidden">{{ $current }} из {{ $last }}</span>

        @foreach($items as $page)
            @if($page === null)
                <span class="hidden px-1 text-ink-muted sm:inline" aria-hidden="true">…</span>
            @elseif($page === $current)
                <span class="{{ $box }} hidden bg-primary font-semibold text-white sm:inline-flex" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="{{ $idle }} hidden sm:inline-flex" aria-label="Страница {{ $page }}">{{ $page }}</a>
            @endif
        @endforeach

        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="{{ $idle }}" rel="next">Далее</a>
        @else
            <span class="{{ $idle }} pointer-events-none opacity-40" aria-disabled="true">Далее</span>
        @endif
    </nav>
@endif
