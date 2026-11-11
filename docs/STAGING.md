# Тестовый стенд test.smesitel.by

Сервер hoster.by, Ubuntu, Docker. Всё ниже — от пользователя **deploy** в папке проекта,
если не сказано иное.

| Что | Где |
|---|---|
| Код (git, ветка `master`) | `/home/deploy/smesitel_by` |
| Настройки | `/home/deploy/smesitel_by/.env` (владелец `deploy:www-data`, права `640`) |
| Контейнеры | `docker-compose.staging.yml`, проект `smesitel_by`: `php`, `nginx`, `mysql`, `queue` (воркер очереди: письма, обмен с 1С), `scheduler` (планировщик: архив и проверки обмена с 1С) |
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

### Владелец storage внутри контейнеров — www-data

php-fpm, `queue` и `scheduler` работают от **www-data** и пишут в том storage: лог
`storage/logs/laravel.log`, сессии, скомпилированные шаблоны, архив обмена с 1С.
Контейнеры по умолчанию запускаются от root, поэтому `docker compose exec -u www-data php php artisan …`
от root создаёт файлы с владельцем root. Так на стенде `laravel.log` стал файлом root:
приложение не могло писать лог, и ошибки (500 при загрузке цен) терялись.

Что сделано:

- `deploy.sh` запускает artisan только от www-data (`exec -u www-data`, `run --user www-data`).
  После кэшей шаг «Права storage и bootstrap/cache» выполняет
  `chown -R www-data:www-data storage bootstrap/cache` и проверяет, что файлов другого
  владельца не осталось. Если остались — деплой завершается ошибкой;
- `queue` и `scheduler` в `docker-compose.staging.yml` — `user: www-data`;
- entrypoint контейнера `php` при каждом старте чинит владельца (до и после кэшей)
  и собирает кэши от www-data.

Ручные команды на сервере — тоже от www-data:

```bash
docker compose -f docker-compose.staging.yml exec -u www-data php php artisan about
```

Если лог или кэши уже стали файлами root (artisan запускали без `-u www-data`):

```bash
docker compose -f docker-compose.staging.yml exec -u root php chown -R www-data:www-data storage bootstrap/cache
docker compose -f docker-compose.staging.yml exec -u root php find storage bootstrap/cache ! -user www-data   # пусто — порядок
```

Или просто `./deploy.sh` (с `SKIP_PULL=1` — без обновления кода).

## Обновление

```bash
cd /home/deploy/smesitel_by
./deploy.sh
```

Скрипт по шагам: бэкап БД → `git pull` → сборка образов (`composer install --no-dev`,
`npm ci && npm run build`) → миграции новым образом → перезапуск контейнеров →
`storage:link` → кэши config/route/view → `queue:restart` и проверка планировщика `scheduler` (воркер очереди перезапускается
с новым кодом) → проверка `http://127.0.0.1:8090/up`.

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
   QUEUE_CONNECTION=database
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

       # API обмена с 1С — без пароля стенда (раздел «API обмена с 1С»)
       location /api/1c/ {
           auth_basic off;
           client_max_body_size 50m;
           proxy_pass http://127.0.0.1:8090;
           proxy_set_header Host $host;
           proxy_set_header X-Real-IP $remote_addr;
           proxy_set_header X-Forwarded-For $remote_addr;
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_read_timeout 120s;
       }

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
7. **Данные:** демо-учётки и заказы — см. «Демо-данные»; каталог — «Импорт каталога на стенде»
   (прежний импорт из JSON — «Полезные команды → Импорт каталога из JSON»).

## API обмена с 1С

Адрес: `https://test.smesitel.by/api/1c/v1` (`docs/1C_EXCHANGE_SPEC.md`). Доступ — Bearer-токен
и белый список IP из админки («Обмен с 1С» → «Токены», «IP-адреса»); пока список IP пуст,
API отвечает 403 всем. Basic auth стенда на `/api/1c/` не действует — 1С его не передаёт.

| Что | Значение |
|---|---|
| nginx сервера | `location /api/1c/` без `auth_basic`, `client_max_body_size 50m`, `X-Forwarded-For $remote_addr` (адрес клиента — для проверки IP) |
| nginx стенда (`docker/staging/nginx.conf`) | `client_max_body_size 210M` — больше 50 МБ, менять не нужно |
| PHP (`docker/php/php.ini` в образе) | `post_max_size = 210M` (тело запроса API), `upload_max_filesize = 20M` (фото; на API не влияет) — менять не нужно. Предел API — 50 МБ в приложении (`SHOP_1C_MAX_BODY_MB`), сверх — 413 |
| Файлы обмена | том `smesitel_by_storage`: `storage/app/exchange/ГГГГ/ММ/<job_id>.csv`, удаляются через 90 дней (`exchange:prune`) |
| Обработка | очередь (сервис `queue`); письма о сбоях — администраторам с галочкой «Сбои обмена с 1С» |
| Расписание | сервис `scheduler` (`php artisan schedule:work`): `exchange:prune` ежедневно 04:10, `exchange:check` раз в 10 минут. До этапа 2 планировщика на стенде не было — его добавляет `./deploy.sh` |

### Настройка nginx сервера (один раз)

Блок `location /api/1c/` — в тот `server`, где `server_name test.smesitel.by` и `listen 443`
(certbot дописал его в `/etc/nginx/sites-available/smesitel`), перед `location / {`:

```nginx
    # API обмена с 1С: без basic auth, тело до 50 МБ; X-Forwarded-For — только адрес клиента
    # (проверка IP по белому списку, подставленный клиентом заголовок не передаётся)
    location /api/1c/ {
        auth_basic off;
        client_max_body_size 50m;
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
```

Команды (от пользователя с sudo):

```bash
# 1. Копия текущего конфига
sudo cp /etc/nginx/sites-available/smesitel /etc/nginx/sites-available/smesitel.bak-$(date +%F)

# 2. Где блок 443 и location / (вставить блок выше перед «location / {» этого server)
sudo grep -n "listen 443\|server_name\|location /" /etc/nginx/sites-available/smesitel
sudo nano /etc/nginx/sites-available/smesitel

# 3. Проверка и применение
sudo nginx -t && sudo systemctl reload nginx

# 4. Без пароля стенда API отвечает JSON (403 — IP ещё не в списке), а не 401 basic auth
curl -i https://test.smesitel.by/api/1c/v1/ping
```

Если `nginx -t` ругается — вернуть копию: `sudo cp /etc/nginx/sites-available/smesitel.bak-<дата> /etc/nginx/sites-available/smesitel && sudo systemctl reload nginx`.

### Планировщик

```bash
cd /home/deploy/smesitel_by
docker compose ps --services --status running    # до деплоя этапа 2: php nginx mysql queue — без scheduler
crontab -l                                         # в cron только бэкап (deploy.sh install-cron), schedule:run нет
./deploy.sh                                        # поднимет scheduler; шаг «Планировщик» проверит, что он запущен
docker compose ps scheduler
docker compose exec -u www-data php php artisan schedule:list  # exchange:prune, exchange:check
docker compose logs --tail=50 scheduler
```

### Проверка с токеном

Токен — «Обмен с 1С» → «Токены» → «Создать токен» (показывается один раз). Свой внешний IP
видно на вкладке «IP-адреса» («Ваш текущий адрес») — добавить его на время проверки, потом удалить.

```bash
TOKEN=sm1c_...    # не сохранять в истории: пробел перед командой, если HISTCONTROL=ignorespace

curl -s https://test.smesitel.by/api/1c/v1/ping -H "Authorization: Bearer $TOKEN"

printf 'guid;sku;name;brand;unit;vat_rate;active\ng-1;L4755-2;Смеситель;LEDEME;шт;20;1\n' \
  | gzip -c | curl -s -X POST --data-binary @- "https://test.smesitel.by/api/1c/v1/catalog?mode=delta" \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: text/csv" -H "Content-Encoding: gzip" \
    -H "X-Exchange-Id: $(cat /proc/sys/kernel/random/uuid)"
# → {"job_id":"…","status":"queued"}

curl -s https://test.smesitel.by/api/1c/v1/jobs/<job_id> -H "Authorization: Bearer $TOKEN"
```

Пока обработчики не подключены (этапы 3–5), задание сразу `done` с нулём строк, данные сайта не меняются.

## Демо-данные

```bash
docker compose exec -u www-data php php artisan db:seed --class=DemoSeeder --force
```

Создаёт (повторный запуск не дублирует): администратора, две организации с адресами,
трёх клиентов, заблокированного пользователя, три демо-товара (категория «Демо-товары»),
заказы во всех статусах и заказ с запросом на отмену. **Пароли выводятся в консоль и при
каждом запуске новые** — передайте их тестировщикам. Работает только при `APP_ENV=staging`.

Удалить демо-данные (организации, пользователей `@demo.test`, их заказы и корзины,
демо-товары и категорию «Демо-товары»; каталог и остальные данные не трогаются):

```bash
docker compose exec -u www-data php php artisan demo:clear          # покажет, что удалит, и спросит
docker compose exec -u www-data php php artisan demo:clear --force  # без вопроса
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
docker compose exec -u www-data php php artisan cache:clear
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
пароля, уведомления администраторам о заказах) записываются в лог приложения целиком, со ссылками.

Уведомления администраторам (новый заказ, запрос на отмену) отправляются **через очередь**:
`QUEUE_CONNECTION=database`, задачи — в таблице `jobs`, отправляет контейнер `queue`
(`php artisan queue:work`). Без запущенного воркера письма копятся в `jobs` и не уходят.
Неудачная отправка повторяется 3 раза (через 1 и 5 минут), затем — в `failed_jobs`.

```bash
docker compose exec php tail -n 200 -f storage/logs/laravel.log   # лог приложения и письма
docker compose exec php grep -n "reset-password" storage/logs/laravel.log | tail   # ссылки из писем
docker compose logs --tail=100 php nginx     # php-fpm и nginx контейнера
docker compose logs --tail=100 -f queue      # воркер очереди: какие задачи выполнены / упали
docker compose exec -u www-data php php artisan queue:failed      # неудачные задачи
docker compose exec -u www-data php php artisan queue:retry all   # повторить их
docker compose logs --tail=100 mysql
sudo tail -f /var/log/nginx/access.log /var/log/nginx/error.log   # nginx сервера
```

Чтобы письма уходили по-настоящему — задать в `.env` `MAIL_MAILER=smtp` и `MAIL_SCHEME`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`,
затем `SKIP_PULL=1 ./deploy.sh` (или `docker compose up -d --force-recreate php queue` — кэш
конфигурации пересобирается при старте, воркер перечитывает `.env`).

## Импорт каталога на стенде

Команда `catalog:import-db` переносит каталог из `catalog.db` (SQLite): категории, бренды,
характеристики, фильтры, дубли (подробно — `docs/CATALOG_ANALYSIS.md`, маппинги —
`database/data/catalog/*.php`, они в образе). Цены, остатки, артикулы и названия не меняет.
Повторный прогон ничего не меняет (идемпотентен).

**Перед импортом** стенд должен быть обновлён `./deploy.sh` (нужны миграции импорта,
в т. ч. `video_url` и `value` до 1000 символов):

```bash
cd /home/deploy/smesitel_by
docker compose exec -u www-data php php artisan migrate:status | grep -E "video_url|widen_product_attribute"   # обе — Ran
```

Всё ниже — от deploy в `/home/deploy/smesitel_by`. Код на стенде — в образе, без bind-mount:
файлы попадают в контейнер `php` только через `docker compose cp`, а `/tmp` контейнера
очищается при его пересоздании (`./deploy.sh`) — копировать перед каждым прогоном.

**1. Положить `catalog.db` на сервер** (вне папки проекта, чтобы не мешал `git pull`) —
с компьютера:

```bash
ssh deploy@<сервер> mkdir -p imports
scp D:\smesitel_by\data\catalog.db deploy@<сервер>:imports/catalog.db
```

**2. Скопировать в контейнер** (только чтение):

```bash
docker compose cp ~/imports/catalog.db php:/tmp/catalog.db
docker compose exec php chmod 0444 /tmp/catalog.db
```

**3. Бэкап БД** — свежий, прямо перед боевым прогоном:

```bash
./deploy.sh backup
B=$(ls -t ~/backups/smesitel-*.sql.gz | head -1); echo "$B"
gunzip -c "$B" | tail -1                     # -- Dump completed on …
gunzip -c "$B" | grep -c 'CREATE TABLE'      # = числу таблиц:
docker compose exec mysql sh -c 'exec mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" -e "select count(*) from information_schema.tables where table_schema=\"$MYSQL_DATABASE\""'
```

**Проверка «без полного дампа не запускается».** Боевой прогон требует `--backup=<файл>`
**внутри контейнера** и читает его конец: нет файла, он пустой или последняя строка не
`-- Dump completed` — прогон не начинается. Файл должен быть **несжатым**: в `.sql.gz`
команда строку не найдёт. Поэтому распакованная копия бэкапа — в контейнер (сжатый
оригинал остаётся в `~/backups`, из него и восстанавливать):

```bash
gunzip -c "$B" | docker compose exec -T php sh -c 'cat > /tmp/backup_catalog_import.sql'
docker compose exec php tail -1 /tmp/backup_catalog_import.sql      # -- Dump completed on …
```

Команда проверяет только, что дамп полный, но не его дату: свежесть бэкапа — на том,
кто запускает (шаг 3 — непосредственно перед шагом 5).

**4. Dry-run** — база не меняется; отчёт — в `/tmp` контейнера, затем на сервер:

```bash
docker compose exec -u www-data php php artisan catalog:import-db /tmp/catalog.db --dry-run --report-dir=/tmp/catalog-import
docker compose cp php:/tmp/catalog-import ~/imports/report-before
less ~/imports/report-before/import-report.md      # и uncategorized.csv (на проверку)
```

В отчёте раздел **«Проверка по схеме БД — ошибок нет»**. Если вместо него
«Ошибки — боевой прогон не стартует» (значение длиннее колонки) — боевой прогон
откажется запускаться; сначала исправить. Данные стенда отличаются от локальных, поэтому
цифры могут не совпасть с `docs/catalog/import-report.md` из репозитория — просмотреть
«Итого», категории к удалению и «Без категории».

**5. Боевой прогон** (локально — ~30 с, пачками в транзакциях):

```bash
docker compose exec -u www-data php php artisan catalog:import-db /tmp/catalog.db --backup=/tmp/backup_catalog_import.sql --report-dir=/tmp/catalog-import
```

Последняя строка — «Импорт выполнен». Меню каталога сбрасывается командой; на всякий
случай — `docker compose exec -u www-data php php artisan cache:clear`.

Если прогон упал — вернуть БД из бэкапа шага 3 (не продолжать с частично применённым):

```bash
gunzip -c "$B" | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose exec -u www-data php php artisan cache:clear
```

**6. Повторный dry-run** — должно быть «Товаров изменится: 0»:

```bash
docker compose exec -u www-data php php artisan catalog:import-db /tmp/catalog.db --dry-run --report-dir=/tmp/catalog-import
docker compose cp php:/tmp/catalog-import ~/imports/report-after
grep -E "^\| (Товаров изменится|Категорий:)" ~/imports/report-after/import-report.md
```

**7. Удалить файлы из контейнера** (каталог и дамп с данными клиентов):

```bash
docker compose exec php rm -rf /tmp/catalog.db /tmp/backup_catalog_import.sql /tmp/catalog-import
```

`~/imports/catalog.db` и отчёты на сервере — удалить, когда не нужны: `rm -rf ~/imports`.

## Время

В БД время хранится в UTC (`app.timezone`), людям показывается по Минску (`APP_DISPLAY_TIMEZONE`, по умолчанию `Europe/Minsk`, — `App\Support\LocalTime`). Сервер MySQL должен работать в UTC, иначе колонки `TIMESTAMP` сдвинутся при чтении. Проверка — обе колонки должны совпадать:

```bash
docker compose -f docker-compose.staging.yml exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "select @@global.time_zone, @@system_time_zone, now(), utc_timestamp()"'
```

## Полезные команды

```bash
docker compose ps                                   # состояние контейнеров
docker compose exec -u www-data php php artisan about           # версия, окружение, кэши
docker compose exec -u www-data php php artisan migrate:status  # миграции
docker compose exec -u www-data php php artisan tinker          # консоль Laravel
docker compose exec mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'   # консоль MySQL
docker compose restart php nginx                    # перезапуск без пересборки
```

Изменения в `.env` применяются после перезапуска `php` (кэш конфигурации собирается
при старте контейнера).

### Импорт каталога из JSON (прежний)

Файл `catalog_import.json` (формат — `app/Console/Commands/ImportCatalog.php`):

```bash
docker compose exec php mkdir -p storage/app/imports
docker compose cp catalog_import.json php:/var/www/storage/app/imports/catalog_import.json
docker compose exec -u www-data php php artisan catalog:import
```

### Пользователь-администратор вручную

```bash
docker compose exec -u www-data php php artisan user:create "Имя Фамилия" admin@example.com --role=admin --generate-password
```
