@extends('layouts.admin')

@section('title', $script->exists ? $script->name : 'Новый код')

@section('flash-validation', 'summary')

@section('content')

<a href="/admin/site-scripts" class="text-sm text-ink-muted hover:text-link">← Все коды</a>

<h1 class="mt-4 mb-6 text-3xl font-bold">{{ $script->exists ? $script->name : 'Новый код' }}</h1>

<form method="POST" action="{{ $script->exists ? '/admin/site-script/'.$script->id : '/admin/site-script' }}" class="max-w-5xl space-y-5 rounded-card border border-line bg-surface p-card" data-script-form>
    @csrf
    <x-input name="name" label="Название" :value="$script->name" required maxlength="255" placeholder="Квиз MoClients — заборы" />
    <x-textarea name="code" label="Код" rows="10" required class="font-mono" :value="$script->code" placeholder="<script src=&quot;https://moclients.com/js/10640.js&quot;></script>" />
    <div class="grid gap-4 sm:grid-cols-2">
        <x-select name="position" label="Куда вставить" :options="\App\Models\SiteScript::POSITIONS" :value="$script->position" />
        <x-input name="sort_order" type="number" label="Порядок" :value="$script->sort_order" />
    </div>
    <x-targets-picker name="targets" :value="$script->targets ?? []" />
    <x-checkbox name="is_active" label="Включён" :checked="$script->is_active" />
    <div class="flex flex-wrap gap-3">
        <x-button>{{ $script->exists ? 'Сохранить' : 'Добавить' }}</x-button>
        <x-button variant="secondary" href="/admin/site-scripts">Отмена</x-button>
    </div>
</form>

@if($script->exists)
    <div class="mt-6">
        <x-confirm :action="'/admin/site-script/'.$script->id.'/delete'" label="Удалить код" title="Удалить код «{{ $script->name }}»?" confirm-label="Удалить" trigger-variant="link" trigger-size="sm" class="text-danger" />
    </div>
@endif

@endsection
