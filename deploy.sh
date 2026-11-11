#!/usr/bin/env bash
#
# Обновление тестового стенда test.smesitel.by (docs/STAGING.md).
#
#   ./deploy.sh               — полное обновление
#   ./deploy.sh backup        — только бэкап БД (так же — ежедневно по cron)
#   ./deploy.sh install-cron  — добавить ежедневный бэкап в crontab пользователя
#
# Запускать из папки проекта от пользователя deploy (не root: git откажется работать
# с репозиторием чужого владельца).
#
# Шаги: бэкап БД → git pull → сборка образов (composer install --no-dev, npm ci &&
# npm run build — внутри docker/staging/Dockerfile) → migrate --force (новым образом,
# пока работает старая версия) → перезапуск контейнеров → storage:link → кэши
# config/route/view → права storage и bootstrap/cache (www-data) → queue:restart
# (воркер очереди) → проверка /up.
# artisan — всегда от www-data (php_exec, migrate): от root он создаёт laravel.log и кэши,
# в которые php-fpm потом не может писать (ошибки приложения теряются). Ручной запуск на
# сервере — так же: docker compose -f docker-compose.staging.yml exec -u www-data php php artisan …
# Ошибка до перезапуска — стенд остаётся на прежней версии.
#
# Настройки (переменные окружения):
#   DEPLOY_BRANCH=master          ветка
#   BACKUP_DIR=$HOME/backups      папка бэкапов БД
#   BACKUP_KEEP=10                сколько последних бэкапов хранить
#   SKIP_PULL=1                   не обновлять код (повторная сборка того же коммита)
#   COMPOSE_PROJECT_NAME=…        имя проекта docker compose (по умолчанию smesitel_by)
#   BACKUP_CRON="30 3 * * *"      расписание ежедневного бэкапа для install-cron

set -Eeuo pipefail

cd "$(dirname "$(readlink -f "$0")")"

BRANCH="${DEPLOY_BRANCH:-master}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
BACKUP_KEEP="${BACKUP_KEEP:-10}"
PROJECT="${COMPOSE_PROJECT_NAME:-smesitel_by}"
COMPOSE=(docker compose -p "$PROJECT" -f docker-compose.staging.yml)

STEP="подготовка"
RESTARTED=0
BACKUP_FILE=""

# Цвета — только в терминале; в логе cron — без управляющих символов
if [ -t 1 ]; then
    BOLD=$'\033[1m' RED=$'\033[31m' GREEN=$'\033[32m' RESET=$'\033[0m'
else
    BOLD='' RED='' GREEN='' RESET=''
fi

step() {
    STEP="$1"
    printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"
}

fail() {
    printf '\n%sОШИБКА: %s%s\n' "$RED" "$1" "$RESET" >&2

    if [ "$RESTARTED" = 0 ]; then
        echo "Стенд не перезапускался — работает прежняя версия." >&2
    else
        echo "Контейнеры уже перезапущены на новой версии. Откат — docs/STAGING.md, раздел «Откат»." >&2
    fi

    [ -n "$BACKUP_FILE" ] && echo "Бэкап БД перед обновлением: $BACKUP_FILE" >&2
    exit 1
}

trap 'fail "шаг «$STEP» завершился с ошибкой (строка $LINENO). Вывод команды — выше."' ERR

compose() {
    "${COMPOSE[@]}" "$@"
}

php_exec() {
    compose exec -T -u www-data php php artisan "$@"
}

# Владелец storage (том) и bootstrap/cache — www-data: чинит и файлы, созданные ручным
# artisan от root (laravel.log, кэши)
fix_permissions() {
    compose exec -T -u root php chown -R www-data:www-data storage bootstrap/cache
}

# --- Проверки ---

preflight() {
    step "Проверки"

    [ "$(id -u)" != 0 ] || [ "${ALLOW_ROOT:-}" = 1 ] \
        || fail "запуск от root. Запускайте от пользователя deploy: sudo -u deploy ./deploy.sh"

    command -v docker >/dev/null || fail "docker не найден"
    docker compose version >/dev/null 2>&1 || fail "нет docker compose (v2)"
    [ -f .env ] || fail "нет файла .env в $(pwd)"

    local env
    env="$(grep -E '^APP_ENV=' .env | cut -d= -f2- | tr -d '"' || true)"
    [ "$env" != production ] || fail "APP_ENV=production — этот скрипт для тестового стенда"

    if grep -qE '^COMPOSE_FILE=.*docker-compose\.yml' .env; then
        echo "Внимание: в .env COMPOSE_FILE указывает на docker-compose.yml (локальный, с bind-mount)."
        echo "          Для стенда: COMPOSE_FILE=docker-compose.staging.yml (deploy.sh использует его в любом случае)."
    fi

    # Остатки прежней схемы (bind-mount): vendor/ и node_modules/ ставились в контейнерах
    # от root. Теперь не используются — зависимости и фронт собираются внутри образа
    local leftover
    for leftover in vendor node_modules public/build; do
        if [ -e "$leftover" ] && [ "$(stat -c %U "$leftover" 2>/dev/null)" = root ]; then
            echo "Внимание: $leftover/ принадлежит root и больше не используется (зависимости — в образе)."
            echo "          Удалить: sudo rm -rf vendor node_modules public/build"
            break
        fi
    done

    mkdir -p "$BACKUP_DIR"
    echo "Проект: $PROJECT, ветка: $BRANCH, бэкапы: $BACKUP_DIR"
}

# --- Бэкап БД ---

backup() {
    step "Бэкап БД"

    if [ -z "$(compose ps -q --status running mysql 2>/dev/null)" ]; then
        echo "MySQL не запущен (первая установка?) — бэкап пропущен."
        return
    fi

    BACKUP_FILE="$BACKUP_DIR/smesitel-$(date +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD 2>/dev/null || echo nogit).sql.gz"

    compose exec -T mysql sh -c \
        'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --routines --triggers --no-tablespaces "$MYSQL_DATABASE"' \
        | gzip > "$BACKUP_FILE"

    gzip -t "$BACKUP_FILE" || fail "бэкап повреждён: $BACKUP_FILE"
    [ "$(gzip -cd "$BACKUP_FILE" | head -c 1000 | wc -c)" -gt 100 ] || fail "бэкап пустой: $BACKUP_FILE"

    echo "Готово: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

    # Хранятся последние BACKUP_KEEP
    ls -1t "$BACKUP_DIR"/smesitel-*.sql.gz 2>/dev/null | tail -n +"$((BACKUP_KEEP + 1))" | xargs -r rm -f
}

# --- Код ---

pull() {
    step "Обновление кода (git pull, ветка $BRANCH)"

    if [ "${SKIP_PULL:-}" = 1 ]; then
        echo "SKIP_PULL=1 — код не обновляется, $(git log --oneline -1)"
        return
    fi

    git diff --quiet && git diff --cached --quiet \
        || fail "в папке проекта есть изменённые файлы (git status). Уберите или закоммитьте их — стенд обновляется только из git"

    # Прежний ручной docker-compose.staging.yml (не из git) мешает git pull
    if [ -f docker-compose.staging.yml ] && ! git ls-files --error-unmatch docker-compose.staging.yml >/dev/null 2>&1; then
        mv docker-compose.staging.yml "docker-compose.staging.yml.manual-$(date +%Y%m%d%H%M%S)"
        echo "Прежний docker-compose.staging.yml (не из git) сохранён как docker-compose.staging.yml.manual-*"
    fi

    local before
    before="$(git rev-parse --short HEAD)"

    git fetch --quiet origin "$BRANCH"
    git merge --ff-only "origin/$BRANCH"

    echo "Код: $before → $(git rev-parse --short HEAD)"
    git log --oneline "$before..HEAD" | head -20
}

# --- Сборка: composer install --no-dev, npm ci && npm run build ---

build() {
    step "Сборка образов (composer install --no-dev, npm ci && npm run build)"
    compose build --pull
}

# Фото, загруженные при прежней схеме (bind-mount ./storage), — в том storage, один раз
import_host_storage() {
    [ -d storage/app/public ] && [ -n "$(ls -A storage/app/public 2>/dev/null | grep -v '^\.gitignore$' || true)" ] || return 0

    step "Перенос загруженных файлов из ./storage в том storage (один раз)"

    # pwd -W — путь для Docker в Git Bash на Windows (локальная проверка); на Linux — обычный pwd
    local here
    here="$(pwd -W 2>/dev/null || pwd)"

    compose run --rm --no-deps -T -v "$here/storage/app/public:/old:ro" --entrypoint sh php -c '
        set -e
        if [ -e storage/.imported-from-host ]; then echo "Уже перенесено ранее — пропуск."; exit 0; fi
        cp -a /old/. storage/app/public/
        chown -R www-data:www-data storage/app/public
        date > storage/.imported-from-host
        echo "Перенесено: $(find storage/app/public -type f | wc -l) файлов"'
}

migrate() {
    step "Миграции (php artisan migrate --force)"
    compose up -d mysql
    compose run --rm -T --user www-data php php artisan migrate --force
}

restart() {
    step "Перезапуск контейнеров"
    compose up -d --remove-orphans
    RESTARTED=1
}

finish() {
    step "storage:link и кэши config/route/view"
    fix_permissions
    compose exec -T -u www-data php sh -c '[ -L public/storage ] || php artisan storage:link'
    php_exec config:cache
    php_exec route:cache
    php_exec view:cache

    step "Права storage и bootstrap/cache (www-data)"
    fix_permissions
    local foreign
    foreign="$(compose exec -T -u root php sh -c 'find storage bootstrap/cache ! -user www-data | head -5')"
    [ -z "$foreign" ] || fail "остались файлы не от www-data: $foreign"
    echo "Готово: владелец — www-data"

    step "Воркер очереди"
    # up -d уже пересоздал queue новым образом. queue:restart — и для случаев, когда контейнер
    # не пересоздавался (тот же образ, правка .env): воркер завершится после текущей задачи,
    # Docker поднимет его снова (restart: unless-stopped) — с новым кодом и .env
    if ! compose ps --status running --services | grep -qx queue; then
        fail "воркер очереди не запущен. Логи: docker compose logs --tail=100 queue"
    fi
    php_exec queue:restart

    # Планировщик (обмен с 1С: очистка архива, письма о сбоях). up -d пересоздал его новым образом
    step "Планировщик"
    if ! compose ps --status running --services | grep -qx scheduler; then
        fail "планировщик не запущен. Логи: docker compose logs --tail=100 scheduler"
    fi

    step "Проверка"
    local port url
    port="${APP_PORT:-$(grep -E "^APP_PORT=" .env | cut -d= -f2 || true)}"
    url="http://127.0.0.1:${port:-8090}/up"

    for _ in $(seq 1 20); do
        if curl -fsS -o /dev/null "$url"; then
            echo "Стенд отвечает: $url"
            docker image prune -f >/dev/null || true
            printf '\n%sГотово: %s%s\n' "$GREEN" "$(git log --oneline -1)" "$RESET"
            [ -n "$BACKUP_FILE" ] && echo "Бэкап перед обновлением: $BACKUP_FILE"
            return
        fi
        sleep 3
    done

    fail "стенд не отвечает на $url. Логи: docker compose logs --tail=100 php nginx"
}

# Ежедневный бэкап: строка в crontab пользователя (повторный запуск не дублирует)
install_cron() {
    step "Ежедневный бэкап по cron"

    local schedule line current
    schedule="${BACKUP_CRON:-30 3 * * *}"
    line="$schedule cd $(pwd) && BACKUP_DIR=$BACKUP_DIR BACKUP_KEEP=$BACKUP_KEEP ./deploy.sh backup >> $BACKUP_DIR/backup.log 2>&1"
    current="$(crontab -l 2>/dev/null || true)"

    if grep -qF './deploy.sh backup' <<< "$current"; then
        echo "Уже есть в crontab:"
        grep -F './deploy.sh backup' <<< "$current"
        return
    fi

    printf '%s\n%s\n' "$current" "$line" | sed '/^$/d' | crontab -
    echo "Добавлено в crontab пользователя $(id -un):"
    echo "  $line"
}

case "${1:-deploy}" in
    backup)
        preflight
        backup
        ;;
    install-cron)
        preflight
        install_cron
        ;;
    deploy)
        preflight
        backup
        pull
        build
        import_host_storage
        migrate
        restart
        finish
        ;;
    *)
        echo "Использование: ./deploy.sh [deploy|backup|install-cron]" >&2
        exit 2
        ;;
esac
