// «В корзину» в списках товаров (формы [data-cart-add]).
// Сейчас форма отправляется обычным POST, сервер возвращает на ту же страницу
// (фильтры и пагинация — в URL). Здесь только сохраняется позиция прокрутки и
// flash-сообщение показывается поверх страницы, а не в её начале.
// Для перехода на fetch достаточно заменить обработчик submit: сервер отвечает
// JSON {ok, message} на Accept: application/json, сообщение — в [data-cart-message].

const KEY = 'cart-add-scroll';

const read = () => {
    try {
        return JSON.parse(sessionStorage.getItem(KEY) ?? 'null');
    } catch {
        return null;
    }
};

document.addEventListener('submit', (event) => {
    if (! event.target.matches('form[data-cart-add]')) {
        return;
    }

    try {
        sessionStorage.setItem(KEY, JSON.stringify({ url: location.href, y: window.scrollY }));
    } catch {
        // Без sessionStorage страница просто откроется сверху
    }
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

    // Положение и вид — в resources/css/app.css ([data-flash][data-floating])
    flash.dataset.floating = '';
    setTimeout(() => flash.remove(), 4000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore);
} else {
    restore();
}
