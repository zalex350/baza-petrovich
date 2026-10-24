// Корзина из списков товаров (x-product-row) без перезагрузки.
//
// - «В корзину» (form[data-cart-add]) — fetch с Accept: application/json; CartController::add
//   отвечает {ok, message, cart, html, toast}: блок покупки строки заменяется на html
//   (x-qty + «В корзине · перейти»), шапка — по cart, сообщение — поверх страницы (toast);
// - количество товара в корзине (form[data-cart-update], x-qty) — fetch на /order/update/{id}
//   после паузы в DEBOUNCE мс; ошибка — прежнее количество и текст в [data-cart-message];
// - не получилось (сеть, не JSON) — обычная отправка формы: сервер вернёт на ту же страницу,
//   позиция прокрутки и flash-сообщение поверх страницы восстанавливаются, как раньше.

const KEY = 'cart-add-scroll';
const DEBOUNCE = 400;
const TOAST_MS = 4000;

const timers = new WeakMap();
let toastTimer = null;

const read = () => {
    try {
        return JSON.parse(sessionStorage.getItem(KEY) ?? 'null');
    } catch {
        return null;
    }
};

// Обычная отправка: запомнить прокрутку, чтобы вернуться на то же место списка
function fallback(form) {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ url: location.href, y: window.scrollY }));
    } catch {
        // Без sessionStorage страница просто откроется сверху
    }

    form.submit();
}

async function send(form) {
    const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    const type = response.headers.get('Content-Type') ?? '';

    if (! type.includes('application/json')) {
        throw new Error(String(response.status));
    }

    return response.json();
}

// Шапка: счётчики (desktop и мобильный), сумма с НДС и подписи ссылок на корзину
export function updateHeader(cart) {
    if (! cart) {
        return;
    }

    document.querySelectorAll('[data-cart-count], [data-cart-count-mobile]').forEach((el) => {
        el.textContent = cart.count;
        el.hidden = cart.count < 1;
    });

    document.querySelectorAll('[data-cart-total]').forEach((el) => {
        el.textContent = cart.total_text;
        el.title = cart.title;
    });

    document.querySelectorAll('[data-cart-link]').forEach((el) => {
        el.setAttribute('aria-label', cart.label);
    });
}

function message(form, text) {
    const box = form.closest('[data-cart-buy]')?.querySelector('[data-cart-message]');

    if (box) {
        box.textContent = text ?? '';
    }
}

function toast(html) {
    const flash = document.querySelector('[data-flash]');

    if (! flash || ! html) {
        return;
    }

    clearTimeout(toastTimer);
    flash.innerHTML = html;
    // Положение и вид — в resources/css/app.css ([data-flash][data-floating])
    flash.dataset.floating = '';
    toastTimer = setTimeout(() => {
        flash.innerHTML = '';
        delete flash.dataset.floating;
    }, TOAST_MS);
}

async function add(form) {
    const button = form.querySelector('button[type="submit"]');

    if (button) {
        button.disabled = true;
    }

    try {
        const data = await send(form);

        if (! data.ok) {
            message(form, data.message);

            return;
        }

        updateHeader(data.cart);
        toast(data.toast);

        // Строка переходит в состояние «В корзине»; Alpine сам подхватит новый x-qty
        const buy = form.closest('[data-cart-buy]');

        if (buy && data.html) {
            buy.outerHTML = data.html;
        }
    } catch {
        fallback(form);
    } finally {
        if (button?.isConnected) {
            button.disabled = false;
        }
    }
}

async function update(form) {
    const input = form.querySelector('input[name="quantity"]');

    try {
        const data = await send(form);

        updateHeader(data.cart);
        message(form, data.ok ? '' : data.message);

        if (input && String(data.quantity) !== input.value) {
            input.value = data.quantity;
            // x-qty обновит своё состояние; detail — чтобы не отправить это изменение снова
            input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { fromServer: true } }));
        }
    } catch {
        fallback(form);
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form.matches('form[data-cart-add]')) {
        event.preventDefault();
        add(form);
    } else if (form.matches('form[data-cart-update]')) {
        event.preventDefault();
        clearTimeout(timers.get(form));
        update(form);
    }
});

// «−/+» и ввод количества: отправка после паузы — серия нажатий даёт один запрос
document.addEventListener('change', (event) => {
    const form = event.target.closest?.('form[data-cart-update]');

    if (! form || event.detail?.fromServer) {
        return;
    }

    clearTimeout(timers.get(form));
    timers.set(form, setTimeout(() => update(form), DEBOUNCE));
});

function restore() {
    const saved = read();

    try {
        sessionStorage.removeItem(KEY);
    } catch {
        // ничего
    }

    if (! saved || saved.url !== location.href) {
        return;
    }

    window.scrollTo(0, saved.y);

    const flash = document.querySelector('[data-flash]');

    if (! flash || ! flash.children.length) {
        return;
    }

    flash.dataset.floating = '';
    setTimeout(() => flash.remove(), TOAST_MS);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore);
} else {
    restore();
}
