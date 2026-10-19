// Очередь фото до загрузки (SRS §10.4.8). Выбор файлов добавляет их в очередь — повторный
// выбор не затирает уже выбранные; × убирает файл из очереди. Очередь синхронизируется
// с <input type="file"> через DataTransfer, поэтому форма отправляется как обычно.
// Без JS — обычный input с multiple: что выбрано последним, то и загрузится.
//
// options: { max, maxKb, types } — те же ограничения, что проверяет сервер.

let seq = 0;

const sameFile = (a, b) => a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;

export default ({ max, maxKb, types }) => ({
    queue: [],
    errors: [],

    get count() {
        return this.queue.length;
    },

    add(event) {
        this.errors = [];

        for (const file of Array.from(event.target.files)) {
            if (this.queue.some((item) => sameFile(item.file, file))) {
                continue;
            }

            // Тип и размер проверяет и сервер (по содержимому) — здесь только подсказка заранее
            if (types.length && ! types.includes(file.type)) {
                this.errors.push(`«${file.name}» — недопустимый формат. Можно загружать JPG, PNG и WebP`);
                continue;
            }

            if (file.size > maxKb * 1024) {
                this.errors.push(`«${file.name}» больше ${Math.round(maxKb / 1024)} МБ`);
                continue;
            }

            if (this.queue.length >= max) {
                this.errors.push(`За один раз — не больше ${max} файлов, остальные не добавлены`);
                break;
            }

            this.queue.push({
                id: ++seq,
                file,
                name: file.name,
                size: (file.size / 1024 / 1024).toFixed(1) + ' МБ',
                url: URL.createObjectURL(file),
            });
        }

        this.sync();
    },

    remove(id) {
        const item = this.queue.find((i) => i.id === id);

        if (item) {
            URL.revokeObjectURL(item.url);
            this.queue = this.queue.filter((i) => i.id !== id);
        }

        this.errors = [];
        this.sync();
    },

    clear() {
        this.queue.forEach((item) => URL.revokeObjectURL(item.url));
        this.queue = [];
        this.errors = [];
        this.sync();
    },

    // В input — ровно файлы очереди: их и отправит форма
    sync() {
        if (typeof DataTransfer === 'undefined') {
            return;
        }

        const transfer = new DataTransfer();
        this.queue.forEach((item) => transfer.items.add(item.file));
        this.$refs.input.files = transfer.files;
    },

    destroy() {
        this.queue.forEach((item) => URL.revokeObjectURL(item.url));
    },
});
