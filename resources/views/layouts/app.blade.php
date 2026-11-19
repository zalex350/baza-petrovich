<!DOCTYPE html>
<html lang="ru">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        @yield('title', config('shop.name'))
    </title>

    @yield('meta')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

    {{-- Коды сторонних сервисов в <head> (админка «Коды и виджеты») --}}
    @foreach(\App\Models\SiteScript::for('head') as $script)
        {!! $script->code !!}
    @endforeach
</head>

<body class="bg-canvas text-ink">

    @include('partials.header')

    <main class="max-w-page mx-auto py-5 px-4 lg:px-6">

        <x-alert.flash :validation="trim($__env->yieldContent('flash-validation', 'list'))" />

        @yield('content')

        @include('partials.page_extras', ['position' => 'inline'])

    </main>

    @include('partials.footer')

    @include('partials.page_extras', ['position' => 'popup'])
    @include('partials.page_extras', ['position' => 'body'])
</body>
</html>
