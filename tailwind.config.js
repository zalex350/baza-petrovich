import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/*
 * Дизайн-токены UI-кита (resources/views/components, docs/UI_INVENTORY.md).
 * Компоненты используют только эти имена: bg-primary, text-tone-success-text, px-btn-x, rounded-control…
 * Значения совпадают с прежними классами экранов — переход на компоненты не меняет вид.
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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                primary: {
                    DEFAULT: colors.slate[800],
                    hover: colors.slate[700],
                },
                danger: {
                    DEFAULT: colors.red[600],
                    hover: colors.red[700],
                },

                // Нейтральные/смысловые тона: бейджи, алерты, пустые состояния
                tone: {
                    neutral: tone(colors.slate[100], colors.slate[700], colors.slate[300]),
                    info: tone(colors.blue[50], colors.blue[800], colors.blue[300]),
                    success: tone(colors.green[100], colors.green[700], colors.green[400]),
                    warning: tone(colors.yellow[100], colors.yellow[800], colors.yellow[400]),
                    danger: tone(colors.red[100], colors.red[700], colors.red[400]),
                    attention: tone(colors.orange[100], colors.orange[700], colors.orange[300]),
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
            },

            borderRadius: {
                control: '0.5rem',
                card: '1rem',
            },
        },
    },

    plugins: [forms],
};
