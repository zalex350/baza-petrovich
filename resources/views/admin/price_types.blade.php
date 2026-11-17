{{--
    Типы цен (AdminPriceTypeController@index). Данные: $types — PriceType с prices_count (товаров с ценой)
    и organizations_count (клиентов с этой «Применяемой ценой»).
    Карточка типа — форма: название, порядок, «Скрытый»; «Сделать по умолчанию» (x-confirm: пересчёт цен).
    Внизу — новый тип вручную.
--}}
@extends('layouts.admin')

@section('title', 'Типы цен')
@section('flash-validation', 'summary')

@section('content')

<h1 class="mb-2 text-3xl font-bold">Типы цен</h1>

<div class="mb-6 max-w-3xl space-y-2 text-sm text-ink-muted">
    <p>
        Цены приходят из 1С колонками <code>price_&lt;код&gt;</code> файла цен и остатков. Клиенту действует его «Применяемая цена»
        (карточка клиента); не задана — цена <strong class="text-ink">по умолчанию</strong>. Нет цены этого типа у товара — цена по умолчанию,
        нет и её — «Цена уточняется». Название типа видно только в админке.
    </p>
    <p>
        <strong class="text-ink">Скрытый</strong> тип клиентам не применяется (у них — цена по умолчанию). Новый код из 1С создаёт тип скрытым —
        проверьте цены и снимите «Скрытый».
    </p>
</div>

@error('default')<x-alert type="error" class="mb-4">{{ $message }}</x-alert>@enderror
@error('is_hidden')<x-alert type="error" class="mb-4">{{ $message }}</x-alert>@enderror

<div class="mb-8 grid gap-4 lg:grid-cols-2">
    @foreach($types as $type)
        <section class="rounded-card border border-line bg-surface p-card" data-price-type="{{ $type->code }}">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold">{{ $type->name }}</h2>
                <code class="text-sm text-ink-muted">price_{{ $type->code }}</code>
                @if($type->is_default)<x-badge tone="success" data-default-badge>по умолчанию</x-badge>@endif
                @if($type->is_hidden)<x-badge tone="warning" data-hidden-badge>скрыт</x-badge>@endif
            </div>
            <p class="mb-4 text-sm text-ink-muted">
                Товаров с ценой: {{ $type->prices_count }} · клиентов: {{ $type->organizations_count }}
            </p>

            <form method="POST" action="/admin/price-type/{{ $type->id }}" class="space-y-3">
                @csrf
                <div class="flex flex-wrap items-end gap-3">
                    <x-input name="name" :id="'name-'.$type->id" label="Название" :value="$type->name" maxlength="100" required class="grow" />
                    <x-input name="sort_order" :id="'sort-'.$type->id" type="number" label="Порядок" :value="$type->sort_order" min="0" max="9999" class="w-28" />
                </div>
                @if($type->is_default)
                    <p class="text-sm text-ink-muted">Тип по умолчанию не скрывается.</p>
                @else
                    <x-checkbox name="is_hidden" :id="'hidden-'.$type->id" label="Скрытый — не применять к клиентам" :checked="$type->is_hidden" />
                @endif
                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" size="sm">Сохранить</x-button>
                    @unless($type->is_default || $type->is_hidden)
                        <x-confirm :action="'/admin/price-type/'.$type->id.'/default'" label="Сделать по умолчанию" :title="'Сделать «'.$type->name.'» ценой по умолчанию?'"
                                   confirm-label="Сделать по умолчанию" variant="primary" trigger-variant="link" trigger-size="sm">
                            Клиенты без своей «Применяемой цены» и все, у кого нет цены своего типа, увидят цены «{{ $type->name }}».
                            Товары без цены этого типа станут «Цена уточняется» для таких клиентов.
                        </x-confirm>
                    @endunless
                </div>
            </form>
        </section>
    @endforeach
</div>

<section class="max-w-xl rounded-card border border-line bg-surface p-card" data-price-type-new>
    <h2 class="mb-3 text-lg font-semibold">Новый тип цены</h2>
    <form method="POST" action="/admin/price-types" class="space-y-3">
        @csrf
        <div class="flex flex-wrap items-start gap-3">
            <x-input name="code" id="new-code" label="Код" hint="Как в колонке price_<код> файла 1С" maxlength="30" required class="w-48" />
            <x-input name="name" id="new-name" label="Название" maxlength="100" required class="grow" />
        </div>
        <x-checkbox name="is_hidden" id="new-hidden" label="Скрытый — не применять к клиентам" :checked="true" />
        <x-button>Добавить</x-button>
    </form>
</section>

@endsection
