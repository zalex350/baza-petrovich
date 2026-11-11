// Подсказки артикула в «Добавить по артикулу» (корзина, x-cart-quick-add): тот же /search/suggest,
// что и в шапке, но выбор не открывает товар, а подставляет ваш код (если есть) или артикул,
// id товара (product_id) и количество по умолчанию.
// ↑/↓ — выбор, Enter — подставить выбранное и сразу добавить (без выбора — отправить форму),
// щелчок — подставить и перейти в количество, Esc — закрыть.

const DEBOUNCE = 250;

export default (url) => ({
    open: false,
    items: [],
    active: -1,
    timer: null,
    controller: null,

    search() {
        clearTimeout(this.timer);
        const query = this.$refs.sku.value.trim();

        if (query.length < 2) {
            this.close();

            return;
        }

        this.timer = setTimeout(async () => {
            this.controller?.abort();
            this.controller = new AbortController();

            try {
                const response = await fetch(`${url}?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });
                const data = await response.json();

                // code — код товара у организации клиента (только своей), иначе null
                this.items = (data.products ?? []).map((p) => ({ id: p.id, article: p.article, brand: p.brand ?? null, code: p.client_code ?? null, name: p.name, qty: p.default_qty ?? 1 }));
                this.active = -1;
                this.open = this.items.length > 0 && document.activeElement === this.$refs.sku;
            } catch {
                // Нет подсказок — поле работает и без них
            }
        }, DEBOUNCE);
    },

    move(step) {
        if (! this.open) {
            return;
        }

        this.active = (this.active + step + this.items.length) % this.items.length;
    },

    enter(event) {
        if (this.open && this.active >= 0) {
            event.preventDefault();
            this.fill(this.items[this.active]);
            this.$refs.sku.form.requestSubmit();
        }
        // Иначе Enter отправляет форму — «Добавить»
    },

    pick(item) {
        this.fill(item);
        this.$refs.qty.focus();
        this.$refs.qty.select();
    },

    fill(item) {
        // «Добавить по вашему коду» — подставляется ваш код, без кода — артикул; товар запоминается
        // (product_id): артикул бывает у нескольких брендов, добавится именно выбранный
        this.$refs.sku.value = item.code ?? item.article;
        this.$refs.productId.value = item.id;
        this.$refs.qty.value = item.qty;
        this.close();
    },

    close() {
        clearTimeout(this.timer);
        this.open = false;
        this.active = -1;
    },
});
