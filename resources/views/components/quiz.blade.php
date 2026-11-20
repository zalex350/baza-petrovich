{{--
    Квиз на витрине (resources/js/quiz.js): прогресс, шаг-вопрос (варианты кнопками, несколько — галочками,
    число или текст), «Назад» / «Далее», последний шаг — имя, телефон, комментарий, согласие → заявка.
    <x-quiz :quiz="$quiz" />            — блок на странице
    <x-quiz :quiz="$quiz" compact />    — во всплывающем окне (без рамки)
--}}
@props(['quiz', 'compact' => false])

<div
    x-data="quiz(@js(['id' => $quiz->id, 'url' => route('quiz.submit', $quiz->id), 'steps' => $quiz->steps]))"
    {{ $attributes->class(['rounded-card border border-line bg-surface p-card' => ! $compact]) }}
    data-quiz="{{ $quiz->id }}"
    data-csrf="{{ csrf_token() }}"
>
    <template x-if="done">
        <div class="flex flex-col items-center gap-3 py-8 text-center" data-quiz-done>
            @svg('heroicon-o-check-circle', 'h-12 w-12 text-success')
            <h3 class="text-xl font-bold" x-text="done.title"></h3>
            <p class="max-w-md text-ink-muted" x-text="done.message"></p>
        </div>
    </template>

    <div x-show="! done">
        <div class="mb-4">
            <h3 class="text-xl font-bold tracking-tight">{{ $quiz->title }}</h3>
            @if($quiz->intro)
                <p class="mt-1 text-ink-muted">{{ $quiz->intro }}</p>
            @endif
            <div class="mt-3 flex items-center gap-3">
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-line-soft"><div class="h-full rounded-full bg-accent transition-all" :style="`width: ${progress}%`"></div></div>
                <span class="text-meta tabular-nums text-ink-muted" x-text="`${Math.min(step + 1, total)} / ${total}`"></span>
            </div>
        </div>

        @foreach($quiz->steps as $i => $s)
            <div x-show="step === {{ $i }}" x-cloak data-quiz-step="{{ $i }}">
                <p class="mb-3 text-lg font-semibold">{{ $s['question'] }}@if($s['required'])<span class="text-danger"> *</span>@endif</p>
                @if($s['hint'] ?? null)<p class="mb-3 -mt-2 text-sm text-ink-muted">{{ $s['hint'] }}</p>@endif

                @if($s['type'] === 'single')
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($s['options'] as $opt)
                            <button type="button" @click="pick(@js($opt))" class="rounded-control border px-4 py-3 text-left font-medium hover:border-primary"
                                :class="answers[{{ $i }}] === @js($opt) ? 'border-primary bg-accent-soft' : 'border-line-strong'">{{ $opt }}</button>
                        @endforeach
                    </div>
                @elseif($s['type'] === 'multi')
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($s['options'] as $opt)
                            <label class="flex cursor-pointer items-center gap-3 rounded-control border border-line-strong px-4 py-3 has-[:checked]:border-primary has-[:checked]:bg-accent-soft">
                                <input type="checkbox" value="{{ $opt }}" x-model="answers[{{ $i }}]" class="h-5 w-5 rounded border-line-strong text-primary focus:ring-primary">
                                <span class="font-medium">{{ $opt }}</span>
                            </label>
                        @endforeach
                    </div>
                @elseif($s['type'] === 'number')
                    <input type="number" min="0" step="any" inputmode="decimal" x-model="answers[{{ $i }}]" @keydown.enter.prevent="next()" class="w-full max-w-xs rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="{{ $s['question'] }}">
                @else
                    <textarea rows="3" maxlength="500" x-model="answers[{{ $i }}]" class="w-full rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="{{ $s['question'] }}"></textarea>
                @endif
            </div>
        @endforeach

        <div x-show="onContacts" x-cloak class="grid gap-3 sm:grid-cols-2" data-quiz-contacts>
            <p class="text-lg font-semibold sm:col-span-2">Куда прислать расчёт?</p>
            <input type="text" x-model="name" placeholder="Имя" autocomplete="name" maxlength="255" class="rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Имя">
            <input type="tel" x-model="phone" placeholder="+375 29 123-45-67" autocomplete="tel" maxlength="50" class="rounded-control border-line-strong focus:border-primary focus:ring-primary" aria-label="Телефон">
            <textarea x-model="comment" rows="2" maxlength="2000" placeholder="Комментарий (необязательно)" class="rounded-control border-line-strong focus:border-primary focus:ring-primary sm:col-span-2" aria-label="Комментарий"></textarea>
            <label class="flex items-start gap-2 text-sm sm:col-span-2">
                <input type="checkbox" x-model="consent" class="mt-0.5 h-4 w-4 rounded border-line-strong text-primary focus:ring-primary">
                <span>Согласен на обработку персональных данных для ответа на заявку</span>
            </label>
            <x-legal-note class="sm:col-span-2" />
        </div>

        <p class="mt-3 text-sm text-tone-danger-text" x-show="error" x-text="error" role="alert"></p>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <x-button variant="secondary" type="button" x-show="step > 0" @click="back()">Назад</x-button>
            <x-button type="button" x-show="! onContacts" @click="next()">Далее</x-button>
            <x-button type="button" x-show="onContacts" x-bind:disabled="sending" @click="submit()" data-quiz-submit>{{ $quiz->button_label ?: 'Получить расчёт' }}</x-button>
        </div>
    </div>
</div>
