// Галерея карточки товара (SRS §4.7): основное фото medium, миниатюры, увеличенный просмотр.
// Без библиотек: Alpine уже есть, а lightbox — это оверлей, листание, клавиши и свайп.
// Без JS: показано основное фото, миниатюры — ссылки на большие версии.
//
// images: [{ medium, large, thumb, mw, mh, lw, lh }] — основное первым.

const SWIPE_MIN = 50; // px по горизонтали — меньше считается не свайпом, а касанием

export default (images, placeholder) => ({
    images,
    placeholder,
    current: 0,
    open: false,
    touchX: null,
    lastFocus: null,

    get image() {
        return this.images[this.current];
    },

    get counter() {
        return `${this.current + 1} из ${this.images.length}`;
    },

    select(index) {
        this.current = index;
    },

    show(index = this.current) {
        this.current = index;
        this.open = true;
        this.lastFocus = document.activeElement;
        document.documentElement.classList.add('overflow-hidden');
        this.$nextTick(() => this.$refs.close?.focus());
    },

    close() {
        this.open = false;
        document.documentElement.classList.remove('overflow-hidden');
        this.lastFocus?.focus();
    },

    next() {
        this.current = (this.current + 1) % this.images.length;
    },

    prev() {
        this.current = (this.current - 1 + this.images.length) % this.images.length;
    },

    keydown(event) {
        if (! this.open) {
            return;
        }

        const actions = { ArrowRight: () => this.next(), ArrowLeft: () => this.prev(), Escape: () => this.close() };

        if (actions[event.key]) {
            event.preventDefault();
            actions[event.key]();
        }
    },

    touchStart(event) {
        this.touchX = event.changedTouches[0].clientX;
    },

    touchEnd(event) {
        if (this.touchX === null) {
            return;
        }

        const delta = event.changedTouches[0].clientX - this.touchX;
        this.touchX = null;

        if (Math.abs(delta) >= SWIPE_MIN) {
            delta < 0 ? this.next() : this.prev();
        }
    },

    // Файл не загрузился — заглушка (один раз, без зацикливания)
    fallback(event) {
        if (event.target.src !== new URL(this.placeholder, location.href).href) {
            event.target.src = this.placeholder;
        }
    },
});
