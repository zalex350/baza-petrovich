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

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

</head>

<body class="bg-gray-100 text-gray-900">

    @include('partials.header')

    <main class="max-w-7xl mx-auto py-6 px-4">

        <x-alert.flash :validation="trim($__env->yieldContent('flash-validation', 'list'))" />

        @yield('content')

    </main>


</body>
</html>
