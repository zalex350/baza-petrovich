// Живые подсказки поиска (товары + категории) для всех полей [data-search-suggest]
// в шапке: desktop-строка и мобильная строка поиска.

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

function render(data, query) {
    if (data.products.length === 0 && data.categories.length === 0) {
        return '<div class="px-4 py-3 text-sm text-slate-500">Ничего не найдено</div>';
    }

    let html = '<div class="flex flex-col sm:flex-row sm:divide-x divide-slate-100">';

    html += '<div class="sm:w-1/3 min-w-0">';
    if (data.categories.length > 0) {
        html += '<div class="py-2"><div class="px-4 py-1 text-xs font-semibold text-slate-500 uppercase">Категории</div>';
        data.categories.forEach((cat) => {
            html += `<a href="${escapeHtml(cat.url)}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">${escapeHtml(cat.name)}</a>`;
        });
        html += '</div>';
    }
    html += '</div>';

    html += '<div class="sm:w-2/3 min-w-0">';
    if (data.products.length > 0) {
        html += '<div class="py-2"><div class="px-4 py-1 text-xs font-semibold text-slate-500 uppercase">Товары</div>';
        html += '<div class="grid grid-cols-1 sm:grid-cols-2 gap-1 px-2">';
        data.products.forEach((prod) => {
            html += `<a href="${escapeHtml(prod.url)}" class="flex items-center gap-3 hover:bg-slate-50 rounded-lg p-2">`;
            // Нет фото — заглушка с сервера; файл не загрузился — та же заглушка
            html += `<img src="${escapeHtml(prod.image)}" alt="${escapeHtml(prod.name)}" width="48" height="48" loading="lazy" class="w-12 h-12 object-contain rounded border border-slate-200 bg-white" onerror="this.onerror=null;this.src='${escapeHtml(prod.placeholder)}'">`;
            html += '<div class="flex-1 min-w-0">';
            // Код товара у организации клиента (только своей): «Ваш код · артикул · название»
            if (prod.client_code) {
                html += `<div class="text-sm text-slate-800 truncate" data-client-code><span class="font-semibold">Ваш код ${escapeHtml(prod.client_code)}</span> · ${escapeHtml(prod.sku)} · ${escapeHtml(prod.name)}</div>`;
            } else {
                html += `<div class="text-sm text-slate-800 truncate">${escapeHtml(prod.name)}</div>`;
            }
            // Гостю цена не приходит — как в каталоге, «после входа»
            if (prod.price) {
                html += `<div class="text-xs text-slate-500">${escapeHtml(prod.price)} BYN</div>`;
            } else {
                html += `<div class="text-xs text-slate-400">${data.guest ? 'Цена доступна после входа' : 'Цена уточняется'}</div>`;
            }
            html += '</div></a>';
        });
        html += '</div></div>';
    }
    html += '</div></div>';

    html += '<div class="border-t border-slate-100">';
    html += `<a href="/search?q=${encodeURIComponent(query)}" class="block px-4 py-2 text-sm text-blue-600 hover:bg-slate-50 font-medium">Показать все результаты →</a>`;
    html += '</div>';

    return html;
}

function init(container) {
    const input = container.querySelector('[data-search-input]');
    const box = container.querySelector('[data-search-suggestions]');
    const url = container.dataset.suggestUrl;
    let timer;

    if (!input || !box || !url) return;

    const hide = () => box.classList.add('hidden');

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const query = input.value.trim();

        if (query.length < 2) {
            hide();
            box.innerHTML = '';
            return;
        }

        timer = setTimeout(() => {
            fetch(`${url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((data) => {
                    box.innerHTML = render(data, query);
                    box.classList.remove('hidden');
                })
                .catch((error) => console.error('Search suggest error:', error));
        }, 300);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) hide();
    });
}

document.querySelectorAll('[data-search-suggest]').forEach(init);
