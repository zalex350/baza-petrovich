<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Локальная разработка

Приложение работает в Docker: `http://localhost:8090`, MySQL на порту 3307.

Инфраструктура лежит в репозитории: `docker-compose.yml` и `docker/` (php — Dockerfile с GD
JPEG/WebP и php.ini, nginx — default.conf). **Все команды `docker-compose` запускаются из корня
репозитория** (папка с `artisan` и `docker-compose.yml`), а не из папки выше.

Пароли и имена БД — только в `.env` (не в git). Перед первым запуском скопируйте `.env.example`
в `.env` и задайте `DB_PASSWORD` и `DB_ROOT_PASSWORD` — без них compose не стартует. Порты
меняются через `APP_PORT` и `FORWARD_DB_PORT`.

```bash
cp .env.example .env            # первый раз; заполнить пароли
docker-compose up -d --build    # --build — после изменений в docker/php
docker-compose exec php composer install
docker-compose exec php php artisan key:generate   # первый раз
docker-compose exec php php artisan migrate
docker-compose exec php php artisan storage:link   # внутри контейнера: на Windows-томе так создаётся рабочая ссылка
docker-compose exec node npm install
docker-compose exec node npm run build
```

Имя compose-проекта зафиксировано (`name: smesitel_by`): контейнеры `smesitel_by-*` и том
`smesitel_by_dbdata` не зависят от имени папки.

### Зависимости PHP

**`vendor/` — в томе Docker `smesitel_by_vendor`**, а не в папке проекта на диске: через
bind-mount Windows тысячи файлов зависимостей в разы замедляли тесты и страницы. Код
приложения по-прежнему подключён из папки проекта — правки видны сразу.

- **composer — только внутри контейнера:**
  ```bash
  docker-compose exec php composer install
  docker-compose exec php composer require vendor/package       # --dev для dev-зависимостей
  docker-compose exec php composer update vendor/package
  ```
  `composer.json` и `composer.lock` лежат в папке проекта — изменения попадают в git как обычно.
- Папку `vendor/` на хосте контейнер **не видит и не обновляет**. Она нужна только для
  подсказок в IDE; при желании обновить — `composer install` на хосте. Для работы сайта
  и тестов она не используется.
- Том пуст после первого запуска или после `docker-compose down -v` — выполнить
  `docker-compose exec php composer install`.

**После `git pull`** — по тому, что изменилось (`git diff --name-only ORIG_HEAD HEAD`):

| Изменилось | Выполнить |
|---|---|
| `composer.lock` | `docker-compose exec php composer install` |
| `docker/php/` (Dockerfile, php.ini) | `docker-compose up -d --build php`, затем `docker-compose exec php composer install` |
| `database/migrations/` | `docker-compose exec php php artisan migrate` |
| `package-lock.json` | `docker-compose exec node npm ci` |
| `resources/` (шаблоны, CSS, JS) или `package-lock.json` | `docker-compose exec node npm run build` |
| `config/`, `.env` | `docker-compose exec php php artisan config:clear` |

Проверка после обновления: `docker-compose exec php php artisan test --parallel`.

### Фото товаров

Хранятся на диске из `SHOP_IMAGES_DISK` (по умолчанию `public` — `storage/app/public`,
нужна ссылка `public/storage`, см. `storage:link` выше). Если на хостинге symlink недоступен —
`SHOP_IMAGES_DISK=public_media`: файлы лежат прямо в `public/media`, ссылка не нужна
(при переключении перенести папку `products/` из `storage/app/public/` в `public/media/`).

### Создание пользователей

Самостоятельная регистрация отключена (SRS §3), учётные записи создаются командой `user:create`. Передавайте все данные аргументами:

```bash
php artisan user:create "Имя" email@example.com --role=client --organization=1 --generate-password
# в Docker:
docker-compose exec php php artisan user:create "Имя" email@example.com --role=client --organization=1 --generate-password
```

- `--role` — `client` или `admin`;
- `--organization` — ID организации (раздел «Организации» в админке); обязателен для `client`, для `admin` — необязателен;
- `--generate-password` — сгенерировать пароль; он выводится в консоль один раз;
- вместо генерации можно задать пароль: `--password=...` (не короче 8 символов).

> **Внимание:** интерактивный ввод (запуск без аргументов) в PowerShell через `docker-compose exec` может приходить с «мусором» — лишними управляющими символами или в неверной кодировке. Из-за этого возможны ошибки валидации email/пароля. Используйте запуск с аргументами, как в примере выше.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
