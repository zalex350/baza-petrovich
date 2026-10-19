{{--
    Выбор фото с очередью до загрузки (image-queue.js). Без собственной формы — вставляется
    в форму загрузки фото или в форму товара. Файлы уходят в поле images[].
    Параметры: $required (bool, по умолчанию false).
--}}
@php
    $maxKb = (int) config('shop.images.max_kb');
    $types = config('shop.images.mimetypes');
    $maxFiles = \App\Http\Controllers\Admin\AdminProductImageController::MAX_FILES;
@endphp

<div
    x-data="imageQueue(@js(['max' => $maxFiles, 'maxKb' => $maxKb, 'types' => $types]))"
    data-image-queue
>

    <input
        type="file"
        name="images[]"
        multiple
        accept="{{ implode(',', $types) }}"
        @if($required ?? false) required @endif
        class="text-sm"
        x-ref="input"
        @change="add($event)"
    >

    <ul x-show="errors.length" x-cloak class="mt-3 list-disc list-inside text-red-700 text-sm space-y-1" role="alert">
        <template x-for="message in errors" :key="message">
            <li x-text="message"></li>
        </template>
    </ul>

    {{-- Очередь: выбранные, но ещё не загруженные файлы --}}
    <div x-show="count" x-cloak class="mt-4">

        <div class="flex items-center gap-3 text-sm text-slate-600 mb-2">
            <span>В очереди: <span x-text="count"></span> из {{ $maxFiles }}</span>
            <button type="button" @click="clear()" class="text-slate-500 underline hover:text-slate-800">
                Очистить очередь
            </button>
        </div>

        <ul class="grid grid-cols-3 sm:grid-cols-5 lg:grid-cols-8 gap-3" data-image-queue-list>

            <template x-for="item in queue" :key="item.id">

                <li class="relative text-xs text-slate-500">
                    <img :src="item.url" :alt="item.name" class="w-full aspect-square object-contain bg-slate-50 rounded-lg border border-slate-200">
                    <button
                        type="button"
                        @click="remove(item.id)"
                        class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-white border border-slate-300 text-slate-600 hover:text-red-600 hover:border-red-300 shadow-sm leading-none text-lg"
                        :aria-label="'Убрать из очереди: ' + item.name"
                        title="Убрать из очереди"
                    >×</button>
                    <div class="mt-1 truncate" x-text="item.name + ' · ' + item.size"></div>
                </li>

            </template>

        </ul>

    </div>

</div>
