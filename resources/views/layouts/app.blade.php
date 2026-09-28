<!DOCTYPE html>
<html lang="ru">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        @yield('title', 'Smesitel')
    </title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

</head>

<body class="bg-gray-100 text-gray-900">

    @include('layouts.navigation')

    <main class="max-w-7xl mx-auto py-6 px-4">

        @include('partials.flash')

        @yield('content')

    </main>

    <script>
    (function() {
        const searchInput = document.querySelector('input[name="q"]');
        const suggestionsDiv = document.getElementById('search-suggestions');
        let debounceTimer;

        if (!searchInput || !suggestionsDiv) return;

        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim();

            if (query.length < 2) {
                suggestionsDiv.classList.add('hidden');
                suggestionsDiv.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch('{{ route("search.suggest") }}?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        renderSuggestions(data, query);
                    })
                    .catch(error => {
                        console.error('Search suggest error:', error);
                    });
            }, 300);
        });

        function renderSuggestions(data, query) {
            let html = '';

            if (data.products.length === 0 && data.categories.length === 0) {
                html = '<div class="px-4 py-3 text-sm text-slate-500">Ничего не найдено</div>';
            } else {
                html += '<div class="flex divide-x divide-slate-100">';

                // Категории слева (1/3 ширины)
                html += '<div class="w-1/3 min-w-0">';
                if (data.categories.length > 0) {
                    html += '<div class="py-2">';
                    html += '<div class="px-4 py-1 text-xs font-semibold text-slate-500 uppercase">Категории</div>';
                    data.categories.forEach(cat => {
                        html += `<a href="${cat.url}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">${cat.name}</a>`;
                    });
                    html += '</div>';
                }
                html += '</div>';

                // Товары справа (2/3 ширины) в сетке 2 колонки
                html += '<div class="w-2/3 min-w-0">';
                if (data.products.length > 0) {
                    html += '<div class="py-2">';
                    html += '<div class="px-4 py-1 text-xs font-semibold text-slate-500 uppercase">Товары</div>';
                    html += '<div class="grid grid-cols-2 gap-1 px-2">';
                    data.products.forEach(prod => {
                        html += `<a href="${prod.url}" class="flex items-center gap-3 hover:bg-slate-50 rounded-lg p-2">`;
                        if (prod.image) {
                            html += `<img src="${prod.image}" alt="${prod.name}" class="w-12 h-12 object-contain rounded border border-slate-200">`;
                        } else {
                            html += `<div class="w-12 h-12 bg-slate-100 flex items-center justify-center rounded border border-slate-200 text-2xl">🚰</div>`;
                        }
                        html += '<div class="flex-1 min-w-0">';
                        html += `<div class="text-sm text-slate-800 truncate">${prod.name}</div>`;
                        if (prod.price) {
                            html += `<div class="text-xs text-slate-500">${prod.price} BYN</div>`;
                        } else {
                            html += `<div class="text-xs text-slate-400">Цена уточняется</div>`;
                        }
                        html += '</div>';
                        html += '</a>';
                    });
                    html += '</div>';
                    html += '</div>';
                }
                html += '</div>';

                html += '</div>';

                // Ссылка "Показать все результаты" под обоими столбцами
                html += `<div class="border-t border-slate-100">`;
                html += `<a href="/search?q=${encodeURIComponent(query)}" class="block px-4 py-2 text-sm text-blue-600 hover:bg-slate-50 font-medium">Показать все результаты →</a>`;
                html += '</div>';
            }

            suggestionsDiv.innerHTML = html;
            suggestionsDiv.classList.remove('hidden');
        }

        document.addEventListener('click', function(event) {
            if (!suggestionsDiv.contains(event.target) && event.target !== searchInput) {
                suggestionsDiv.classList.add('hidden');
            }
        });
    })();
    </script>

</body>
</html>
