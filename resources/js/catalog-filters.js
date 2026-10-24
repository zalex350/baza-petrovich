// Каталог: фильтры, сортировка и страница — в параметрах URL (BR-029, BR-030).
// Без JS всё работает GET-формой и ссылками. С JS список обновляется без перезагрузки,
// URL меняется через history.pushState — «Назад», ссылка и обновление страницы работают.
// На десктопе фильтр применяется сразу; на мобильном — кнопкой «Показать товары».
// Позиция прокрутки списка запоминается при переходе в карточку товара и
// восстанавливается при возврате по «← Вернуться к списку» (BR-084).

const SCROLL_KEY = 'catalog-scroll';
const DEBOUNCE = 400; // мс — ввод «от/до» применяется не на каждую цифру

const storage = {
    read() {
        try {
            return JSON.parse(sessionStorage.getItem(SCROLL_KEY) ?? 'null');
        } catch {
            return null;
        }
    },
    write(value) {
        try {
            sessionStorage.setItem(SCROLL_KEY, JSON.stringify(value));
        } catch {
            // приватный режим — без восстановления прокрутки
        }
    },
    clear() {
        try {
            sessionStorage.removeItem(SCROLL_KEY);
        } catch {
            // ничего
        }
    },
};

// URL формы без пустых полей: ?brand[]=Grohe, а не ?price[from]=&price[to]=&sort=
export function formUrl(form) {
    const params = new URLSearchParams();

    for (const [name, value] of new FormData(form)) {
        if (String(value).trim() !== '') {
            params.append(name, value);
        }
    }

    const query = params.toString();

    return form.action.split('?')[0] + (query ? `?${query}` : '');
}

export default () => ({
    open: false,
    loading: false,
    timer: null,
    controller: null,

    init() {
        this.restoreScroll();

        window.addEventListener('popstate', () => this.load(location.href, { push: false }));

        // Ссылки чипов, «Сбросить всё», пагинации — без перезагрузки
        // $root, а не $el: в обработчиках событий $el — элемент события
        this.$root.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-catalog-link], [data-pagination] a[href]');

            if (link && ! event.ctrlKey && ! event.metaKey && ! event.shiftKey && event.button === 0) {
                event.preventDefault();
                this.open = false;
                this.load(link.href, { scrollTop: link.closest('[data-pagination]') !== null });

                return;
            }

            // Переход в карточку — запомнить, где были в списке
            if (event.target.closest('a[data-product-link]')) {
                storage.write({ url: location.pathname + location.search, y: window.scrollY });
            }
        });
    },

    isDesktop() {
        return window.matchMedia('(min-width: 1024px)').matches;
    },

    changed(event) {
        // На мобильном — только по «Показать товары»
        if (! this.isDesktop() && ! event.target.matches('[data-sort]')) {
            return;
        }

        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.submit(), event.target.type === 'number' ? DEBOUNCE : 0);
    },

    submit() {
        clearTimeout(this.timer);
        this.open = false;
        // Панель уже в нужном состоянии — не трогаем её, чтобы не сбить фокус в «от/до»
        this.load(formUrl(this.$root.querySelector('[data-catalog-form]')), { panel: false });
    },

    async load(url, { push = true, scrollTop = false, panel = true } = {}) {
        this.controller?.abort();
        this.controller = new AbortController();
        this.loading = true;

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                signal: this.controller.signal,
            });

            if (! response.ok) {
                throw new Error(response.status);
            }

            const html = new DOMParser().parseFromString(await response.text(), 'text/html');

            // Значения фильтров не зависят от выбора (BR-017), но отметки и «от/до» — из URL:
            // после перехода по ссылке или «Назад» заменяется и панель
            const parts = panel ? ['[data-catalog-results]', '[data-catalog-filters]'] : ['[data-catalog-results]'];

            for (const selector of parts) {
                const fresh = html.querySelector(selector);
                const current = this.$root.querySelector(selector);

                if (fresh && current) {
                    current.innerHTML = fresh.innerHTML;
                }
            }

            // Количество товаров у заголовка — вне компонента
            const found = html.querySelector('[data-found]');
            const foundHere = document.querySelector('[data-found]');

            if (found && foundHere) {
                foundHere.textContent = found.textContent;
            }

            if (push && url !== location.href) {
                history.pushState({ catalog: true }, '', url);
            }

            if (scrollTop) {
                this.$root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                // Не получилось — обычный переход, состояние всё равно в URL
                location.href = url;
            }
        } finally {
            this.loading = false;
        }
    },

    restoreScroll() {
        const saved = storage.read();

        if (saved && saved.url === location.pathname + location.search) {
            storage.clear();
            requestAnimationFrame(() => window.scrollTo(0, saved.y));

            // Картинки ещё грузятся и страница короче — повторить, когда всё загрузится
            if (document.readyState !== 'complete') {
                window.addEventListener('load', () => window.scrollTo(0, saved.y), { once: true });
            }
        }
    },
});
