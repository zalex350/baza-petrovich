@extends('layouts.admin')

@section('title', 'Коды и виджеты')

@section('content')

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Коды и виджеты</h1>
    <x-button href="/admin/site-script/create">Добавить код</x-button>
</div>

<p class="mb-6 max-w-3xl text-sm text-ink-muted">
    Код сторонних сервисов: квизы MoClients и Marquiz, чат JivoSite, обратный звонок, Яндекс.Метрика, Google Tag Manager, пиксели.
    Вставляется как есть — добавляйте только код из личного кабинета сервиса.
</p>

@if($scripts->isEmpty())
    <x-empty-state title="Кодов пока нет" icon="code-bracket" />
@else
    <x-table stack="md" caption="Коды и виджеты">
        <x-slot:head>
            <x-table.th>Название</x-table.th>
            <x-table.th>Место</x-table.th>
            <x-table.th>Страницы</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>
        @foreach($scripts as $script)
            <tr data-script-row="{{ $script->id }}">
                <x-table.td label="Название"><a href="/admin/site-script/{{ $script->id }}" class="font-medium text-link hover:underline">{{ $script->name }}</a></x-table.td>
                <x-table.td label="Место">{{ $script->position === 'head' ? '<head>' : 'перед </body>' }}</x-table.td>
                <x-table.td label="Страницы">{{ in_array('all', $script->targets ?? [], true) ? 'все' : count($script->targets ?? []).' мест' }}</x-table.td>
                <x-table.td label="Статус"><x-badge :tone="$script->is_active ? 'success' : 'neutral'">{{ $script->is_active ? 'включён' : 'выключен' }}</x-badge></x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
