{{--
    Ошибка сервера. Без макета витрины: шапка обращается к БД (меню, корзина), а ошибка может быть
    в ней — тогда страница ошибки не отрисовалась бы. Стили — те же (Vite), логотип — текстом.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка на сервере — {{ config('shop.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-canvas text-ink">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex h-16 max-w-page items-center px-4 lg:px-6">
            <x-logo />
        </div>
    </header>

    <main class="mx-auto max-w-page px-4 py-5 lg:px-6">
        <x-empty-state title="Ошибка на сервере" icon="exclamation-triangle" class="mx-auto my-8 max-w-xl" data-error-page="500">
            <p class="text-meta uppercase tracking-wide">Ошибка 500</p>
            <p class="mt-1">Что-то пошло не так. Попробуйте повторить через несколько минут; если ошибка повторяется — свяжитесь с нами.</p>

            <x-slot:action>
                <x-button href="/">На главную</x-button>
                <x-button variant="secondary" href="/catalog">В каталог</x-button>
            </x-slot:action>
        </x-empty-state>
    </main>
</body>
</html>
