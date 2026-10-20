# Тестовый стенд test.smesitel.by

Сервер hoster.by, Ubuntu, Docker. Всё ниже — от пользователя **deploy** в папке проекта,
если не сказано иное.

| Что | Где |
|---|---|
| Код (git, ветка `master`) | `/home/deploy/smesitel_by` |
| Настройки | `/home/deploy/smesitel_by/.env` (владелец `deploy:www-data`, права `640`) |
| Контейнеры | `docker-compose.staging.yml`, проект `smesitel_by`: `php`, `nginx`, `mysql` |
| БД MySQL 8 | том `smesitel_by_dbdata`, наружу не открыта |
| Фото, логи, сессии | том `smesitel_by_storage` (`/var/www/storage` в контейнерах) |
| HTTPS, пароль на сайт | nginx сервера: `/etc/nginx/sites-enabled/smesitel`, certbot, `/etc/nginx/.htpasswd` |
| Сайт внутри сервера | `http://127.0.0.1:8090` (nginx сервера проксирует сюда) |
| Бэкапы БД | `/home/deploy/backups`, последние 10: перед каждым деплоем и ежедневно в 03:30 (cron) |

Код, PHP-зависимости и собранный фронт — **внутри образов** (`docker/staging/Dockerfile`).
Папка проекта на сервере нужна только для `git pull`, сборки и `.env`; правки файлов
на сервере на сайт не попадают — только через git и `./deploy.sh`.

### Где собирается фронт

На сервере, при каждом деплое, в **одноразовом node-контейнере** — это стадия `assets`
в `docker/staging/Dockerfile` (`node:22`: `npm ci && npm run build`). Контейнер живёт только
во время `docker compose build`; в работающем стенде node нет. Готовый `public/build`
копируется в образы `php` и `nginx`. Так же, без dev-пакетов, ставится `composer install
--no-dev` (стадия `vendor`). На сервере не нужны ни node, ни composer, ни PHP — только Docker.

### Права на файлы

Всё в папке проекта — пользователя **deploy**: `git pull`, `./deploy.sh`, cron — от него.
От root git откажется (`detected dubious ownership`) — запускать `sudo -u deploy …`
(или, если очень нужно, `git config --global --add safe.directory /home/deploy/smesitel_by`
у того пользователя, от которого запускаете).

`vendor/` и `node_modules/` в папке проекта при прежней схеме ставились в контейнерах
от root. Теперь они не используются (зависимости — в образе), `deploy.sh` о них
предупреждает. Удалить: `sudo rm -rf vendor node_modules public/build`.
`.env` — `deploy:www-data`, `640`; `storage/` в папке проекта после перехода тоже
не используется (данные — в томе `smesitel_by_storage`).

## Обновление

```bash
cd /home/deploy/smesitel_by
./deploy.sh
```

Скрипт по шагам: бэкап БД → `git pull` → сборка образов (`composer install --no-dev`,
`npm ci && npm run build`) → миграции новым образом → перезапуск контейнеров →
`storage:link` → кэши config/route/view → проверка `http://127.0.0.1:8090/up`.

- Ошибка на любом шаге — скрипт останавливается и пишет, на каком. Если до перезапуска —
  стенд продолжает работать на прежней версии.
- Изменённые на сервере файлы под git — обновление не начнётся (`git status`).
- Только бэкап: `./deploy.sh backup`.
- Пересобрать тот же коммит без `git pull`: `SKIP_PULL=1 ./deploy.sh`.

Ошибка `detected dubious ownership in repository` — git запущен не от владельца папки
(например, от root). Запускайте от deploy: `sudo -u deploy ./deploy.sh`.

## Переход с ручной схемы (один раз)

Сейчас стенд запущен из `docker-compose.yml` + ручного `docker-compose.staging.yml`
(bind-mount кода, контейнер node). Переход:

1. В `.env` заменить строку
   `COMPOSE_FILE=docker-compose.yml:docker-compose.staging.yml` на
   `COMPOSE_FILE=docker-compose.staging.yml`.
2. `./deploy.sh`. Скрипт сам:
   - отложит ручной `docker-compose.staging.yml` (не из git) как
     `docker-compose.staging.yml.manual-<дата>` — иначе `git pull` откажется;
   - сделает бэкап БД (том `smesitel_by_dbdata` остаётся тем же — данные сохраняются);
   - перенесёт загруженные фото из `./storage/app/public` в том `smesitel_by_storage`
     (один раз, повторно не копирует);
   - удалит контейнер `node` и пересоздаст `php` и `nginx` без bind-mount.
3. Проверить сайт. После этого папки `vendor/`, `node_modules/`, `public/build/` на сервере
   не нужны (они принадлежат root): `sudo rm -rf vendor node_modules public/build`.

## Установка с нуля

1. **Docker** (с плагином compose v2), пользователь deploy в группе docker:
   `sudo usermod -aG docker deploy` (перелогиниться).
2. **Код:**
   ```bash
   cd /home/deploy
   git clone https://github.com/zalex350/smesitel_by.git
   cd smesitel_by
   ```
3. **`.env`** — из `.env.example`, ключевые значения:
   ```dotenv
   APP_NAME=Smesitel
   APP_ENV=staging
   APP_DEBUG=false
   APP_URL=https://test.smesitel.by
   APP_LOCALE=ru

   LOG_CHANNEL=stack
   LOG_STACK=single
   LOG_LEVEL=info

   DB_CONNECTION=mysql
   DB_HOST=mysql
   DB_PORT=3306
   DB_DATABASE=smesitel
   DB_USERNAME=smesitel
   DB_PASSWORD=<пароль>
   DB_ROOT_PASSWORD=<пароль root>

   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=sync
   MAIL_MAILER=log

   COMPOSE_FILE=docker-compose.staging.yml
   ```
   Права: `chown deploy:www-data .env && chmod 640 .env` (php-fpm в контейнере читает его
   как www-data).
4. **APP_KEY:** собрать образ и сгенерировать ключ, вписать в `.env`:
   ```bash
   docker compose build
   docker compose run --rm --no-deps php php artisan key:generate --show
   ```
5. **Запуск:** `./deploy.sh` (бэкап на первом запуске пропускается — БД ещё нет),
   затем ежедневный бэкап: `./deploy.sh install-cron`.
6. **nginx сервера** — `/etc/nginx/sites-available/smesitel` (ссылка в `sites-enabled`):
   ```nginx
   server {
       server_name test.smesitel.by;
       client_max_body_size 210M;

       auth_basic "Staging";
       auth_basic_user_file /etc/nginx/.htpasswd;

       location / {
           proxy_pass http://127.0.0.1:8090;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_read_timeout 120s;
       }
       listen 80;
   }
   ```
   Пароль на сайт: `sudo htpasswd -c /etc/nginx/.htpasswd <логин>` (пакет `apache2-utils`).
   HTTPS: `sudo certbot --nginx -d test.smesitel.by` — certbot сам допишет `listen 443`
   и редирект с http. `sudo nginx -t && sudo systemctl reload nginx`.
7. **Данные:** демо-учётки и заказы — см. «Демо-данные»; каталог из JSON —
   «Импорт каталога».

## Демо-данные

```bash
docker compose exec php php artisan db:seed --class=DemoSeeder --force
```

Создаёт (повторный запуск не дублирует): администратора, две организации с адресами,
трёх клиентов, заблокированного пользователя, три демо-товара (категория «Демо-товары»),
заказы во всех статусах и заказ с запросом на отмену. **Пароли выводятся в консоль и при
каждом запуске новые** — передайте их тестировщикам. Работает только при `APP_ENV=staging`.

Удалить демо-данные (организации, пользователей `@demo.test`, их заказы и корзины,
демо-товары и категорию «Демо-товары»; каталог и остальные данные не трогаются):

```bash
docker compose exec php php artisan demo:clear          # покажет, что удалит, и спросит
docker compose exec php php artisan demo:clear --force  # без вопроса
```

## Бэкап и восстановление БД

Бэкап делается перед каждым обновлением и **ежедневно по cron**; вручную —
`./deploy.sh backup`.

Ежедневный бэкап включается один раз:

```bash
./deploy.sh install-cron     # 03:30 каждый день, в crontab пользователя deploy
crontab -l                   # проверить
tail -n 50 ~/backups/backup.log
```

Время меняется переменной `BACKUP_CRON` (`BACKUP_CRON="0 2 * * *" ./deploy.sh install-cron`),
повторный запуск строку не дублирует.
Файлы: `/home/deploy/backups/smesitel-<дата>-<коммит>.sql.gz`, хранятся последние 10
(`BACKUP_KEEP`, папка — `BACKUP_DIR`). Бэкапы лежат на том же сервере — важные копии
скачивайте себе: `scp deploy@<сервер>:backups/<файл> .`

Восстановление (текущие данные будут заменены — сначала `./deploy.sh backup`):

```bash
gunzip -c /home/deploy/backups/<файл>.sql.gz \
  | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose exec php php artisan cache:clear
```

Фото и другие загруженные файлы — в томе `smesitel_by_storage`, бэкап тома:

```bash
docker run --rm -v smesitel_by_storage:/data -v "$HOME/backups:/backup" alpine \
  tar czf /backup/storage-$(date +%Y%m%d).tar.gz -C /data app/public
```

## Откат

```bash
git log --oneline -10                 # найти прежний коммит
git checkout <коммит>
SKIP_PULL=1 ./deploy.sh               # собрать и запустить прежнюю версию
```

Если новая версия успела применить миграции, а прежней они мешают, — восстановить БД
из бэкапа, сделанного перед обновлением (его путь `deploy.sh` выводит в конце и при ошибке).
Вернуться на актуальную версию: `git checkout master && ./deploy.sh`.

## Логи и письма

Почта на стенде не отправляется: `MAIL_MAILER=log` — письма (приглашения, восстановление
пароля) записываются в лог приложения целиком, со ссылками.

```bash
docker compose exec php tail -n 200 -f storage/logs/laravel.log   # лог приложения и письма
docker compose exec php grep -n "reset-password" storage/logs/laravel.log | tail   # ссылки из писем
docker compose logs --tail=100 php nginx     # php-fpm и nginx контейнера
docker compose logs --tail=100 mysql
sudo tail -f /var/log/nginx/access.log /var/log/nginx/error.log   # nginx сервера
```

Чтобы письма уходили по-настоящему — задать в `.env` `MAIL_MAILER=smtp` и `MAIL_HOST`,
`MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, затем `SKIP_PULL=1 ./deploy.sh`
(или `docker compose up -d --force-recreate php` — кэш конфигурации пересобирается при старте).

## Полезные команды

```bash
docker compose ps                                   # состояние контейнеров
docker compose exec php php artisan about           # версия, окружение, кэши
docker compose exec php php artisan migrate:status  # миграции
docker compose exec php php artisan tinker          # консоль Laravel
docker compose exec mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'   # консоль MySQL
docker compose restart php nginx                    # перезапуск без пересборки
```

Изменения в `.env` применяются после перезапуска `php` (кэш конфигурации собирается
при старте контейнера).

### Импорт каталога

Файл `catalog_import.json` (формат — `app/Console/Commands/ImportCatalog.php`):

```bash
docker compose exec php mkdir -p storage/app/imports
docker compose cp catalog_import.json php:/var/www/storage/app/imports/catalog_import.json
docker compose exec php php artisan catalog:import
```

### Пользователь-администратор вручную

```bash
docker compose exec php php artisan user:create "Имя Фамилия" admin@example.com --role=admin --generate-password
```
