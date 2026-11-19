@extends('layouts.admin')

@section('title', 'Квизы')

@section('content')

<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-3xl font-bold">Квизы</h1>
    <x-button href="/admin/quiz/create">Создать квиз</x-button>
</div>

<p class="mb-6 max-w-3xl text-sm text-ink-muted">
    Свои квизы и калькуляторы: вопросы по шагам, в конце — имя и телефон. Ответы приходят в «Заявки с сайта»,
    на почту, в Telegram и Битрикс24. Квиз стороннего сервиса (MoClients, Marquiz…) — в «Коды и виджеты».
</p>

@if($quizzes->isEmpty())
    <x-empty-state title="Квизов пока нет" icon="calculator" />
@else
    <x-table stack="md" caption="Квизы">
        <x-slot:head>
            <x-table.th>Квиз</x-table.th>
            <x-table.th>Вид</x-table.th>
            <x-table.th align="right">Вопросов</x-table.th>
            <x-table.th align="right">Заявок</x-table.th>
            <x-table.th>Статус</x-table.th>
        </x-slot:head>
        @foreach($quizzes as $quiz)
            <tr data-quiz-row="{{ $quiz->id }}">
                <x-table.td label="Квиз"><a href="/admin/quiz/{{ $quiz->id }}" class="font-medium text-link hover:underline">{{ $quiz->title }}</a></x-table.td>
                <x-table.td label="Вид">{{ \App\Models\Quiz::DISPLAY[$quiz->display] ?? $quiz->display }}</x-table.td>
                <x-table.td label="Вопросов" align="right">{{ count($quiz->steps) }}</x-table.td>
                <x-table.td label="Заявок" align="right"><a href="/admin/leads?type=quiz" class="text-link hover:underline">{{ $counts[$quiz->id] ?? 0 }}</a></x-table.td>
                <x-table.td label="Статус"><x-badge :tone="$quiz->is_active ? 'success' : 'neutral'">{{ $quiz->is_active ? 'включён' : 'выключен' }}</x-badge></x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
