// Кнопка «копировать» (x-copy): navigator.clipboard, а где его нет или он отказал (не HTTPS,
// нет разрешения) — запасной вариант: выделить текст ($refs.text) и execCommand('copy').
// Подпись «скопировано» — на 1,5 с; не удалось и так — текст остаётся выделенным, подсказка Ctrl+C.

const SHOW_MS = 1500;

export default (text) => ({
    label: '',
    timer: null,

    async copy() {
        let ok = false;

        try {
            await navigator.clipboard.writeText(text);
            ok = true;
        } catch {
            ok = this.copyBySelection();
        }

        this.show(ok ? 'скопировано' : 'выделено — нажмите Ctrl+C');
    },

    copyBySelection() {
        // Поле (x-copy field) — выделить значение поля
        if (this.$refs.text instanceof HTMLInputElement) {
            this.$refs.text.select();

            return this.execCopy();
        }

        const selection = window.getSelection();
        const range = document.createRange();

        range.selectNodeContents(this.$refs.text);
        selection.removeAllRanges();
        selection.addRange(range);

        const ok = this.execCopy();

        // Скопировано — снять выделение; нет — оставить, чтобы скопировать вручную
        if (ok) {
            selection.removeAllRanges();
        }

        return ok;
    },

    execCopy() {
        try {
            return document.execCommand('copy');
        } catch {
            return false;
        }
    },

    show(label) {
        clearTimeout(this.timer);
        this.label = label;
        this.timer = setTimeout(() => this.label = '', SHOW_MS);
    },
});
