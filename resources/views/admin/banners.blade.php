@extends('layouts.admin')

@section('title', 'Баннеры')

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <h1 class="text-3xl font-bold">Баннеры</h1>
    <x-button href="/admin/banner/create">Добавить баннер</x-button>
</div>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    Слайдер на главной: включённые баннеры по порядку, автопрокрутка с паузой при наведении.
    Нет включённых — слайдер не показывается.
</p>

@if($banners->isEmpty())
    <x-empty-state title="Баннеров пока нет" icon="photo">
        <x-slot:action>
            <x-button href="/admin/banner/create">Добавить баннер</x-button>
        </x-slot:action>
    </x-empty-state>
@else
    <x-table stack="md" caption="Баннеры">
        <x-slot:head>
            <x-table.th>Картинка</x-table.th>
            <x-table.th>Название и ссылка</x-table.th>
            <x-table.th align="right">Порядок</x-table.th>
            <x-table.th>Статус</x-table.th>
            <x-table.th><span class="sr-only">Действия</span></x-table.th>
        </x-slot:head>

        @foreach($banners as $banner)
            <tr data-banner-row="{{ $banner->id }}">
                <x-table.td label="Картинка">
                    <img src="{{ $banner->desktopUrl() }}" alt="" class="h-14 w-40 rounded-control border border-line bg-media object-cover">
                </x-table.td>
                <x-table.td label="Название и ссылка">
                    <span class="block font-medium text-ink">{{ $banner->title ?: 'Без названия' }}</span>
                    <span class="block text-meta text-ink-muted">{{ $banner->url ?: 'без ссылки' }}</span>
                    @unless($banner->image_mobile)
                        <span class="block text-meta text-ink-muted">на телефоне — картинка для десктопа</span>
                    @endunless
                </x-table.td>
                <x-table.td label="Порядок" align="right">{{ $banner->sort_order }}</x-table.td>
                <x-table.td label="Статус">
                    <x-badge :tone="$banner->is_active ? 'success' : 'neutral'">{{ $banner->is_active ? 'Включён' : 'Выключен' }}</x-badge>
                </x-table.td>
                <x-table.td>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-button variant="secondary" size="sm" :href="'/admin/banner/'.$banner->id.'/edit'">Изменить</x-button>
                        <form method="POST" action="/admin/banner/{{ $banner->id }}/toggle">
                            @csrf
                            <x-button variant="secondary" size="sm">{{ $banner->is_active ? 'Выключить' : 'Включить' }}</x-button>
                        </form>
                        <x-confirm
                            :action="'/admin/banner/'.$banner->id.'/delete'"
                            label="Удалить"
                            title="Удалить баннер?"
                            confirm-label="Удалить"
                            variant="danger"
                            trigger-variant="link"
                            trigger-size="sm"
                        >
                            Баннер «{{ $banner->title ?: 'Без названия' }}» и его картинки будут удалены.
                        </x-confirm>
                    </div>
                </x-table.td>
            </tr>
        @endforeach
    </x-table>
@endif

@endsection
