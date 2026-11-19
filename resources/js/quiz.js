/**
 * Квиз на витрине (x-quiz): шаги-вопросы → контакты → отправка JSON на /quiz/{id} → заявка.
 * config: { id, url, steps: [{question, type, options, required, hint}] }
 */
export default (config) => ({
    steps: config.steps,
    step: 0, // 0..steps.length — последний шаг — контакты
    answers: config.steps.map((s) => (s.type === 'multi' ? [] : '')),
    name: '',
    phone: '',
    comment: '',
    consent: false,
    error: '',
    sending: false,
    done: null,

    get total() {
        return this.steps.length + 1;
    },
    get onContacts() {
        return this.step >= this.steps.length;
    },
    get progress() {
        return Math.round((this.step / this.total) * 100);
    },
    answered(i) {
        const a = this.answers[i];
        return Array.isArray(a) ? a.length > 0 : String(a).trim() !== '';
    },
    pick(option) {
        // Один вариант — выбрать и сразу дальше
        this.answers[this.step] = option;
        this.error = '';
        setTimeout(() => this.next(), 150);
    },
    next() {
        const s = this.steps[this.step];
        if (s && s.required && !this.answered(this.step)) {
            this.error = 'Выберите вариант или напишите ответ';
            return;
        }
        this.error = '';
        this.step = Math.min(this.step + 1, this.steps.length);
    },
    back() {
        this.error = '';
        this.step = Math.max(this.step - 1, 0);
    },
    async submit() {
        if (!this.name.trim() || !this.phone.trim()) {
            this.error = 'Укажите имя и телефон';
            return;
        }
        if (!this.consent) {
            this.error = 'Нужно согласие на обработку персональных данных';
            return;
        }
        this.sending = true;
        this.error = '';
        try {
            const res = await fetch(config.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': this.$root.dataset.csrf,
                },
                body: JSON.stringify({
                    answers: this.answers,
                    name: this.name,
                    phone: this.phone,
                    comment: this.comment,
                    consent: this.consent ? 1 : 0,
                    page: window.location.pathname,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                this.error = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Не отправилось — попробуйте ещё раз');
                return;
            }
            this.done = data;
        } catch (e) {
            this.error = 'Нет связи — попробуйте ещё раз';
        } finally {
            this.sending = false;
        }
    },
});
