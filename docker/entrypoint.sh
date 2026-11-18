#!/bin/sh
# Start des Containers: Einstellungen prüfen, Schlüssel und Datenbank vorbereiten,
# Migrationen ausführen, Caches aufbauen. Artisan läuft als www-data, damit Logs
# und Caches dem Webserver gehören.
set -eu

APP_DIR=/var/www/html
WEB_USER=www-data
# Ein selbst erzeugter APP_KEY liegt im persistenten storage-Volume.
APP_KEY_FILE="${APP_KEY_FILE:-$APP_DIR/storage/app/.app_key}"
# Standard-Speicherort einer SQLite-Datenbank, ebenfalls im Volume.
SQLITE_DEFAULT_FILE="$APP_DIR/storage/app/database.sqlite"
DATABASE_READY_SCRIPT=/usr/local/lib/hausverwaltung/database-ready.php
# Wie lange auf einen Datenbankserver gewartet wird (Sekunden).
DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-60}"
DB_WAIT_INTERVAL=2
MYSQL_DEFAULT_PORT=3306
PGSQL_DEFAULT_PORT=5432
MAX_PORT=65535
# Referenzdaten (Standardkategorien, Standardvorlage, Themes); die Seeder legen nur
# Fehlendes an. Bewusst NICHT AdminUserSeeder: er legt admin@example.com mit leerem
# Passwort an.
REFERENCE_SEEDERS="CostCategorySeeder BillingTemplateSeeder ThemeSeeder"

cd "$APP_DIR"

fail() {
    echo "FEHLER: $*" >&2
    exit 1
}

as_web_user() {
    setpriv --reuid="$WEB_USER" --regid="$WEB_USER" --init-groups "$@"
}

is_number() {
    case "$1" in
        "" | *[!0-9]*) return 1 ;;
        *) return 0 ;;
    esac
}

require_admin_email() {
    # Ohne ADMIN_EMAIL gilt der Platzhalter aus config/app.php – wer sich mit ihm
    # registriert, wäre Administrator.
    case "${ADMIN_EMAIL:-}" in
        "" | *@example.com | *@example.org) fail "ADMIN_EMAIL muss auf die E-Mail-Adresse des Administrators gesetzt sein." ;;
        *@*) ;;
        *) fail "ADMIN_EMAIL ist keine E-Mail-Adresse: $ADMIN_EMAIL" ;;
    esac
}

prepare_storage() {
    mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache
    chown -R "$WEB_USER:$WEB_USER" storage bootstrap/cache
}

ensure_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        return
    fi
    if [ ! -s "$APP_KEY_FILE" ]; then
        echo "Kein APP_KEY gesetzt – erzeuge einen und speichere ihn in $APP_KEY_FILE."
        ( umask 077; as_web_user php artisan key:generate --show --no-ansi > "$APP_KEY_FILE.tmp" )
        mv "$APP_KEY_FILE.tmp" "$APP_KEY_FILE"
        chown "$WEB_USER:$WEB_USER" "$APP_KEY_FILE"
    fi
    APP_KEY="$(cat "$APP_KEY_FILE")"
    case "$APP_KEY" in
        base64:*) export APP_KEY ;;
        *) fail "$APP_KEY_FILE enthält keinen gültigen Schlüssel." ;;
    esac
}

configure_sqlite() {
    DB_DATABASE="${DB_DATABASE:-$SQLITE_DEFAULT_FILE}"
    case "$DB_DATABASE" in
        /*) ;;
        *) fail "DB_DATABASE muss für SQLite ein absoluter Pfad sein: $DB_DATABASE" ;;
    esac
    export DB_DATABASE
    if [ ! -f "$DB_DATABASE" ]; then
        as_web_user mkdir -p "$(dirname "$DB_DATABASE")"
        as_web_user touch "$DB_DATABASE"
    fi
}

configure_server_database() {
    default_port="$1"
    missing=""
    for variable in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do
        eval "value=\${$variable:-}"
        [ -n "$value" ] || missing="$missing $variable"
    done
    [ -z "$missing" ] || fail "Für DB_CONNECTION=$DB_CONNECTION fehlen:$missing"

    DB_PORT="${DB_PORT:-$default_port}"
    if ! is_number "$DB_PORT" || [ "$DB_PORT" -lt 1 ] || [ "$DB_PORT" -gt "$MAX_PORT" ]; then
        fail "DB_PORT ist keine gültige Portnummer: $DB_PORT"
    fi
    export DB_PORT
}

configure_database() {
    DB_CONNECTION="${DB_CONNECTION:-sqlite}"
    export DB_CONNECTION
    case "$DB_CONNECTION" in
        sqlite) configure_sqlite ;;
        mysql | mariadb) configure_server_database "$MYSQL_DEFAULT_PORT" ;;
        pgsql) configure_server_database "$PGSQL_DEFAULT_PORT" ;;
        *) fail "DB_CONNECTION muss sqlite, mysql, mariadb oder pgsql sein, nicht: $DB_CONNECTION" ;;
    esac
}

wait_for_database() {
    is_number "$DB_WAIT_TIMEOUT" || fail "DB_WAIT_TIMEOUT ist keine Zahl: $DB_WAIT_TIMEOUT"
    waited=0
    until as_web_user php "$DATABASE_READY_SCRIPT" 2> /tmp/database-ready.err; do
        if [ "$waited" -ge "$DB_WAIT_TIMEOUT" ]; then
            cat /tmp/database-ready.err >&2
            fail "Datenbank ($DB_CONNECTION) nach $DB_WAIT_TIMEOUT Sekunden nicht erreichbar."
        fi
        echo "Warte auf die Datenbank ($DB_CONNECTION) …"
        sleep "$DB_WAIT_INTERVAL"
        waited=$((waited + DB_WAIT_INTERVAL))
    done
    rm -f /tmp/database-ready.err
}

require_admin_email
prepare_storage
ensure_app_key
configure_database
wait_for_database

seed_reference_data() {
    for seeder in $REFERENCE_SEEDERS; do
        as_web_user php artisan db:seed --class="$seeder" --force --no-interaction
    done
}

as_web_user php artisan migrate --force --no-interaction
seed_reference_data
as_web_user php artisan optimize --no-interaction

exec "$@"
