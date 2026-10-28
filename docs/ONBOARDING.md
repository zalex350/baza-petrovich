# Онбординг: проект с нуля на Windows + Docker

Шаги от чистой машины до работающего сайта с демо-данными и зелёными тестами. Что как устроено, описано в `docs/ARCHITECTURE.md` и `docs/MODULES.md`.

## 1. Что установить

| Что | Зачем |
|---|---|
| Docker Desktop (WSL 2) | Все сервисы: php, nginx, mysql, node, queue, mailpit |
| Git for Windows | Клон и Git Bash (команды ниже — для него; в PowerShell те же, кроме `cp`) |
| Редактор (PhpStorm / VS Code) | — |

PHP, Composer и Node на хост ставить не нужно: всё выполняется в контейнерах. Окончания строк в репозитории — LF (`.gitattributes`).

## 2. Клон и `.env`

```bash
git clone https://github.com/zalex350/smesitel_by.git src
cd src                       # корень репозитория — папка с artisan и docker-compose.yml
cp .env.example .env
```

В `.env` заполнить пароли БД. Это любые значения для своей машины, не пароли стенда:

| Переменная | Значение |
|---|---|
| `DB_PASSWORD` | любой пароль пользователя `smesitel` |
| `DB_ROOT_PASSWORD` | любой пароль root MySQL |
| `APP_PORT`, `FORWARD_DB_PORT` | при занятых портах (по умолчанию 8090 и 3307) |

Без паролей `docker-compose` не стартует. Остальное в `.env.example` уже настроено под Docker: `DB_HOST=mysql`, Mailpit, очередь и кэш в БД.

## 3. Запуск

Все команды `docker-compose` — из корня репозитория.

```bash
docker-compose up -d --build                         # первый раз — сборка образа php
docker-compose exec php composer install             # vendor — в томе Docker, не на диске
docker-compose exec php php artisan key:generate
docker-compose exec php php artisan migrate
docker-compose exec php php artisan storage:link     # внутри контейнера, иначе ссылка на Windows не работает
docker-compose exec node npm install
docker-compose exec node npm run build
```

Сайт: http://localhost:8090. MySQL снаружи: `localhost:3307`.

> `composer setup` из `composer.json` рассчитан на запуск без Docker (`npm` на хосте). В Docker используйте шаги выше.

## 4. Демо-данные и учётки

Каталог пустой, пока не загружены данные. Для разработки есть два варианта:

| Вариант | Команда | Что даёт |
|---|---|---|
| Демо-стенд | `docker-compose exec php php artisan db:seed --class=DemoSeeder --force` | Администратор, 2 организации с адресами, клиенты, заблокированный, 3 демо-товара, заказы во всех статусах и с запросом на отмену |
| Каталог | импорт `catalog.db` (§7) | ~11 тыс. реальных товаров, категории, характеристики, фильтры |

`DemoSeeder` работает только при `APP_ENV` = `local`, `staging` или `testing`. Повторный запуск данные не дублирует. Удалить демо-данные — `php artisan demo:clear`.

Демо-учётки (`@demo.test`):

| Роль | Email |
|---|---|
| Администратор | `admin@demo.test` |
| Клиент ООО «Аква Строй» | `ivanov@demo.test`, `petrova@demo.test` |
| Клиент ЧУП «Сантех-Опт» | `sidorenko@demo.test` |
| Заблокированный | `blocked@demo.test` |

**Паролей в репозитории нет.** `DemoSeeder` генерирует новые при каждом запуске и выводит их в консоль один раз, поэтому их стоит сразу сохранить. Пароли стенда у каждого свои, их выдаёт менеджер проекта.

Своя учётка без демо-данных:

```bash
docker-compose exec php php artisan user:create "Имя" me@example.com --role=admin --generate-password
```

Подробнее о `user:create` — в `README.md`. Самостоятельной регистрации на сайте нет.

## 5. Тесты

```bash
docker-compose exec php php artisan test --parallel                 # полный прогон, ~20–60 с
docker-compose exec php php artisan test --filter=CartValidatorTest # один класс
docker-compose exec php php artisan test tests/Feature/Auth         # папка
```

Тесты используют SQLite `:memory:` и не трогают MySQL. При нехватке памяти — `--parallel --processes=4`. Когда какие тесты запускать — `CLAUDE.md` → Commands → Tests.

## 6. Почта и очередь

| Что | Где |
|---|---|
| Письма | Mailpit: http://localhost:8025 (наружу ничего не уходит) |
| Очередь | контейнер `queue` (`queue:work`), `QUEUE_CONNECTION=database` |
| После правок PHP | `docker-compose restart queue`: воркер держит код в памяти |
| Логи воркера | `docker-compose logs -f queue` |
| Упавшие задачи | `docker-compose exec php php artisan queue:failed` |

Проверить: оформить заказ под клиентом. Администратору с включённой галочкой «Новый заказ» (карточка пользователя в админке) придёт письмо в Mailpit.

## 7. Импорт каталога из `catalog.db`

`catalog.db` (SQLite, ~21 МБ) не хранится в git, его выдаёт менеджер проекта. Положите файл вне репозитория, например в `D:\smesitel_by\data\catalog.db`. Маппинги лежат в `database/data/catalog/*.php`, решения описаны в `docs/CATALOG_ANALYSIS.md`.

```bash
# 1. файл — в контейнер (путь Windows для docker cp)
docker cp D:/smesitel_by/data/catalog.db smesitel_by-php-1:/tmp/catalog.db

# 2. пробный прогон: отчёт docs/catalog/import-report.md, БД не меняется
MSYS_NO_PATHCONV=1 docker-compose exec php php artisan catalog:import-db /tmp/catalog.db --dry-run

# 3. бэкап БД (правило бэкапа — CLAUDE.md), затем дамп — в контейнер
docker-compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' > ../backup_before_catalog_import.sql
docker cp ../backup_before_catalog_import.sql smesitel_by-php-1:/tmp/backup.sql

# 4. боевой прогон — только с полным дампом
MSYS_NO_PATHCONV=1 docker-compose exec php php artisan catalog:import-db /tmp/catalog.db --backup=/tmp/backup.sql
```

`MSYS_NO_PATHCONV=1` нужен в Git Bash, иначе `/tmp/…` превратится в путь Windows. Повторный dry-run после импорта должен показать 0 изменений. Цены и остатки в `catalog.db` нет: импортированные товары без цены, а новые создаются черновиками.

## 8. Правила работы

Все правила — в **`CLAUDE.md`**:

| Тема | Раздел `CLAUDE.md` |
|---|---|
| Ветки, мерж (`merge --no-ff` → migrate → тесты → build → push) | «Правило „мержи“» |
| Какие тесты когда запускать | Commands → Tests |
| Бэкап перед миграцией, меняющей данные | «Правило бэкапа» |
| `migrate` после мержа ветки с миграцией | Migrations |
| UI только компонентами кита, Tailwind-токены, форматы цен и дат | Conventions |

После `git pull` — что выполнить в зависимости от изменений: `README.md` → таблица «После `git pull`».

## 9. Стенд и бэклог

| Что | Где |
|---|---|
| Деплой на тестовый стенд, бэкапы, импорт на стенде | `docs/STAGING.md` |
| Как заказчику проверять стенд | `docs/TEST_GUIDE.md` |
| Что не сделано (коды клиентов, документы товара) | `docs/BACKLOG.md` |
| Карта экранов «URL → контроллер → шаблон» | `docs/UI_INVENTORY.md` |

## 10. Частые проблемы

| Симптом | Решение |
|---|---|
| `docker-compose` ругается на `DB_PASSWORD не задан` | Заполнить пароли в `.env` (§2) |
| `Class … not found` после смены ветки | `docker-compose exec php composer dump-autoload` (или `composer install`, если менялся `composer.lock`) |
| Нет стилей, или новые классы Tailwind не применились | `docker-compose exec node npm run build` |
| `Column not found` после `git pull` | `docker-compose exec php php artisan migrate` |
| Письма не приходят | Работает ли контейнер `queue` (`docker-compose ps`), `docker-compose restart queue` |
| Фото не открываются (404 `/storage/…`) | `docker-compose exec php php artisan storage:link` |
| Пустой `vendor` после `docker-compose down -v` | `docker-compose exec php composer install` |
