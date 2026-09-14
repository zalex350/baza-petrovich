@extends('layouts.app')

@section('title', 'Профиль')

@section('content')

<div class="mb-10">

    <h1 class="text-4xl font-bold text-slate-800">
        Профиль
    </h1>

</div>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">

        <div class="max-w-xl">

            @include('profile.partials.update-profile-information-form')

        </div>

    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">

        <div class="max-w-xl">

            @include('profile.partials.update-password-form')

        </div>

    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">

        <div class="max-w-xl">

            @include('profile.partials.delete-user-form')

        </div>

    </div>

</div>

@endsection
