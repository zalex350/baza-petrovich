@extends('layouts.admin')

@section('title', $banner->exists ? 'Баннер' : 'Новый баннер')

{{-- Ошибки — у полей, сверху — один alert «Проверьте выделенные поля» --}}
@section('flash-validation', 'summary')

@section('content')

<a href="/admin/banners" class="text-sm text-ink-muted hover:text-link">← Все баннеры</a>

<h1 class="text-3xl font-bold mt-4 mb-6">{{ $banner->exists ? 'Баннер' : 'Новый баннер' }}</h1>

<form
    method="POST"
    action="{{ $banner->exists ? '/admin/banner/'.$banner->id : '/admin/banner' }}"
    enctype="multipart/form-data"
    class="max-w-3xl space-y-5 rounded-card border border-line bg-surface p-card"
    data-banner-form
>
    @csrf

    <x-input name="title" label="Название" :value="$banner->title" hint="Для админки и подписи картинки (alt)." maxlength="255" />

    <x-file
        name="image_desktop"
        label="Картинка для десктопа"
        :required="! $banner->exists"
        accept="image/jpeg,image/png,image/webp"
        :preview="$banner->exists ? $banner->desktopUrl() : null"
        hint="Пропорции 33:10 (например, 2640×800). JPG, PNG или WebP, сохраняется в WebP."
    />

    <x-file
        name="image_mobile"
        label="Картинка для телефона"
        accept="image/jpeg,image/png,image/webp"
        :preview="$banner->image_mobile ? $banner->mobileUrl() : null"
        hint="Пропорции 3:2 (например, 1200×800). Нет — на телефоне показывается картинка для десктопа."
    />

    @if($banner->image_mobile)
        <x-checkbox name="remove_mobile" label="Удалить картинку для телефона" :checked="false" />
    @endif

    <x-input name="url" label="Ссылка" :value="$banner->url" placeholder="/promo или https://…" hint="Пусто — баннер без ссылки." maxlength="500" />

    <x-input name="sort_order" type="number" label="Порядок" :value="$banner->sort_order" min="0" required hint="Меньше — раньше в слайдере." class="max-w-40" />

    <x-checkbox name="is_active" label="Показывать на главной" :checked="$banner->is_active" />

    <div class="flex gap-3">
        <x-button>{{ $banner->exists ? 'Сохранить' : 'Добавить баннер' }}</x-button>
        <x-button variant="secondary" href="/admin/banners">Отмена</x-button>
    </div>
</form>

@endsection
