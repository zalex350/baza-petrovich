{{--
    Блок «Документы» на странице товара в админке (SRS §4.8). Применяется сразу, без черновика.
    Загрузка PDF до 20 МБ с типом и названием (пусто — из имени файла); у каждого документа —
    название и тип (сохранить), удаление с подтверждением; порядок — перетаскиванием
    (sortable-list.js), без JS — порядок загрузки.
    Данные: $product (с documents).
--}}
@php
    $documents = $product->documents;
    $types = collect(\App\Enums\ProductDocumentType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()]);
    $maxMb = intdiv(\App\Models\ProductDocument::MAX_KB, 1024);
@endphp

<div
    id="documents"
    class="bg-white rounded-2xl border border-slate-200 p-6 mb-6 scroll-mt-6"
    x-data="sortableList('/admin/product/{{ $product->id }}/documents/reorder', 'documentId')"
    data-csrf="{{ csrf_token() }}"
    data-admin-documents
>

    <h2 class="text-lg font-semibold mb-1">
        Документы
        <span class="text-sm font-normal text-slate-400 ml-2">данные сайта · применяются сразу</span>
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        Инструкции, паспорта, сертификаты — PDF до {{ $maxMb }} МБ. В карточке товара на витрине — блок «Документы»
        (нет документов — блок скрыт); файл открывается по ссылке.
    </p>

    {{-- Загрузка --}}
    <form method="POST" action="/admin/product/{{ $product->id }}/documents" enctype="multipart/form-data" class="mb-6 grid gap-4 rounded-card border border-line bg-field p-4 md:grid-cols-2" data-document-upload>
        @csrf
        <x-file name="document" label="Файл PDF" accept="application/pdf" required hint="До {{ $maxMb }} МБ." class="md:col-span-2" />
        <x-select name="type" label="Тип" :options="$types" :value="\App\Enums\ProductDocumentType::Manual->value" required />
        <x-input name="title" label="Название" maxlength="255" hint="Пусто — из имени файла." />
        <div class="md:col-span-2">
            <x-button>Загрузить документ</x-button>
        </div>
    </form>

    @if($documents->isEmpty())

        <p class="text-slate-500" data-documents-empty>Документов нет — в карточке товара блок не показывается.</p>

    @else

        <p class="text-sm text-slate-500 mb-2">
            Порядок в карточке — перетаскиванием за <span aria-hidden="true">⠿</span>.
            <span x-show="status" x-text="status" :class="statusError ? 'text-red-600' : 'text-green-700'" class="ml-2" role="status"></span>
        </p>

        <ul x-ref="list" class="divide-y divide-slate-200 border border-slate-200 rounded-xl" data-documents-list>
            @foreach($documents as $document)
                <li class="flex flex-wrap items-center gap-3 p-3 bg-white" data-document-id="{{ $document->id }}">
                    <span class="cursor-move select-none text-slate-400 text-xl px-1" data-drag-handle title="Перетащите, чтобы изменить порядок" aria-hidden="true">⠿</span>

                    @svg('heroicon-o-'.$document->type->icon(), 'h-6 w-6 shrink-0 text-danger')

                    <form method="POST" action="/admin/product/{{ $product->id }}/documents/{{ $document->id }}" class="flex min-w-0 grow flex-wrap items-center gap-2">
                        @csrf
                        <input
                            type="text"
                            name="title"
                            value="{{ $document->title }}"
                            required
                            maxlength="255"
                            aria-label="Название документа"
                            class="min-w-48 grow rounded-control border-line-strong px-control-x py-control-y text-sm focus:border-primary focus:ring-primary"
                        >
                        <select name="type" aria-label="Тип документа" class="rounded-control border-line-strong py-control-y pl-control-x text-sm focus:border-primary focus:ring-primary">
                            @foreach($types as $value => $label)
                                <option value="{{ $value }}" @selected($document->type->value === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-button variant="secondary" size="sm">Сохранить</x-button>
                    </form>

                    <span class="text-meta text-ink-muted whitespace-nowrap">
                        <a href="{{ $document->url() }}" target="_blank" rel="noopener" class="text-link hover:underline" title="{{ $document->original_name }}">Открыть</a>
                        · {{ $document->sizeLabel() }}
                    </span>

                    <x-confirm
                        :action="'/admin/product/'.$product->id.'/documents/'.$document->id.'/delete'"
                        label="Удалить"
                        title="Удалить документ?"
                        confirm-label="Удалить"
                        variant="danger"
                        trigger-variant="link"
                        trigger-size="sm"
                    >
                        Документ «{{ $document->title }}» будет удалён из карточки товара вместе с файлом.
                    </x-confirm>
                </li>
            @endforeach
        </ul>

    @endif

</div>
