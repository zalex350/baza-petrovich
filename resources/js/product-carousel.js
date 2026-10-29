// Карусель товаров (x-product-carousel): прокрутка ленты стрелками на ширину видимой части,
// стрелки отключаются у краёв. Лента прокручивается и пальцем / колесом (scroll-snap).

export default () => ({
    atStart: true,
    atEnd: false,

    init() {
        this.update();
        this.$refs.track.addEventListener('scroll', () => this.update(), { passive: true });
        window.addEventListener('resize', () => this.update(), { passive: true });
    },

    update() {
        const track = this.$refs.track;

        this.atStart = track.scrollLeft <= 4;
        this.atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    },

    scroll(direction) {
        const track = this.$refs.track;

        track.scrollBy({ left: direction * track.clientWidth * 0.9, behavior: 'smooth' });
    },
});
