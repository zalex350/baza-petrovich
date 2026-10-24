// Страница корзины ([data-cart-page]) без перезагрузки: количество (x-qty), галочки «в заказ»,
// «Выбрать все», удаление позиции (x-confirm).
//
// Действие — fetch с Accept: application/json на CartController (update / select / select-all /
// delete); ответ {ok, message, cart, quantity?}. Затем страница корзины запрашивается заново
// и заменяются её части [data-refresh="…"] (суммы строк, наличие, панель «Ваш заказ», баннер,
// нижняя панель телефона); часть, где сейчас фокус (поле количества), не трогается.
// Ошибка проверки (кратность, остаток) — значение не применяется, текст — в строке.
// Сеть / не JSON — обычная отправка формы.

import { updateHeader } from './cart-actions';

const DEBOUNCE = 400;

const timers = new WeakMap();
let refreshing = null;

const page = () => document.querySelector('[data-cart-page]');

function token() {
    return page()?.querySelector('input[name="_token"]')?.value
        ?? document.querySelector('input[name="_token"]')?.value
        ?? '';
}

async function post(url, data = {}) {
    const body = new FormData();
    body.append('_token', token());

    for (const [key, value] of Object.entries(data)) {
        body.append(key, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (! (response.headers.get('Content-Type') ?? '').includes('application/json')) {
        throw new Error(String(response.status));
    }

    return response.json();
}

function message(el, text) {
    const item = el.closest('[data-cart-item]');
    const box = item?.querySelector('[data-cart-message]');

    if (! box) {
        return;
    }

    box.textContent = '';

    // «Доступно N шт.» уже стоит в строке — не повторять
    if (text && ! item.textContent.includes(text)) {
        box.textContent = text;
    }
}

// Заново отрисовать изменившиеся части страницы
async function refresh() {
    // Несколько действий подряд — одно обновление после последнего
    refreshing?.abort();
    refreshing = new AbortController();

    let html;

    try {
        const response = await fetch(location.pathname, {
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            signal: refreshing.signal,
        });
        html = new DOMParser().parseFromString(await response.text(), 'text/html');
    } catch (error) {
        if (error.name !== 'AbortError') {
            location.reload();
        }

        return;
    }

    // Корзина опустела — нужна другая страница (пустое состояние)
    if (! html.querySelector('[data-cart-item]')) {
        location.reload();

        return;
    }

    // Удалённые позиции
    document.querySelectorAll('[data-cart-item]').forEach((item) => {
        if (! html.querySelector(`[data-cart-item="${item.dataset.cartItem}"]`)) {
            item.remove();
        }
    });

    document.querySelectorAll('[data-refresh]').forEach((part) => {
        const fresh = html.querySelector(`[data-refresh="${part.dataset.refresh}"]`);

        if (fresh && ! part.contains(document.activeElement)) {
            part.replaceWith(fresh);
        }
    });
}

async function changeQuantity(form) {
    const input = form.querySelector('input[name="quantity"]');

    try {
        const data = await post(form.action, { quantity: input.value });

        updateHeader(data.cart);
        message(form, data.ok ? '' : data.message);

        if (String(data.quantity) !== input.value) {
            input.value = data.quantity;
            // x-qty обновит своё состояние; detail — чтобы не отправить это изменение снова
            input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { fromServer: true } }));
        }

        await refresh();
    } catch {
        form.submit();
    }
}

async function select(checkbox) {
    try {
        const data = await post(`/order/select/${checkbox.dataset.cartSelect}`, { selected: checkbox.checked ? 1 : 0 });

        updateHeader(data.cart);
        message(checkbox, data.ok ? '' : data.message);

        if (! data.ok) {
            checkbox.checked = false;
        }

        await refresh();
    } catch {
        location.reload();
    }
}

async function selectAll(checkbox) {
    try {
        const data = await post('/order/select-all', { selected: checkbox.checked ? 1 : 0 });

        updateHeader(data.cart);
        await refresh();
    } catch {
        location.reload();
    }
}

async function remove(form) {
    // Закрыть окно подтверждения до того, как строка исчезнет (снимается блокировка страницы)
    if (window.Alpine) {
        window.Alpine.$data(form).open = false;
    }

    try {
        const data = await post(form.action);

        updateHeader(data.cart);
        await refresh();
    } catch {
        form.submit();
    }
}

document.addEventListener('change', (event) => {
    if (! page()) {
        return;
    }

    const target = event.target;

    if (target.matches('[data-cart-select]')) {
        select(target);

        return;
    }

    if (target.matches('[data-cart-select-all]')) {
        selectAll(target);

        return;
    }

    const form = target.closest('form[data-cart-qty]');

    if (form && ! event.detail?.fromServer) {
        clearTimeout(timers.get(form));
        timers.set(form, setTimeout(() => changeQuantity(form), DEBOUNCE));
    }
});

document.addEventListener('submit', (event) => {
    if (! page()) {
        return;
    }

    const form = event.target;

    if (form.matches('form[data-cart-qty]')) {
        event.preventDefault();
        clearTimeout(timers.get(form));
        changeQuantity(form);
    } else if (/\/order\/delete\/\d+$/.test(form.getAttribute('action') ?? '')) {
        event.preventDefault();
        remove(form);
    }
});
