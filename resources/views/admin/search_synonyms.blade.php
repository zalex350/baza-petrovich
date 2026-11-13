{{--
    Синонимы поиска (AdminSearchSynonymController@index).
    Данные: $synonyms — правила (SearchSynonym), $check — проверяемый запрос, $result — null или
    [slots: SearchQuery::describe(), total, products (до 10), ms].
    Правило строкой: «смеситель, кран» — группа, «мойка → раковина» — замена в одну сторону.
    Правка — в строке таблицы (поле lines[id]), удаление — x-confirm.
--}}
@extends('layouts.admin')

@section('title', 'Синонимы поиска')
@section('flash-validation', 'summary')

@section('content')

<h1 class="text-3xl font-bold mb-2">Синонимы поиска</h1>

<div class="text-sm text-ink-muted mb-6 max-w-3xl space-y-1">
    <p>
        Поиск на сайте понимает формы слов: «смесители для кухни» и «смеситель кухня» находят одно и то же.
        Синонимы добавляют слова, которые покупатели пишут иначе, чем в названиях товаров.
    </p>
    <p>
        <b>Группа</b> — слова через запятую, равнозначны в обе стороны: <code>смеситель, кран</code>.
        <b>Замена в одну сторону</b> — через стрелку: <code>мойка → раковина</code> (по слову «мойка» найдутся и раковины,
        по «раковине» мойки — нет). Можно писать <code>-&gt;</code>. Слово может быть фразой: <code>вентиль, шаровой кран</code>.
        Форма слова не важна. Изменения действуют в поиске сразу.
    </p>
</div>

{{-- Проверка запроса --}}
<section class="rounded-card border border-line bg-surface p-card mb-6" aria-labelledby="check-title" data-synonym-check>
    <h2 id="check-title" class="text-lg font-semibold mb-3">Проверить запрос</h2>

    <form method="GET" action="/admin/search-synonyms" class="flex flex-wrap items-end gap-2">
        <x-input name="check" type="search" label="Запрос" :value="$check" placeholder="например, кран кухня" class="grow max-w-md" />
        <x-button>Проверить</x-button>
        @if($check !== '')
            <x-button variant="link" href="/admin/search-synonyms">Сбросить</x-button>
        @endif
    </form>

    @if($result !== null)
        <div class="mt-4 space-y-3" data-check-result>
            @if($result['slots'] === [])
                <p class="text-sm text-ink-muted">В запросе нет слов для поиска (только служебные слова или знаки).</p>
            @else
                <ul class="text-sm space-y-1">
                    @foreach($result['slots'] as $slot)
                        <li data-check-slot>
                            <b>{{ $slot['words'] }}</b>
                            <span class="text-ink-muted">→ основа «{{ $slot['stems'] }}»</span>
                            @if($slot['synonyms'] !== [])
                                <span>или {{ collect($slot['synonyms'])->map(fn ($s) => "«{$s}»")->implode(', ') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="text-sm">
                Нашлось товаров на витрине: <b data-check-total>{{ $result['total'] }}</b>
                <span class="text-ink-muted">({{ $result['ms'] }} мс)</span>
            </p>

            @if($result['products']->isNotEmpty())
                <ol class="text-sm list-decimal pl-5 space-y-0.5">
                    @foreach($result['products'] as $product)
                        <li>
                            <a href="/admin/product/{{ $product->id }}/edit" class="text-link hover:underline">{{ $product->title() }}</a>
                            <span class="text-ink-muted">{{ $product->displayArticle() }}</span>
                        </li>
                    @endforeach
                </ol>
                @if($result['total'] > $result['products']->count())
                    <p class="text-meta text-ink-muted">Первые {{ $result['products']->count() }} — в порядке выдачи поиска.</p>
                @endif
            @endif
        </div>
    @endif
</section>

{{-- Добавление --}}
<form method="POST" action="/admin/search-synonyms" class="mb-6 flex flex-wrap items-end gap-2" data-synonym-add>
    @csrf
    <x-input name="line" label="Новое правило" placeholder="смеситель, кран  или  мойка → раковина" class="grow max-w-xl" />
    <x-button>Добавить</x-button>
</form>

@if($synonyms->isEmpty())
    <x-empty-state title="Синонимов нет" icon="language">
        Добавьте первое правило — например, «смеситель, кран».
    </x-empty-state>
@else
    <x-table caption="Синонимы поиска">
        <x-slot:head>
            <x-table.th sticky="start">Правило</x-table.th>
            <x-table.th>Вид</x-table.th>
            <x-table.th sticky="end">Действия</x-table.th>
        </x-slot:head>

        @foreach($synonyms as $synonym)
            <tr data-synonym-row="{{ $synonym->id }}">
                <x-table.td sticky="start">
                    <form method="POST" action="/admin/search-synonym/{{ $synonym->id }}" id="synonym-{{ $synonym->id }}" class="min-w-64">
                        @csrf
                        <x-input name="lines[{{ $synonym->id }}]" :value="$synonym->line()" aria-label="Правило" />
                    </form>
                </x-table.td>
                <x-table.td nowrap>
                    @if($synonym->isOneWay())
                        <x-badge tone="info">в одну сторону</x-badge>
                    @else
                        <x-badge>группа</x-badge>
                    @endif
                </x-table.td>
                <x-table.td sticky="end" nowrap>
                    <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-3">
                        <x-button size="sm" variant="secondary" form="synonym-{{ $synonym->id }}">Сохранить</x-button>
                        <x-confirm
                            action="/admin/search-synonym/{{ $synonym->id }}/delete"
                            label="Удалить"
                            title="Удалить синонимы?"
                            confirm-label="Удалить"
                            trigger-variant="link"
                            trigger-size="sm"
                        >
                            Правило «{{ $synonym->line() }}» перестанет действовать в поиске.
                        </x-confirm>
                    </div>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
