{{-- Поле поиска с подсказками; $id различает desktop/mobile экземпляры --}}
<div class="relative flex-1 min-w-0" data-search-suggest data-suggest-url="{{ route('search.suggest') }}">

    <form action="{{ route('search.index') }}" method="GET" role="search" class="flex items-center">

        <label for="search-{{ $id }}" class="sr-only">Поиск по каталогу</label>

        <input
            id="search-{{ $id }}"
            type="search"
            name="q"
            value="{{ request('q') }}"
            placeholder="Поиск по названию или артикулу"
            autocomplete="off"
            class="w-full pl-4 pr-11 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            data-search-input
        >

        <button type="submit" class="-ml-10 w-10 h-9 flex items-center justify-center text-slate-500 hover:text-blue-600" aria-label="Найти">
            @svg('heroicon-o-magnifying-glass', 'w-5 h-5')
        </button>

    </form>

    <div class="absolute top-full left-0 right-0 mt-1 lg:left-1/2 lg:right-auto lg:-translate-x-1/2 lg:w-[min(56rem,88vw)] bg-white rounded-xl border border-slate-200 shadow-lg hidden z-50 max-h-96 overflow-y-auto" data-search-suggestions></div>

</div>
