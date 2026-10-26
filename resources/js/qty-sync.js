// Отправка количества (x-qty) на сервер без гонок: по форме — один запрос за раз, последнее
// значение побеждает. Пока запрос идёт, новые изменения не отправляются параллельно: после ответа
// уходит ещё один запрос, если значение в поле отличается от отправленного. Ответ сервера
// (прежнее количество после ошибки) применяется, только если пользователь не изменил его дальше.
// Ручной ввод (Enter, уход из поля) отправляется сразу, «−/+» — после паузы (серия нажатий — один запрос).

const DEBOUNCE = 400;
const state = new WeakMap();

function get(form) {
    if (! state.has(form)) {
        const input = form.querySelector('input[name="quantity"]');
        state.set(form, { busy: false, timer: null, sent: input?.defaultValue ?? null });
    }

    return state.get(form);
}

/**
 * @param {HTMLFormElement} form
 * @param {(form: HTMLFormElement, quantity: string) => Promise<{quantity?: number}|null>} send
 *        запрос; ответ с quantity — количество в корзине по данным сервера
 * @param {{ delay?: boolean }} options delay — «−/+»: после паузы
 */
export function queueQuantity(form, send, { delay = false } = {}) {
    const s = get(form);

    clearTimeout(s.timer);

    if (delay) {
        s.timer = setTimeout(() => flush(form, send), DEBOUNCE);
    } else {
        flush(form, send);
    }
}

async function flush(form, send) {
    const s = get(form);
    const input = form.querySelector('input[name="quantity"]');

    if (! input || s.busy || input.value === s.sent) {
        return;
    }

    s.busy = true;

    try {
        // Пока пользователь меняет значение во время запроса — досылать последнее
        while (input.value !== s.sent) {
            const value = input.value;
            s.sent = value;
            const data = await send(form, value);

            if (data && data.quantity !== undefined && String(data.quantity) !== value) {
                // Сервер не принял значение: прежнее — в поле, если оно не изменилось дальше
                s.sent = String(data.quantity);

                if (input.value === value) {
                    input.value = data.quantity;
                    input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { fromServer: true } }));
                }
            }
        }
    } finally {
        s.busy = false;
    }
}
