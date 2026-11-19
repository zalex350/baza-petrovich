@extends('layouts.admin')

@section('title', $quiz->exists ? $quiz->title : 'Новый квиз')

@section('flash-validation', 'summary')

@section('content')

<a href="/admin/quizzes" class="text-sm text-ink-muted hover:text-link">← Все квизы</a>

<h1 class="mt-4 mb-6 text-3xl font-bold">{{ $quiz->exists ? $quiz->title : 'Новый квиз' }}</h1>

<form method="POST" action="{{ $quiz->exists ? '/admin/quiz/'.$quiz->id : '/admin/quiz' }}" class="max-w-5xl space-y-6" x-data="quizEditor(@js($quiz->steps ?? []))" data-quiz-form>
    @csrf
    <input type="hidden" name="steps" :value="json" value="{{ json_encode($quiz->steps ?? [], JSON_UNESCAPED_UNICODE) }}">

    <section class="space-y-4 rounded-card border border-line bg-surface p-card">
        <x-input name="title" label="Заголовок" :value="$quiz->title" required maxlength="255" placeholder="Рассчитайте стоимость забора за 1 минуту" />
        <x-textarea name="intro" label="Подзаголовок" rows="2" maxlength="1000" :value="$quiz->intro" placeholder="Ответьте на 4 вопроса — пришлём расчёт материалов и монтажа" />
        <div class="grid gap-4 sm:grid-cols-3">
            <x-select name="display" label="Как показывать" :options="\App\Models\Quiz::DISPLAY" :value="$quiz->display" />
            <x-input name="button_label" label="Текст кнопки" :value="$quiz->button_label" maxlength="100" placeholder="Рассчитать забор" hint="Кнопка отправки и плавающая кнопка" />
            <x-input name="sort_order" type="number" label="Порядок" :value="$quiz->sort_order" />
        </div>
    </section>

    <section class="space-y-4 rounded-card border border-line bg-surface p-card" data-quiz-steps>
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Вопросы</h2>
            <x-button variant="secondary" size="sm" type="button" @click="add()">+ Вопрос</x-button>
        </div>
        @error('steps')<p class="text-sm text-tone-danger-text">{{ $message }}</p>@enderror

        <template x-for="(s, i) in steps" :key="i">
            <div class="space-y-3 rounded-card border border-line-strong p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-semibold" x-text="`Шаг ${i + 1}`"></span>
                    <div class="flex gap-2 text-sm">
                        <button type="button" class="text-link hover:underline" @click="move(i, -1)" :disabled="i === 0">↑</button>
                        <button type="button" class="text-link hover:underline" @click="move(i, 1)" :disabled="i === steps.length - 1">↓</button>
                        <button type="button" class="text-danger hover:underline" @click="remove(i)">Удалить</button>
                    </div>
                </div>
                <input type="text" x-model="s.question" placeholder="Вопрос: Какой забор нужен?" maxlength="300" class="w-full rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Вопрос">
                <div class="grid gap-3 sm:grid-cols-[14rem_minmax(0,1fr)]">
                    <select x-model="s.type" class="rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Тип ответа">
                        <template x-for="(label, key) in types" :key="key"><option :value="key" x-text="label" :selected="s.type === key"></option></template>
                    </select>
                    <input type="text" x-model="s.hint" placeholder="Подсказка (необязательно)" maxlength="300" class="rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Подсказка">
                </div>
                <div x-show="s.type === 'single' || s.type === 'multi'">
                    <textarea x-model="s.optionsText" rows="4" placeholder="Варианты — каждый с новой строки&#10;Профнастил&#10;Металлоштакетник&#10;Сетка-рабица" class="w-full rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Варианты ответа"></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="s.required" class="h-4 w-4 rounded border-line-strong text-primary focus:ring-primary"> Обязательный вопрос</label>
            </div>
        </template>
        <p class="text-meta text-ink-muted">Последний шаг — имя, телефон, комментарий и согласие — добавляется сам.</p>
    </section>

    <section class="space-y-4 rounded-card border border-line bg-surface p-card">
        <x-input name="result_title" label="Заголовок после отправки" :value="$quiz->result_title" maxlength="255" placeholder="Спасибо! Расчёт уже готовится" />
        <x-textarea name="result_text" label="Текст после отправки" rows="2" maxlength="1000" :value="$quiz->result_text" placeholder="Менеджер перезвонит в течение 15 минут в рабочее время." />
    </section>

    <section class="rounded-card border border-line bg-surface p-card">
        <x-targets-picker name="targets" :value="$quiz->targets ?? []" />
    </section>

    <x-checkbox name="is_active" label="Включён" :checked="$quiz->is_active" />

    <div class="flex flex-wrap gap-3">
        <x-button>{{ $quiz->exists ? 'Сохранить' : 'Создать квиз' }}</x-button>
        <x-button variant="secondary" href="/admin/quizzes">Отмена</x-button>
    </div>
</form>

@if($quiz->exists)
    <section class="mt-8 max-w-5xl">
        <h2 class="mb-3 text-lg font-semibold">Как видит покупатель</h2>
        <x-quiz :quiz="$quiz" />
    </section>
    <div class="mt-6">
        <x-confirm :action="'/admin/quiz/'.$quiz->id.'/delete'" label="Удалить квиз" title="Удалить квиз «{{ $quiz->title }}»? Заявки останутся." confirm-label="Удалить" trigger-variant="link" trigger-size="sm" class="text-danger" />
    </div>
@endif

@endsection
