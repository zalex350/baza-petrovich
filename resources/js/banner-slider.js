// Слайдер баннеров на главной (x-banner-slider): автопрокрутка, стрелки, точки,
// пауза при наведении и фокусе внутри; при «уменьшении движения» в системе — без автопрокрутки.

const INTERVAL = 6000;

export default (count) => ({
    index: 0,
    count,
    timer: null,
    paused: false,

    init() {
        this.start();
    },

    start() {
        clearInterval(this.timer);

        if (this.count < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        this.timer = setInterval(() => {
            if (! this.paused && ! document.hidden) {
                this.go(this.index + 1);
            }
        }, INTERVAL);
    },

    go(i) {
        this.index = (i + this.count) % this.count;
    },

    // Ручное переключение — отсчёт автопрокрутки заново
    manual(i) {
        this.go(i);
        this.start();
    },

    destroy() {
        clearInterval(this.timer);
    },
});
