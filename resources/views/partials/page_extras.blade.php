{{--
    Квизы и коды сторонних сервисов для текущей страницы (App\Services\PageTargets):
    position = inline — блоки квизов под контентом; popup — кнопки и окна квизов; body — коды перед </body>.
    Коды в <head> выводит layout (SiteScript::for('head')).
--}}
@php($quizzes = \App\Models\Quiz::forPage())

@if($position === 'inline')
    @foreach($quizzes->filter->inline() as $quiz)
        <section class="mt-10" aria-label="{{ $quiz->title }}" data-quiz-inline>
            <x-quiz :quiz="$quiz" />
        </section>
    @endforeach
@elseif($position === 'popup')
    @php($popups = $quizzes->filter->popup()->values())
    @foreach($popups as $n => $quiz)
        <div x-data="{ open: false }" @keydown.escape.window="open = false" data-quiz-popup="{{ $quiz->id }}">
            <button type="button" @click="open = true" class="fixed left-4 z-40 inline-flex items-center gap-2 rounded-full bg-accent px-5 py-3 font-semibold text-accent-fg shadow-lg hover:bg-accent-hover" style="bottom: {{ 16 + $n * 64 }}px">
                @svg('heroicon-o-calculator', 'h-5 w-5') {{ $quiz->button_label ?: $quiz->title }}
            </button>
            <div x-show="open" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="open = false" role="dialog" aria-modal="true" aria-label="{{ $quiz->title }}">
                <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-card bg-surface p-card shadow-xl" x-trap.noscroll="open">
                    <button type="button" class="absolute right-3 top-3 rounded-full p-2 text-ink-muted hover:bg-canvas" @click="open = false" aria-label="Закрыть">@svg('heroicon-o-x-mark', 'h-5 w-5')</button>
                    <x-quiz :quiz="$quiz" compact />
                </div>
            </div>
        </div>
    @endforeach
@else
    @foreach(\App\Models\SiteScript::for('body') as $script)
        {!! $script->code !!}
    @endforeach
@endif
