// Порядок списка перетаскиванием с сохранением без перезагрузки (документы товара в админке).
// sortableList(url, key): элементы x-ref="list" с data-{key} (dataset[key] — id), ручка — [data-drag-handle];
// после перетаскивания — POST url {ids: [...]} (JSON), ответ {message}. Без JS — порядок не меняется.

export default (url, key) => ({
    status: '',
    statusError: false,

    async init() {
        const list = this.$refs.list;

        if (! list) {
            return;
        }

        // SortableJS — тем же отдельным чанком, что и для фото товара
        const { default: Sortable } = await import('sortablejs');

        Sortable.create(list, {
            handle: '[data-drag-handle]',
            animation: 150,
            onEnd: () => this.save(),
        });
    },

    async save() {
        const ids = Array.from(this.$refs.list.children).map((el) => el.dataset[key]).filter(Boolean);

        this.status = 'Сохраняем порядок…';
        this.statusError = false;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.$root.dataset.csrf,
                },
                body: JSON.stringify({ ids }),
            });

            if (! response.ok) {
                throw new Error(response.status);
            }

            this.status = (await response.json()).message;
        } catch {
            this.status = 'Не удалось сохранить порядок. Обновите страницу и попробуйте снова.';
            this.statusError = true;
        }
    },
});
