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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

</head>

<body class="bg-canvas text-ink">

    @include('partials.header')

    <main class="max-w-7xl mx-auto py-6 px-4">

        <x-alert.flash :validation="trim($__env->yieldContent('flash-validation', 'list'))" />

        @yield('content')

    </main>


</body>
</html>
