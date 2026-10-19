// Блок «Изображения» на странице товара в админке.
// Без JS всё работает формами: загрузка, «вверх/вниз», основное, замена, удаление.
// С JS: перетаскивание с сохранением порядка без перезагрузки. Очередь фото до загрузки — image-queue.js.

export default (reorderUrl) => ({
    status: '',
    statusError: false,

    async init() {
        const list = this.$refs.list;

        if (! list) {
            return;
        }

        // SortableJS (~15 КБ gzip, без зависимостей, тач-перетаскивание) — отдельным чанком,
        // только на странице товара в админке
        const { default: Sortable } = await import('sortablejs');

        Sortable.create(list, {
            handle: '[data-drag-handle]',
            animation: 150,
            onEnd: () => this.saveOrder(),
        });
    },

    async saveOrder() {
        const ids = Array.from(this.$refs.list.querySelectorAll('[data-image-id]'))
            .map((el) => el.dataset.imageId);

        this.status = 'Сохраняем порядок…';
        this.statusError = false;

        try {
            const response = await fetch(reorderUrl, {
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
            this.renumber();
        } catch {
            this.status = 'Не удалось сохранить порядок. Обновите страницу и попробуйте снова.';
            this.statusError = true;
        }
    },

    // Номера позиций и пометка «основное» не зависят от порядка, обновляем только номера
    renumber() {
        this.$refs.list.querySelectorAll('[data-position]').forEach((el, index) => {
            el.textContent = index + 1;
        });
    },
});
