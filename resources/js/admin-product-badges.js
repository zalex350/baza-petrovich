// Бейджи ХИТ / НОВИНКА / Акция в форме товара (админка): сохраняются сразу по галочке,
// без кнопок формы. Ошибка — галочка возвращается в прежнее состояние, сообщение рядом.

export default (url) => ({
    status: '',
    statusError: false,

    async save(input) {
        const value = input.checked;

        input.disabled = true;
        this.status = 'Сохраняем…';
        this.statusError = false;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.$root.dataset.csrf,
                },
                body: JSON.stringify({ field: input.name, value }),
            });

            if (! response.ok) {
                throw new Error(response.status);
            }

            const data = await response.json();

            input.checked = data.value;
            this.status = data.message;
        } catch {
            input.checked = ! value;
            this.status = 'Не сохранено — обновите страницу и попробуйте снова.';
            this.statusError = true;
        } finally {
            input.disabled = false;
        }
    },
});
