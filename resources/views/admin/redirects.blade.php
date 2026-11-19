{{--
    Редиректы 301 (AdminRedirectController): свои и автоматические (смена адреса товара, раздела, страницы).
    Адреса старого сайта разбираются сами (App\Support\LegacyUrls) — их проверка в форме «Проверить адрес».
--}}
@extends('layouts.admin')

@section('title', 'Редиректы')

@section('flash-validation', 'summary')

@section('content')

<h1 class="text-3xl font-bold mb-2">Редиректы</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Если страницы по адресу нет, сайт ведёт посетителя (и поисковик) на новый адрес с кодом 301.
    Адреса старого сайта — каталог, товары, услуги, статьи, «Наши работы» — разбираются сами, записывать их не нужно.
    Сюда попадают сменённые адреса товаров, разделов и страниц (автоматически) и ваши правила.
</p>

<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <form method="POST" action="/admin/redirects" class="space-y-3 rounded-card border border-line bg-surface p-card" data-redirect-form>
        @csrf
        <h2 class="font-semibold">Добавить редирект</h2>
        <x-input name="from" label="Старый адрес" placeholder="/akcii/leto-2024" hint="Путь или полный адрес — домен и «/» в конце не важны." />
        <x-input name="to" label="Куда вести" placeholder="/promo" hint="Путь от «/» или полный адрес https://…" />
        <x-button>Сохранить</x-button>
    </form>

    <form method="GET" action="/admin/redirects/check" class="space-y-3 rounded-card border border-line bg-surface p-card" data-redirect-check>
        <h2 class="font-semibold">Проверить адрес</h2>
        <x-input name="url" label="Адрес" placeholder="https://baza-petrovich.by/catalog/profnastil/profnastil-dlya-zabora/" :value="old('url')" hint="Куда попадёт посетитель, если такой страницы нет." />
        <x-button variant="secondary">Проверить</x-button>
    </form>
</div>

<form method="GET" action="/admin/redirects" class="mb-4 flex flex-wrap items-end gap-3">
    <x-input name="q" label="Поиск" :value="$q" placeholder="адрес" class="w-72" />
    <x-button variant="secondary">Найти</x-button>
</form>

@if($redirects->isEmpty())
    <x-empty-state title="Редиректов нет" icon="arrow-uturn-right">Появятся, когда вы смените адрес товара, раздела или страницы.</x-empty-state>
@else
    <x-table stack caption="Редиректы">
        <x-slot:head>
            <x-table.th>Старый адрес</x-table.th>
            <x-table.th>Новый</x-table.th>
            <x-table.th>Откуда</x-table.th>
            <x-table.th>Переходов</x-table.th>
            <x-table.th><span class="sr-only">Действия</span></x-table.th>
        </x-slot:head>
        @foreach($redirects as $redirect)
            <tr data-redirect-row="{{ $redirect->id }}">
                <x-table.td label="Старый адрес" class="break-all">/{{ $redirect->from_path }}</x-table.td>
                <x-table.td label="Новый" class="break-all"><a href="{{ $redirect->to_url }}" target="_blank" rel="noopener" class="text-link hover:underline">{{ $redirect->to_url }}</a></x-table.td>
                <x-table.td label="Откуда" class="text-ink-muted">{{ $redirect->note ?: '—' }}</x-table.td>
                <x-table.td label="Переходов" nowrap>{{ $redirect->hits }}@if($redirect->last_hit_at) <span class="text-meta text-ink-muted">· {{ date_ru($redirect->last_hit_at) }}</span>@endif</x-table.td>
                <x-table.td>
                    <x-confirm :action="'/admin/redirect/'.$redirect->id.'/delete'" label="Удалить" title="Удалить редирект?" confirm-label="Удалить" variant="danger" trigger-variant="link">
                        /{{ $redirect->from_path }} перестанет вести на {{ $redirect->to_url }}.
                    </x-confirm>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
    <div class="mt-4"><x-pagination :paginator="$redirects" /></div>
@endif

@endsection
