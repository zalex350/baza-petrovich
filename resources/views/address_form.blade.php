@extends('layouts.account')

@section('title', $address->exists ? 'Редактирование адреса' : 'Новый адрес доставки')

@section('account')

<a
    href="/addresses"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    ← Назад к адресам доставки
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    {{ $address->exists ? 'Редактирование адреса' : 'Новый адрес доставки' }}
</h1>

<div class="bg-white rounded-2xl border border-slate-200 p-6 max-w-2xl">

    <form
        method="POST"
        action="{{ $address->exists ? '/addresses/'.$address->id : '/addresses' }}"
        class="space-y-6"
    >

        @csrf

        @include('partials.address_fields', ['address' => $address])

        <button
            type="submit"
            class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-xl transition"
        >
            {{ $address->exists ? 'Сохранить' : 'Добавить адрес' }}
        </button>

    </form>

</div>

@endsection
