@extends('layouts.app')

@section('title', $organization->exists ? 'Редактирование организации' : 'Новая организация')

@section('content')

<a
    href="{{ $organization->exists ? '/admin/organization/'.$organization->id : '/admin/organizations' }}"
    class="text-sm text-slate-500 hover:text-blue-600"
>
    {{ $organization->exists ? '← Назад к карточке организации' : '← Назад к списку организаций' }}
</a>

<h1 class="text-3xl font-bold mt-4 mb-6">
    {{ $organization->exists ? 'Редактирование организации' : 'Новая организация' }}
</h1>

@if($errors->any())

    <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6">
        <ul class="list-disc list-inside text-red-700 space-y-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>

@endif

<div class="bg-white rounded-2xl border border-slate-200 p-6">

    <form
        method="POST"
        action="{{ $organization->exists ? '/admin/organization/'.$organization->id : '/admin/organization' }}"
        class="space-y-6"
    >

        @csrf

        <!-- Name -->
        <div>
            <label class="block mb-2 font-semibold">
                Наименование <span class="text-red-600">*</span>
            </label>
            <input
                type="text"
                name="name"
                value="{{ old('name', $organization->name) }}"
                class="w-full border rounded px-3 py-2"
                required
            >
        </div>

        <!-- Legal name -->
        <div>
            <label class="block mb-2 font-semibold">Полное юридическое наименование</label>
            <input
                type="text"
                name="legal_name"
                value="{{ old('legal_name', $organization->legal_name) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- UNP -->
        <div>
            <label class="block mb-2 font-semibold">
                УНП
                <span class="text-slate-400 font-normal text-sm">(9 цифр)</span>
            </label>
            <input
                type="text"
                name="unp"
                value="{{ old('unp', $organization->unp) }}"
                inputmode="numeric"
                pattern="[0-9]{9}"
                maxlength="9"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Address -->
        <div>
            <label class="block mb-2 font-semibold">Адрес</label>
            <input
                type="text"
                name="address"
                value="{{ old('address', $organization->address) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Phone -->
        <div>
            <label class="block mb-2 font-semibold">Телефон</label>
            <input
                type="text"
                name="phone"
                value="{{ old('phone', $organization->phone) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Email -->
        <div>
            <label class="block mb-2 font-semibold">Email</label>
            <input
                type="email"
                name="email"
                value="{{ old('email', $organization->email) }}"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <!-- Comment -->
        <div>
            <label class="block mb-2 font-semibold">Комментарий</label>
            <textarea
                name="comment"
                rows="4"
                class="w-full border rounded px-3 py-2"
            >{{ old('comment', $organization->comment) }}</textarea>
        </div>

        @if($organization->exists)

            <!-- GUID (read-only) -->
            <div>
                <label class="block mb-2 font-semibold">
                    GUID 1С
                    <span class="text-slate-400 font-normal text-sm">(заполняется только при обмене с 1С)</span>
                </label>
                <input
                    type="text"
                    value="{{ $organization->guid ?? '—' }}"
                    class="w-full border rounded px-3 py-2 bg-slate-100 text-slate-500"
                    readonly
                    disabled
                >
            </div>

        @endif

        <!-- Submit -->
        <div>
            <button
                type="submit"
                class="bg-slate-800 hover:bg-slate-700 text-white px-6 py-3 rounded"
            >
                {{ $organization->exists ? 'Сохранить' : 'Создать организацию' }}
            </button>
        </div>

    </form>

</div>

@endsection
