import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/*
 * Дизайн-токены UI-кита (resources/views/components, docs/UI_INVENTORY.md).
 * Компоненты используют только эти имена: bg-primary, text-tone-success-text, px-btn-x, rounded-control…
 * Значения — из макетов docs/design (фон, поверхность, граница, текст, смысловые цвета, радиусы 8/10/12).
 * Экраны, ещё не переведённые на кит, пока используют прежние классы slate-*.
 */
const tone = (bg, text, border) => ({ bg, text, border });

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Классы бейджей статусов: OrderStatus::color(), OrderHistoryEvent::color()
        './app/Enums/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Inter подключается из Google Fonts в layouts (app, admin, guest)
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // Основа: фон страницы, поверхность карточек, граница, текст
                canvas: '#F5F6F8',
                surface: '#FFFFFF',
                line: {
                    DEFAULT: '#E3E6EB',
                    // Границы полей ввода и количества
                    strong: '#D5DAE1',
                    // Граница вторичной кнопки
                    control: '#C9D0DA',
                    // Пустая полоска индикатора наличия
                    empty: '#D9DEE5',
                    // Разделители строк внутри карточки (список товаров)
                    soft: '#EDF0F3',
                },
                // Подложка фото товара в списках
                media: '#F1F3F6',
                // Чипы выбранных фильтров
                chip: '#E8EEF9',
                // Строка корзины, снятая с заказа из-за нехватки остатка
                'warn-row': '#FFFBF3',
                // Фон поля поиска, шапки таблиц, кнопок «−/+»
                field: '#F9FAFB',
                ink: {
                    DEFAULT: '#111827',
                    muted: '#5B6472',
                },
                link: {
                    DEFAULT: '#1D5FD1',
                    hover: '#174BA6',
                },

                primary: {
                    DEFAULT: '#1E2B45',
                    hover: '#2C3D5E',
                    // Текст на тёмном фоне (верхняя полоса шапки)
                    fg: '#DDE3EE',
                },
                danger: {
                    DEFAULT: '#B42318',
                    hover: '#912018',
                },
                // Яркие смысловые цвета (индикатор наличия, иконки); текст на светлом фоне — tone.*.text
                success: '#1F8A4C',
                warning: '#C27803',

                // Нейтральные/смысловые тона: бейджи, алерты, пустые состояния
                tone: {
                    neutral: tone('#F0F2F5', '#5B6472', '#E3E6EB'),
                    info: tone('#EEF4FD', '#1D5FD1', '#C6D8F6'),
                    success: tone('#E8F3EC', '#146C34', '#B5DEC4'),
                    warning: tone('#FFF6E5', '#8A4B00', '#F3D9A6'),
                    danger: tone('#FEF3F2', '#B42318', '#F5C2BD'),
                    attention: tone(colors.orange[100], colors.orange[700], colors.orange[300]),
                },

                // Бейджи товара (x-product-badge)
                badge: {
                    hit: tone('#FDECEC', '#9B1C1C'),
                    new: tone('#E8EEF9', '#1E3A8A'),
                    // «%» — акция
                    promo: tone('#FDF0E1', '#9A3412'),
                },

                // Единая карта цветов статусов заказа (App\Enums\OrderStatus::color())
                status: {
                    new: tone(colors.blue[100], colors.blue[700]),
                    confirmed: tone(colors.cyan[100], colors.cyan[700]),
                    sent: tone(colors.indigo[100], colors.indigo[700]),
                    progress: tone(colors.yellow[100], colors.yellow[700]),
                    shipped: tone(colors.purple[100], colors.purple[700]),
                    completed: tone(colors.green[100], colors.green[700]),
                    cancelled: tone(colors.slate[200], colors.slate[600]),
                },
            },

            fontSize: {
                // x-logo: шапка / мобильная шапка
                logo: ['22px', { lineHeight: '1.2', letterSpacing: '-0.02em' }],
                'logo-sm': ['19px', { lineHeight: '1.2', letterSpacing: '-0.02em' }],
                // Верхняя полоса шапки, подписи
                meta: ['13px', { lineHeight: '1.4' }],
                // Счётчик корзины
                counter: ['11px', { lineHeight: '16px' }],
                // Цена в карточке товара
                price: ['32px', { lineHeight: '1.15', letterSpacing: '-0.01em' }],
            },

            aspectRatio: {
                // Баннер главной: десктоп 1320×400, телефон 3:2
                banner: '33 / 10',
                'banner-mobile': '3 / 2',
            },

            maxWidth: {
                // Ширина контента витрины (макеты: 1320px)
                page: '1320px',
            },

            spacing: {
                // Поля ввода
                'control-x': '0.75rem',
                'control-y': '0.5rem',
                // Кнопки: md и sm
                'btn-x': '1.25rem',
                'btn-y': '0.5rem',
                'btn-x-sm': '0.75rem',
                'btn-y-sm': '0.375rem',
                // Ячейки таблиц
                'cell-x': '1rem',
                'cell-y': '0.75rem',
                // Внутренний отступ карточек, алертов, модалок
                card: '1.5rem',
                // Высота полей и кнопок шапки, поля количества (44px — удобно пальцем)
                touch: '2.75rem',
                // Ширина плитки в карусели главной: телефон — две с краем следующей, sm, lg
                'carousel-item': '44vw',
                'carousel-item-sm': '13.5rem',
                'carousel-item-lg': '14.75rem',
            },

            gridTemplateColumns: {
                // Строка товара (x-product-row): наличие | цена | покупка — одинаковые колонки во всех строках
                'product-row': '7.5rem 9rem 19rem',
                // Покупка в строке (x-product-row.buy): количество | кнопка
                'product-buy': '8.5rem 10rem',
            },

            borderRadius: {
                // Полоски индикатора наличия
                bar: '1px',
                // Бейджи товара ХИТ / НОВИНКА
                tag: '4px',
                // Поля, кнопки
                control: '8px',
                // Крупные кнопки и поле количества (48px), выпадающие панели, алерты
                panel: '10px',
                // Карточки и модалки
                card: '12px',
            },
        },
    },

    plugins: [forms],
};
