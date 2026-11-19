@extends('layouts.admin')

@section('title', $review->exists ? 'Отзыв' : 'Новый отзыв')

@section('flash-validation', 'summary')

@section('content')

<a href="/admin/reviews" class="text-sm text-ink-muted hover:text-link">← Все отзывы</a>

<h1 class="mt-4 mb-6 text-3xl font-bold">{{ $review->exists ? 'Отзыв' : 'Новый отзыв' }}</h1>

<form method="POST" action="{{ $review->exists ? '/admin/review/'.$review->id : '/admin/review' }}" enctype="multipart/form-data" class="max-w-3xl space-y-5 rounded-card border border-line bg-surface p-card" data-review-form>
    @csrf

    <div class="grid gap-4 sm:grid-cols-2">
        <x-input name="author" label="Автор" :value="$review->author" maxlength="255" placeholder="Андрей, д. Прудищи" />
        <x-input name="group" label="Группа" :value="$review->group" maxlength="100" list="review-groups" hint="Например: Заборы «под ключ»" />
    </div>
    <datalist id="review-groups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist>

    <x-textarea name="text" label="Текст отзыва" rows="5" maxlength="5000" :value="$review->text" />

    <x-file name="image" label="Фото / скан отзыва" accept="image/jpeg,image/png,image/webp" :preview="$review->imageUrl()" />
    @if($review->imageUrl())
        <x-checkbox name="remove_image" label="Убрать фото" :unchecked="false" />
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <x-select name="rating" label="Оценка" placeholder="Без оценки" :options="[5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★']" :value="$review->rating" />
        <x-input name="reviewed_on" type="date" label="Дата" :value="$review->reviewed_on?->format('Y-m-d')" />
        <x-input name="sort_order" type="number" label="Порядок" :value="$review->sort_order" hint="Меньше — выше" />
    </div>

    <x-select name="work_id" label="Объект «Наши работы»" placeholder="Не связан" :options="$works" :value="$review->work_id" />

    <x-checkbox name="is_published" label="Опубликован" :checked="$review->is_published" />

    <div class="flex flex-wrap gap-3">
        <x-button>{{ $review->exists ? 'Сохранить' : 'Добавить' }}</x-button>
        <x-button variant="secondary" href="/admin/reviews">Отмена</x-button>
    </div>
</form>

@if($review->exists)
    <div class="mt-6">
        <x-confirm :action="'/admin/review/'.$review->id.'/delete'" label="Удалить отзыв" title="Удалить отзыв?" confirm-label="Удалить" trigger-variant="link" trigger-size="sm" class="text-danger" />
    </div>
@endif

@endsection
