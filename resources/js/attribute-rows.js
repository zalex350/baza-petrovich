// Блок «Характеристики» формы товара: добавление и удаление строк, порядок перетаскиванием
// (BR-061). Порядок полей в форме = порядок строк, сервер берёт его как есть.
// Без JS — строки и пустые запасные строки внизу; с JS запасные скрыты, есть «+ Добавить».

let seq = 0;

export default () => ({
    ready: false,

    async init() {
        // Запасные строки без JS; с JS вместо них — кнопка «Добавить»
        this.$refs.list.querySelectorAll('[data-spare]').forEach((el) => el.remove());
        this.ready = true;

        const { default: Sortable } = await import('sortablejs');

        Sortable.create(this.$refs.list, { handle: '[data-drag-handle]', animation: 150 });
    },

    add() {
        const html = this.$refs.template.innerHTML.replaceAll('__INDEX__', `n${Date.now()}${++seq}`);
        this.$refs.list.insertAdjacentHTML('beforeend', html);
        this.$refs.list.lastElementChild.querySelector('input')?.focus();
    },

    remove(event) {
        event.target.closest('[data-attribute-row]').remove();
    },
});
