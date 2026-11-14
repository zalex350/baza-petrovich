# Иконки категорий

Используются `App\Services\CategoryIcons` (поле `categories.icon`), компонентами `x-category-icon`
и `x-category-icon-picker`, превью — `/admin/category-icons`.

| Папка / файл | Что |
| --- | --- |
| `material.json` | Какие иконки Material Symbols есть в проекте: имя → слова для поиска в админке |
| `material/` | Их SVG — копирует `docker-compose exec node npm run icons` из npm-пакета `@material-symbols/svg-300` (Outlined, weight 300; devDependency, на сервер не нужен). Файлы коммитятся; вручную не править |
| `custom.json`, `custom/` | Свои иконки сантехники: 24×24, `fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"`, без своих цветов |

Новая иконка Material: добавить имя и слова в `material.json` → `npm run icons` → закоммитить SVG.
Новая своя: файл в `custom/` в том же стиле + слова в `custom.json` (`CategoryIconsTest` проверяет стиль).

Material Symbols — © Google, лицензия Apache 2.0 (https://github.com/google/material-design-icons/blob/master/LICENSE).
