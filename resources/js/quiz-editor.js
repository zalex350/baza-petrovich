/**
 * Конструктор шагов квиза в админке: вопросы, тип, варианты (по строке), обязательность, подсказка;
 * порядок — вверх / вниз. Результат — JSON в скрытом поле steps (сервер нормализует — Quiz::normalizeSteps).
 */
export default (initial) => ({
    steps: (initial && initial.length ? initial : [{ question: '', type: 'single', options: [], required: true, hint: '' }])
        .map((s) => ({ ...s, optionsText: (s.options || []).join('\n'), hint: s.hint || '' })),
    types: { single: 'Один вариант', multi: 'Несколько вариантов', number: 'Число', text: 'Свой ответ (текст)' },

    add() {
        this.steps.push({ question: '', type: 'single', optionsText: '', required: true, hint: '' });
    },
    remove(i) {
        this.steps.splice(i, 1);
    },
    move(i, d) {
        const j = i + d;
        if (j < 0 || j >= this.steps.length) return;
        const [s] = this.steps.splice(i, 1);
        this.steps.splice(j, 0, s);
    },
    get json() {
        return JSON.stringify(this.steps.map((s) => ({
            question: s.question,
            type: s.type,
            options: s.optionsText.split('\n').map((o) => o.trim()).filter(Boolean),
            required: !!s.required,
            hint: s.hint,
        })));
    },
});
