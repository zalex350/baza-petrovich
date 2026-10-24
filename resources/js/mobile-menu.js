// Мобильная шапка (<1024px). Alpine-компонент mobileMenu.
//
// - поиск — постоянной строкой под шапкой (макет CartMobile), без переключателя;
// - off-canvas панель слева: блокирует прокрутку страницы, закрывается
//   по оверлею и Esc;
// - каталог — пошаговая навигация по уровням (drill-down), без hover:
//   дерево загружается один раз с /catalog/menu.json при первом открытии.

export default ({ menuUrl }) => ({
    panel: false,
    view: 'main', // 'main' | 'catalog'

    tree: null,
    loading: false,
    failed: false,
    stack: [], // путь по дереву: [узел 1-го уровня, узел 2-го уровня, …]

    init() {
        this.$watch('panel', (open) => {
            document.body.classList.toggle('overflow-hidden', open);
            if (open) this.$nextTick(() => this.$refs.close?.focus());
        });
    },

    openPanel() {
        this.view = 'main';
        this.stack = [];
        this.panel = true;
    },

    closePanel() {
        if (!this.panel) return;
        this.panel = false;
        this.$refs.burger?.focus();
    },

    // --- каталог ---

    async openCatalog() {
        this.view = 'catalog';
        this.stack = [];

        if (this.tree || this.loading) return;

        this.loading = true;
        this.failed = false;

        try {
            const response = await fetch(menuUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.statusText);
            this.tree = await response.json();
        } catch (error) {
            console.error('Catalog menu error:', error);
            this.failed = true;
        } finally {
            this.loading = false;
        }
    },

    get current() {
        return this.stack.length ? this.stack[this.stack.length - 1] : null;
    },

    get items() {
        return this.current ? this.current.children : (this.tree || []);
    },

    enter(node) {
        this.stack.push(node);
        this.$nextTick(() => this.$refs.catalogList?.scrollTo({ top: 0 }));
    },

    back() {
        if (this.stack.length) {
            this.stack.pop();
        } else {
            this.view = 'main';
        }
    },
});
