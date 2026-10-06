@extends('layouts.app')

@section('title', 'Адреса доставки')

@section('content')

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">

    <h1 class="text-3xl font-bold">
        Адреса доставки
    </h1>

    @if($organization)

        <a
            href="/addresses/create"
            class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-xl transition"
        >
            Добавить адрес
        </a>

    @endif

</div>

@if(! $organization)

    <div class="bg-white rounded-2xl border border-slate-200 p-6">

        <p>
            Пользователь не привязан к организации — адреса доставки недоступны.
        </p>

    </div>

@else

    <p class="text-slate-600 mb-4">
        Адреса организации «{{ $organization->name }}». Адрес выбирается при оформлении заказа.
    </p>

    <div class="bg-white rounded-2xl border border-slate-200 p-6">

        @if($addresses->isEmpty())

            <p class="text-slate-500">
                Адресов пока нет. Добавьте адрес, чтобы оформить заказ.
            </p>

        @else

            @include('partials.address_list', ['addresses' => $addresses, 'baseUrl' => '/addresses'])

        @endif

    </div>

@endif

@endsection
