@extends('layouts.admin')

@section('title', 'Загрузка кодов — '.$organization->name)

@section('flash-validation', 'summary')

@section('content')

{{--
    Предпросмотр загрузки кодов товаров клиента (ClientCodeImport::preview): база ещё не менялась.
    «Применить» — в транзакции, с повторной проверкой по текущей базе. Списки длиннее $limit строк
    обрезаются (в отчёте после применения — полные числа).
--}}
@php
    $base = '/admin/organization/'.$organization->id;
    $limit = 300;
    $replace = $preview['mode'] === \App\Services\ClientCodeImport::MODE_REPLACE;
    $sections = [
        'add' => count($preview['add']),
        'update' => count($preview['update']),
        'delete' => count($preview['delete']),
    ];
@endphp

<a href="{{ $base }}?tab=codes" class="text-sm text-ink-muted hover:text-link">← Коды товаров клиента</a>

<h1 class="text-3xl font-bold mt-4 mb-2">Загрузка кодов: предпросмотр</h1>
<p class="text-ink-muted mb-6">
    Клиент «{{ $organization->name }}», файл «{{ $file }}», режим «{{ $modes[$preview['mode']] }}». Строк с данными: {{ $preview['total'] }}.
    Изменения ещё не сохранены.
</p>

<div class="mb-6 rounded-card border border-line bg-surface p-card" data-import-summary>
    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-5">
        <div><dt class="text-ink-muted">Добавить</dt><dd class="text-lg font-semibold" data-count-add>{{ $sections['add'] }}</dd></div>
        <div><dt class="text-ink-muted">Обновить</dt><dd class="text-lg font-semibold" data-count-update>{{ $sections['update'] }}</dd></div>
        <div><dt class="text-ink-muted">Удалить</dt><dd class="text-lg font-semibold" data-count-delete>{{ $sections['delete'] }}</dd></div>
        <div><dt class="text-ink-muted">Без изменений</dt><dd class="text-lg font-semibold">{{ $preview['unchanged'] }}</dd></div>
        <div><dt class="text-ink-muted">Ошибки</dt><dd @class(['text-lg font-semibold', 'text-tone-danger-text' => $preview['errors']]) data-count-errors>{{ count($preview['errors']) }}</dd></div>
    </dl>

    @if($replace && $preview['errors'])
        <x-alert type="error" class="mt-4" data-replace-blocked>
            В режиме «Заменить полностью» файл должен быть без ошибок: иначе коды товаров из ошибочных строк были бы удалены.
            Исправьте строки и загрузите файл снова — или выберите режим «Добавить и обновить».
        </x-alert>
    @elseif($preview['errors'])
        <x-alert type="warning" class="mt-4">
            Строки с ошибками будут пропущены — коды этих товаров не изменятся.
        </x-alert>
    @endif

    @if(! $preview['can_apply'] && ! ($replace && $preview['errors']))
        <x-alert type="info" class="mt-4">Применять нечего: коды в файле совпадают с текущими.</x-alert>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-3">
        @if($preview['can_apply'])
            @if($sections['delete'] > 0)
                <x-confirm :action="$base.'/codes/import/apply'" label="Применить" title="Применить загрузку?" confirm-label="Применить" variant="primary">
                    Будет добавлено {{ $sections['add'] }}, обновлено {{ $sections['update'] }} и удалено {{ $sections['delete'] }} кодов клиента «{{ $organization->name }}».
                </x-confirm>
            @else
                <form method="POST" action="{{ $base }}/codes/import/apply">
                    @csrf
                    <x-button data-import-apply>Применить</x-button>
                </form>
            @endif
        @endif

        <form method="POST" action="{{ $base }}/codes/import/cancel">
            @csrf
            <x-button variant="secondary">Отменить и загрузить другой файл</x-button>
        </form>
    </div>
</div>

@if($preview['errors'])
    <section class="mb-6 rounded-card border border-line bg-surface p-card" data-section="errors">
        <h2 class="text-lg font-semibold mb-4">Ошибки <span class="text-sm font-normal text-ink-muted ml-2">{{ count($preview['errors']) }}</span></h2>
        @include('partials.admin_client_code_errors', ['rowErrors' => array_slice($preview['errors'], 0, $limit)])
        @if(count($preview['errors']) > $limit)
            <p class="mt-2 text-sm text-ink-muted">И ещё {{ count($preview['errors']) - $limit }}.</p>
        @endif
    </section>
@endif

@if($preview['add'])
    <section class="mb-6 rounded-card border border-line bg-surface p-card" data-section="add">
        <h2 class="text-lg font-semibold mb-4">Добавить <span class="text-sm font-normal text-ink-muted ml-2">{{ $sections['add'] }}</span></h2>
        <x-table stack caption="Новые коды">
            <x-slot:head>
                <x-table.th>Строка</x-table.th>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Название</x-table.th>
                <x-table.th>Код клиента</x-table.th>
            </x-slot:head>
            @foreach(array_slice($preview['add'], 0, $limit) as $row)
                <tr>
                    <x-table.td label="Строка" nowrap>{{ $row['row'] }}</x-table.td>
                    <x-table.td label="Артикул" class="font-mono">{{ $row['product']->displayArticle() }}@if($row['product']->brand) <span class="font-sans text-ink-muted">· {{ $row['product']->brand }}</span>@endif</x-table.td>
                    <x-table.td label="Название">{{ $row['product']->title() }}</x-table.td>
                    <x-table.td label="Код клиента" class="font-mono">{{ $row['code'] }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
        @if($sections['add'] > $limit)
            <p class="mt-2 text-sm text-ink-muted">И ещё {{ $sections['add'] - $limit }}.</p>
        @endif
    </section>
@endif

@if($preview['update'])
    <section class="mb-6 rounded-card border border-line bg-surface p-card" data-section="update">
        <h2 class="text-lg font-semibold mb-4">Обновить <span class="text-sm font-normal text-ink-muted ml-2">{{ $sections['update'] }}</span></h2>
        <x-table stack caption="Изменённые коды">
            <x-slot:head>
                <x-table.th>Строка</x-table.th>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Название</x-table.th>
                <x-table.th>Было</x-table.th>
                <x-table.th>Стало</x-table.th>
            </x-slot:head>
            @foreach(array_slice($preview['update'], 0, $limit) as $row)
                <tr>
                    <x-table.td label="Строка" nowrap>{{ $row['row'] }}</x-table.td>
                    <x-table.td label="Артикул" class="font-mono">{{ $row['product']->displayArticle() }}@if($row['product']->brand) <span class="font-sans text-ink-muted">· {{ $row['product']->brand }}</span>@endif</x-table.td>
                    <x-table.td label="Название">{{ $row['product']->title() }}</x-table.td>
                    <x-table.td label="Было" class="font-mono text-ink-muted line-through">{{ $row['old'] }}</x-table.td>
                    <x-table.td label="Стало" class="font-mono">{{ $row['code'] }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
        @if($sections['update'] > $limit)
            <p class="mt-2 text-sm text-ink-muted">И ещё {{ $sections['update'] - $limit }}.</p>
        @endif
    </section>
@endif

@if($preview['delete'])
    <section class="mb-6 rounded-card border border-line bg-surface p-card" data-section="delete">
        <h2 class="text-lg font-semibold mb-1">Удалить <span class="text-sm font-normal text-ink-muted ml-2">{{ $sections['delete'] }}</span></h2>
        <p class="text-sm text-ink-muted mb-4">Режим «Заменить полностью»: этих товаров нет в файле.</p>
        <x-table stack caption="Удаляемые коды">
            <x-slot:head>
                <x-table.th>Артикул</x-table.th>
                <x-table.th>Название</x-table.th>
                <x-table.th>Код клиента</x-table.th>
            </x-slot:head>
            @foreach(array_slice($preview['delete'], 0, $limit) as $code)
                <tr>
                    <x-table.td label="Артикул" class="font-mono">{{ $code->product?->displayArticle() ?? '—' }}@if($code->product?->brand) <span class="font-sans text-ink-muted">· {{ $code->product->brand }}</span>@endif</x-table.td>
                    <x-table.td label="Название">{{ $code->product?->name ?? 'Товар удалён' }}</x-table.td>
                    <x-table.td label="Код клиента" class="font-mono">{{ $code->code }}</x-table.td>
                </tr>
            @endforeach
        </x-table>
        @if($sections['delete'] > $limit)
            <p class="mt-2 text-sm text-ink-muted">И ещё {{ $sections['delete'] - $limit }}.</p>
        @endif
    </section>
@endif

@endsection
