// Мегаменю каталога (desktop). Alpine-компонент megaMenu.
//
// - открытие по наведению с задержкой OPEN_DELAY (не открывается случайно) или по клику;
// - закрытие по уходу курсора (CLOSE_DELAY), Esc, клику вне кнопки/панели;
// - menu-aim: пока курсор движется по диагонали к правой панели, пункт слева
//   не переключается (алгоритм jquery-menu-aim);
// - клавиатура: ↓ на кнопке открывает меню, ↑/↓ по разделам, → в подкатегории,
//   ← обратно, Enter — переход по ссылке, Esc — закрыть.

const OPEN_DELAY = 150;
const CLOSE_DELAY = 300;
const AIM_DELAY = 300;
const AIM_TOLERANCE = 75; // px — расширение «треугольника» по вертикали
const MOUSE_LOCS = 3;

const slope = (a, b) => (b.y - a.y) / (b.x - a.x);

export default ({ first = null } = {}) => ({
    open: false,
    active: first,

    openTimer: null,
    closeTimer: null,
    aimTimer: null,
    mouseLocs: [],
    lastDelayLoc: null,

    // --- открытие / закрытие ---

    buttonEnter() {
        clearTimeout(this.closeTimer);
        if (this.open) return;
        this.openTimer = setTimeout(() => this.show(), OPEN_DELAY);
    },

    buttonLeave() {
        clearTimeout(this.openTimer);
        this.scheduleClose();
    },

    panelEnter() {
        clearTimeout(this.closeTimer);
    },

    panelLeave() {
        clearTimeout(this.aimTimer);
        this.scheduleClose();
    },

    scheduleClose() {
        clearTimeout(this.closeTimer);
        this.closeTimer = setTimeout(() => this.close(), CLOSE_DELAY);
    },

    show() {
        clearTimeout(this.closeTimer);
        this.open = true;
        if (this.active === null) this.active = first;
    },

    toggle() {
        clearTimeout(this.openTimer);
        this.open ? this.close() : this.show();
    },

    close(returnFocus = false) {
        if (!this.open) return;
        clearTimeout(this.openTimer);
        clearTimeout(this.closeTimer);
        clearTimeout(this.aimTimer);
        this.open = false;
        this.mouseLocs = [];
        if (returnFocus) this.$refs.button.focus();
    },

    onWindowClick(event) {
        if (!this.open) return;
        if (this.$refs.button.contains(event.target) || this.$refs.panel.contains(event.target)) return;
        this.close();
    },

    // --- выбор раздела с menu-aim ---

    activate(id) {
        clearTimeout(this.aimTimer);
        this.active = id;
    },

    itemEnter(id) {
        clearTimeout(this.aimTimer);
        const delay = this.activationDelay();

        if (delay) {
            this.aimTimer = setTimeout(() => this.itemEnter(id), delay);
        } else {
            this.activate(id);
        }
    },

    trackMouse(event) {
        this.mouseLocs.push({ x: event.clientX, y: event.clientY });
        if (this.mouseLocs.length > MOUSE_LOCS) this.mouseLocs.shift();
    },

    // Задержка нужна, если курсор движется внутрь треугольника
    // «текущая точка → верхний и нижний левые углы правой панели».
    activationDelay() {
        const submenu = this.$refs.submenu;
        if (!submenu || this.mouseLocs.length < 2) return 0;

        const rect = submenu.getBoundingClientRect();
        const loc = this.mouseLocs[this.mouseLocs.length - 1];
        const prev = this.mouseLocs[0];

        if (this.lastDelayLoc && loc.x === this.lastDelayLoc.x && loc.y === this.lastDelayLoc.y) {
            return 0; // курсор остановился — переключаем
        }

        const upper = { x: rect.left, y: rect.top - AIM_TOLERANCE };
        const lower = { x: rect.left, y: rect.bottom + AIM_TOLERANCE };

        if (loc.x >= rect.left) return 0;

        const movingIntoTriangle =
            slope(loc, upper) < slope(prev, upper) &&
            slope(loc, lower) > slope(prev, lower);

        if (movingIntoTriangle) {
            this.lastDelayLoc = loc;
            return AIM_DELAY;
        }

        this.lastDelayLoc = null;
        return 0;
    },

    // --- клавиатура ---

    rootLinks() {
        return Array.from(this.$refs.panel.querySelectorAll('[data-mega-root]'));
    },

    openAndFocus() {
        this.show();
        this.$nextTick(() => this.focusActiveRoot());
    },

    focusActiveRoot() {
        const links = this.rootLinks();
        (links.find((a) => Number(a.dataset.megaRoot) === this.active) || links[0])?.focus();
    },

    moveRoot(step) {
        const links = this.rootLinks();
        const index = links.indexOf(document.activeElement);
        const next = links[(index + step + links.length) % links.length];
        next?.focus();
    },

    focusSubmenu() {
        this.$refs.panel.querySelector(`[data-mega-sub="${this.active}"] a`)?.focus();
    },
});
