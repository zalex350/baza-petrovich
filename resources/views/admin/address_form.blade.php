@extends('layouts.admin')

@section('title', $address->exists ? 'Редактирование адреса' : 'Новый адрес доставки')

@section('content')

<a
    href="/admin/organization/{{ $organization->id }}"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к карточке клиента
</a>

<h1 class="text-3xl font-bold mt-4 mb-2">
    {{ $address->exists ? 'Редактирование адреса' : 'Новый адрес доставки' }}
</h1>

<p class="text-slate-600 mb-6">
    Клиент: {{ $organization->name }}
</p>

<div class="bg-white rounded-2xl border border-slate-200 p-6 max-w-2xl">

    <form
        method="POST"
        action="/admin/organization/{{ $organization->id }}/address{{ $address->exists ? '/'.$address->id : '' }}"
        class="space-y-6"
    >

        @csrf

        @include('partials.address_fields', ['address' => $address])

        <button
            type="submit"
            class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded"
        >
            {{ $address->exists ? 'Сохранить' : 'Добавить адрес' }}
        </button>

    </form>

</div>

@endsection
