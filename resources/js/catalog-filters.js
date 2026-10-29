// Каталог: фильтры, сортировка и страница — в параметрах URL (BR-029, BR-030).
// Без JS всё работает GET-формой и ссылками. С JS список обновляется без перезагрузки,
// URL меняется через history.pushState — «Назад», ссылка и обновление страницы работают.
// На десктопе фильтр применяется сразу; на мобильном — кнопкой «Показать товары».
// Позиция прокрутки списка запоминается при переходе в карточку товара и
// восстанавливается при возврате по «← Вернуться к списку» (BR-084).
// Вид списка (плитка / список) — параметр view в URL; выбор запоминается в localStorage
// и применяется, когда список открыт без параметра. «Показать ещё» догружает следующую
// страницу под список, обновляет пагинацию и URL.

const SCROLL_KEY = 'catalog-scroll';
const VIEW_KEY = 'catalog-view';

const view = {
    read() {
        try {
            return localStorage.getItem(VIEW_KEY);
        } catch {
            return null;
        }
    },
    write(value) {
        try {
            localStorage.setItem(VIEW_KEY, value);
        } catch {
            // без localStorage вид держится только в URL
        }
    },
};
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
        this.applyView();

        window.addEventListener('popstate', () => this.load(location.href, { push: false }));

        // Ссылки чипов, «Сбросить всё», пагинации — без перезагрузки
        // $root, а не $el: в обработчиках событий $el — элемент события
        this.$root.addEventListener('click', (event) => {
            const plain = ! event.ctrlKey && ! event.metaKey && ! event.shiftKey && event.button === 0;
            const more = event.target.closest('a[data-load-more]');

            if (more && plain) {
                event.preventDefault();
                this.loadMore(more);

                return;
            }

            const switcher = event.target.closest('a[data-view]');

            if (switcher) {
                view.write(switcher.dataset.view);
            }

            const link = event.target.closest('a[data-catalog-link], [data-pagination] a[href]');

            if (link && plain) {
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

    // Открыт без ?view — применить сохранённый выбор «плитка»
    applyView() {
        const url = new URL(location.href);

        if (url.searchParams.has('view')) {
            view.write(url.searchParams.get('view') === 'grid' ? 'grid' : 'list');

            return;
        }

        if (view.read() === 'grid') {
            url.searchParams.set('view', 'grid');
            url.searchParams.delete('page');
            history.replaceState({ catalog: true }, '', url);
            this.load(url.href, { push: false });
        }
    },

    // «Показать ещё»: товары следующей страницы — в конец списка, пагинация и кнопка — новые
    async loadMore(link) {
        if (this.loading) {
            return;
        }

        this.loading = true;

        try {
            const response = await fetch(link.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            });

            if (! response.ok) {
                throw new Error(response.status);
            }

            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            const list = this.$root.querySelector('[data-product-list]');
            const fresh = html.querySelector('[data-product-list]');

            if (! list || ! fresh) {
                throw new Error('list');
            }

            list.append(...fresh.children);

            for (const selector of ['[data-pagination]']) {
                const part = html.querySelector(selector);
                const current = this.$root.querySelector(selector);

                if (part && current) {
                    current.innerHTML = part.innerHTML;
                }
            }

            const wrap = this.$root.querySelector('[data-load-more-wrap]');
            const nextWrap = html.querySelector('[data-load-more-wrap]');

            if (wrap) {
                if (nextWrap) {
                    wrap.innerHTML = nextWrap.innerHTML;
                } else {
                    wrap.remove();
                }
            }

            history.pushState({ catalog: true }, '', link.href);
        } catch {
            location.href = link.href;
        } finally {
            this.loading = false;
        }
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
