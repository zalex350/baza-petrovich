import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // editor.js — только на форме страницы в админке (Trix), не в общей сборке
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/editor.js'],
            refresh: true,
        }),
    ],
});
