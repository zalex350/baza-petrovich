// Аналитика витрины: window.track(event, params) — одно событие во все подключённые счётчики.
//
// Настройки — window.__analytics (partials/analytics, админка «Настройки» → Аналитика); нет —
// track() ничего не делает. Куда уходит событие:
// - dataLayer (Google Tag Manager): {event, ecommerce} для событий магазина, {event, ...params} для остальных;
// - gtag (без GTM): GA4 — событие как есть; Google Ads — конверсия по метке (покупка, заявка);
// - Яндекс Метрика — цель reachGoal(event), Вебвизор — запись визитов;
// - Microsoft Clarity — clarity('event', event).
// События страницы с сервера — <script type="application/json" data-track-event> (компонент x-track).
// Клики по телефону, email и мессенджерам — click_phone, click_email, click_messenger.

const ECOMMERCE = ['view_item', 'view_item_list', 'select_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'purchase'];
const CONSENT_COOKIE = 'cookie_consent';

const config = () => window.__analytics ?? null;

function loadScript(src) {
    const s = document.createElement('script');
    s.async = true;
    s.src = src;
    document.head.appendChild(s);
}

let ymLoaded = false;
let clarityLoaded = false;

function loadYandex(id) {
    if (ymLoaded || ! id) {
        return;
    }
    ymLoaded = true;
    window.ym = window.ym || function () {
        (window.ym.a = window.ym.a || []).push(arguments);
    };
    window.ym.l = Date.now();
    loadScript('https://mc.yandex.ru/metrika/tag.js');
    window.ym(Number(id), 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true, ecommerce: 'dataLayer' });
}

function loadClarity(id) {
    if (clarityLoaded || ! id) {
        return;
    }
    clarityLoaded = true;
    window.clarity = window.clarity || function () {
        (window.clarity.q = window.clarity.q || []).push(arguments);
    };
    loadScript('https://www.clarity.ms/tag/' + encodeURIComponent(id));
}

function startTrackers(a) {
    loadYandex(a.ym);
    loadClarity(a.clarity);
}

export function track(event, params = {}) {
    const a = config();

    if (! a || ! event) {
        return;
    }

    window.dataLayer = window.dataLayer || [];

    if (ECOMMERCE.includes(event)) {
        const ecommerce = { currency: a.currency, ...params };
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({ event, ecommerce });
    } else {
        window.dataLayer.push({ event, ...params });
    }

    // Без GTM — напрямую в GA4 и Google Ads (с GTM теги настраиваются в контейнере по событиям dataLayer)
    if (! a.gtm && typeof window.gtag === 'function') {
        if (a.ga4) {
            window.gtag('event', event, ECOMMERCE.includes(event) ? { currency: a.currency, ...params } : params);
        }

        const label = a.adsLabels?.[event];

        if (a.ads && label) {
            window.gtag('event', 'conversion', {
                send_to: `${a.ads}/${label}`,
                value: params.value ?? 1,
                currency: params.currency ?? a.currency,
                transaction_id: params.transaction_id ?? '',
            });
        }
    }

    if (a.ym && typeof window.ym === 'function') {
        window.ym(Number(a.ym), 'reachGoal', event, params.value ? { order_price: params.value, currency: a.currency } : undefined);
    }

    if (a.clarity && typeof window.clarity === 'function') {
        window.clarity('event', event);
    }
}

window.track = track;

// Событие один раз в браузере (покупка: обновление страницы заказа не дублирует конверсию)
function once(key) {
    try {
        const sent = JSON.parse(localStorage.getItem('track-once') ?? '[]');

        if (sent.includes(key)) {
            return false;
        }
        localStorage.setItem('track-once', JSON.stringify([...sent, key].slice(-50)));
    } catch {
        // Без localStorage — отправляем
    }

    return true;
}

function pageEvents() {
    document.querySelectorAll('script[data-track-event]').forEach((el) => {
        let params = {};

        try {
            params = JSON.parse(el.textContent || '{}');
        } catch {
            return;
        }

        const key = el.dataset.trackOnce;

        if (! key || once(`${el.dataset.trackEvent}:${key}`)) {
            track(el.dataset.trackEvent, params);
        }
    });
}

// Телефон, email, мессенджеры — конверсии «звонок» и «написал»
const MESSENGERS = /^(viber:|tg:|whatsapp:)|^https?:\/\/(t\.me|telegram\.me|wa\.me|api\.whatsapp\.com|invite\.viber\.com|vk\.com|instagram\.com)\//i;

function linkClicks() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]');

        if (! link) {
            return;
        }

        const href = link.getAttribute('href') ?? '';
        const where = link.closest('[data-track-area]')?.dataset.trackArea ?? (link.closest('header') ? 'header' : link.closest('footer') ? 'footer' : 'content');

        if (href.startsWith('tel:')) {
            track('click_phone', { phone: href.slice(4), link_area: where });
        } else if (href.startsWith('mailto:')) {
            track('click_email', { link_area: where });
        } else if (MESSENGERS.test(href)) {
            track('click_messenger', { messenger: link.hostname || href.split(':')[0], link_area: where });
        }
    }, { capture: true });
}

// Начало оформления — первое касание формы заказа (форма на странице корзины)
function checkoutStart() {
    const form = document.getElementById('checkout-form');
    const data = document.querySelector('script[data-checkout-items]');

    if (! form || ! data) {
        return;
    }

    let sent = false;
    const send = () => {
        if (sent) {
            return;
        }
        sent = true;

        try {
            track('begin_checkout', JSON.parse(data.textContent || '{}'));
        } catch {
            // Нет данных корзины — без события
        }
    };

    form.addEventListener('focusin', send, { once: true });
    form.addEventListener('submit', send, { once: true });
}

// Баннер cookie: выбор — cookie на год; согласие включает cookie Google и загружает Метрику и Clarity
function consentBanner(a) {
    const banner = document.querySelector('[data-cookie-banner]');
    const saved = (document.cookie.match(new RegExp(`(?:^|; )${CONSENT_COOKIE}=(granted|denied)`)) || [])[1];

    const choose = (state) => {
        document.cookie = `${CONSENT_COOKIE}=${state}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
        a.granted = state === 'granted';

        if (typeof window.gtag === 'function') {
            window.gtag('consent', 'update', { ad_storage: state, ad_user_data: state, ad_personalization: state, analytics_storage: state });
        }

        if (a.granted) {
            startTrackers(a);
        }

        window.dataLayer?.push({ event: 'cookie_consent', consent: state });

        if (banner) {
            banner.hidden = true;
        }
    };

    if (banner && ! saved) {
        banner.hidden = false;
        banner.querySelector('[data-cookie-accept]')?.addEventListener('click', () => choose('granted'));
        banner.querySelector('[data-cookie-decline]')?.addEventListener('click', () => choose('denied'));
    }
}

function init() {
    const a = config();

    if (! a) {
        return;
    }

    if (a.granted) {
        startTrackers(a);
    }

    consentBanner(a);
    pageEvents();
    linkClicks();
    checkoutStart();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
