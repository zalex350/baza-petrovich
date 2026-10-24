{{-- Поле поиска с подсказками; $id различает desktop/mobile экземпляры --}}
<div class="relative flex-1 min-w-0" data-search-suggest data-suggest-url="{{ route('search.suggest') }}">

    <form action="{{ route('search.index') }}" method="GET" role="search" class="flex items-center">

        <label for="search-{{ $id }}" class="sr-only">Поиск по каталогу</label>

        <input
            id="search-{{ $id }}"
            type="search"
            name="q"
            value="{{ request('q') }}"
            placeholder="Артикул или название"
            autocomplete="off"
            class="w-full h-10 pl-4 pr-11 bg-canvas border border-line rounded-control text-sm text-ink placeholder:text-ink-muted focus:bg-surface focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary"
            data-search-input
        >

        <button type="submit" class="-ml-10 w-10 h-10 flex items-center justify-center text-ink-muted hover:text-link" aria-label="Найти">
            @svg('heroicon-o-magnifying-glass', 'w-5 h-5')
        </button>

    </form>

    <div class="absolute top-full left-0 right-0 mt-1 lg:left-1/2 lg:right-auto lg:-translate-x-1/2 lg:w-[min(56rem,88vw)] bg-surface rounded-panel border border-line shadow-lg hidden z-50 max-h-96 overflow-y-auto" data-search-suggestions></div>

</div>
