# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**smesitel.by** — Laravel 12 e-commerce/catalog platform for Belarusian plumbing fixtures. Full-stack: PHP 8.2 backend (Blade templates), Tailwind CSS + Alpine.js frontend, MySQL 8.0 database, Dockerized with Nginx + PHP-FPM.

## Commands

Корень репозитория — папка с `artisan` (локально `D:\smesitel_by\src`). Все команды ниже — из неё.

**Docker infrastructure is in the repo: `docker-compose.yml` and `docker/`. Run every `docker-compose` command from the repo root** (the project name is pinned to `smesitel_by`, so containers and the `smesitel_by_dbdata` volume are the same as before). DB passwords come from `.env` (`DB_PASSWORD`, `DB_ROOT_PASSWORD`) — значения только там, не в git и не в этом файле. Тестовый стенд — `docs/STAGING.md`.

**Setup (first time):**
```bash
composer setup
# Installs deps, creates .env, generates APP_KEY, migrates DB, runs npm install + build
```

**Development (local, non-Docker):**
```bash
composer dev
# Concurrently runs: php artisan serve, queue:listen, pail (logs), npm run dev (Vite watch)
```

**Frontend build (Docker):**
```bash
docker-compose exec node npm run build
# Required after any Tailwind class changes
```

**Tests — всегда в контейнере `php`:**
```bash
docker-compose exec php php artisan test --filter=CatalogFiltersTest          # один класс / метод
docker-compose exec php php artisan test tests/Feature/CatalogFiltersTest.php # файл
docker-compose exec php php artisan test tests/Feature/Auth                   # модуль (папка)
docker-compose exec php php artisan test --parallel                           # полный прогон, ~20 с
```
SQLite `:memory:`, `BCRYPT_ROUNDS=4`, скомпилированные шаблоны — в `/tmp` контейнера, лог тестов — `null` (`phpunit.xml`). Полный прогон последовательно — ~1 мин, `--parallel` (paratest, по процессу на ядро) — ~20 с; при нехватке памяти — `--parallel --processes=4`.

> **Правила запуска тестов:**
> - **Во время работы** — только тесты по изменённым файлам: `--filter=<Класс|метод>` или путь к файлу теста.
> - **Перед коммитом** — тесты затронутого модуля (папка или несколько связанных классов: например, всё про товар в админке — `--filter='AdminProduct|ProductImage|ProductAttributes'`).
> - **Полный прогон** (`--parallel`) — один раз перед мержем ветки или по просьбе пользователя.
> - **Правки только документации или комментариев** — без тестов.

**Xdebug** в контейнере установлен, но выключен (`xdebug.mode = "off"`) — не замедляет тесты и страницы. Включить на один запуск: `docker-compose exec -e XDEBUG_MODE=coverage php php artisan test --coverage` (или `XDEBUG_MODE=debug`).

**`vendor/` — в томе Docker** (`smesitel_by_vendor`), а не на диске Windows: через bind-mount тысячи файлов зависимостей замедляли тесты в ~3 раза (вместе с шаблонами в `/tmp` и opcache для CLI полный прогон — 470 с → 58 с) и страницы тоже. `composer install/require` — только в контейнере: `docker-compose exec php composer install`. Папка `vendor/` на хосте контейнером больше не обновляется — для подсказок IDE её при необходимости обновить `composer install` на хосте. После пересоздания тома (`docker-compose down -v`) — снова `docker-compose exec php composer install`.

**Code style:**
```bash
docker-compose exec php ./vendor/bin/pint
```

**Migrations:**
```bash
docker-compose exec php php artisan migrate
```

> **Правило бэкапа:** перед любой миграцией, меняющей или переносящей данные, — дамп БД в `D:\smesitel_by\backup_before_<задача>.sql`; проверить, что дамп полный; путь и размер указать в отчёте.
> ```bash
> docker-compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' > ../backup_before_<задача>.sql
> ```
> Дамп полный, если команда завершилась без ошибки, последняя строка файла — `-- Dump completed on …`, а число `CREATE TABLE` совпадает с числом таблиц в БД.

> **Правило:** после мержа любой ветки, содержащей новую миграцию, обязательно выполнить `docker-compose exec php php artisan migrate` на контейнере — до того, как считать задачу завершённой. Тесты используют SQLite in-memory и не проверяют состояние боевой MySQL; без этого шага новые колонки отсутствуют в БД и INSERT падает с `Column not found`.

## Architecture

### Routing
Routes are split by domain into separate files in `routes/`:
- `admin.php` — admin panel routes (requires `auth` + `admin` middleware), prefix `/admin`
- `web.php` — public pages (home, product detail)
- `catalog.php`, `search.php`, `cart.php`, `orders.php`, `profile.php`, `auth.php`

Полная карта «URL → контроллер → шаблон» — `docs/UI_INVENTORY.md`.

### Controllers
- `app/Http/Controllers/Admin/` — `AdminProductController`, `AdminOrderController` и др.
- Public controllers: `CatalogController`, `ProductController`, `SearchController`, `CartController`, `OrderController`, `HomeController`

### Models & Relationships
```
Product → has_many ProductImage, belongs_to Category
Order → belongs_to User, belongs_to Organization, has_many OrderItem
OrderItem → belongs_to Order, belongs_to Product
User → belongs_to Organization (nullable), has_many Order
```

### Views (Blade)
Templates in `resources/views/`. Страницы — `@extends('layouts.app')` (или `layouts.admin`) + `@section('content')`.

**UI-кит — `resources/views/components/`** (анонимные Blade-компоненты): `x-button`, `x-input`, `x-select`, `x-textarea`, `x-checkbox`, `x-field`, `x-table` (+ `x-table.th`, `x-table.td`), `x-badge`, `x-alert`, `x-alert.flash`, `x-empty-state`, `x-confirm`, `x-price`, `x-pagination` (вместо `->links()`); для витрины — `x-breadcrumbs`, `x-stock` (пороги `shop.stock_many` / `shop.stock_few`), `x-qty` (кратность `min_qty` / `qty_step` / `default_qty` товара), `x-product-badge` (ХИТ, НОВИНКА), `x-logo`. Эталон вида — макеты `docs/design/*.dc.html` (только референс: формат x-dc/sc-for в проект не переносить). Описание параметров — в комментарии в начале каждого файла. Пример экрана — `admin/orders.blade.php`.

### File Storage
User uploads go to `storage/app/public/` (accessible via `/storage/...` URLs via symlink):
- `products/` — product images (referenced as `asset('storage/'.$product->image)`)

## Conventions

**Blade templates:**
- **Элементы UI — только компоненты из `resources/views/components`** (`x-button`, `x-input`, `x-table`, `x-badge`, `x-alert`, `x-price`, `x-confirm` и т.д.), без локальной разметки кнопок, полей, таблиц, бейджей и сообщений.
- **`@include` — только для крупных блоков страницы** (`partials/order_items`, `partials/header` и т.п.).
- **Новые элементы — добавлять в кит**, не делать локально на экране.
- Подтверждение действий — `x-confirm`, не `onclick="return confirm(...)"`. Суммы — `x-price` (`App\Support\Money`: `1 234,50 BYN`). Flash-сообщения выводят layouts (`x-alert.flash`) — на экранах не подключать.
- **При переводе формы на `x-input`/`x-select`** (и `x-textarea`) общий список ошибок заменяется одним alert «Проверьте выделенные поля»: в шаблоне экрана `@section('flash-validation', 'summary')`, тексты ошибок остаются только у полей. Пока на экране есть формы не на ките — оставлять список (по умолчанию). Пример — `admin/orders.blade.php`.
- Breeze-компоненты (`x-primary-button`, `x-text-input`, `x-input-label`, `x-input-error`, `x-guest-layout` и др.) — наследие auth/профиля; в новом коде не использовать.

**Tailwind:** цвета, отступы и радиусы — токены в `tailwind.config.js` (`bg-primary`, `bg-danger`, `bg-tone-success-bg`, `bg-status-new-bg`, `px-btn-x`, `px-cell-x`, `rounded-control`, `rounded-card`, `p-card`); новые значения — туда же, а не произвольными классами. Цвета статусов заказа — одна карта: `OrderStatus::color()` → токены `status.*`.
Экраны, ещё не переведённые на кит, используют прежние классы: карточки `bg-white rounded-2xl border border-slate-200`, таблицы `divide-y divide-slate-200`, основные кнопки `bg-slate-800 hover:bg-slate-700 text-white`.

**Testing:** Feature tests use `RefreshDatabase` trait; tests run against SQLite in-memory (configured in `phpunit.xml`), not MySQL. SQLite в тестах не приводит кириллицу к нижнему регистру (`LOWER`, `LIKE`) — в тестах поиска без учёта регистра используйте латиницу. Компоненты кита — `tests/Feature/Ui`. Правила, когда какие тесты запускать, — в разделе Commands → Tests.

**Docker:** App runs on `http://localhost:8090`. MySQL exposed on port 3307. The `node` container handles frontend builds.

## Environment

Key `.env` values for Docker setup:
```
DB_HOST=mysql  # Docker service name
DB_PORT=3306
DB_DATABASE=smesitel
SESSION_DRIVER=database
QUEUE_CONNECTION=database   # воркер — контейнер queue; после правок PHP: docker-compose restart queue
CACHE_STORE=database
MAIL_MAILER=smtp             # локально — Mailpit: MAIL_HOST=mailpit, MAIL_PORT=1025, письма — http://localhost:8025
APP_NAME=smesitel.by         # отправитель и подпись писем (MAIL_FROM_NAME="${APP_NAME}")
```
На стенде почта — `MAIL_MAILER=log`, переменные — `docs/STAGING.md`.
