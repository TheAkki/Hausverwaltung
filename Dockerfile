# syntax=docker/dockerfile:1

# Versionen der Basis-Images (beim Bauen änderbar mit --build-arg).
# Node 22 ist LTS; Vite 8 braucht mindestens Node 20.19 bzw. 22.12.
ARG NODE_VERSION=22
ARG PHP_VERSION=8.3
ARG DEBIAN_RELEASE=trixie
ARG COMPOSER_VERSION=2

# ---- Frontend: CSS/JS mit Vite bauen ----------------------------------------
FROM node:${NODE_VERSION}-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund

COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# ---- Composer-Binary ---------------------------------------------------------
FROM composer:${COMPOSER_VERSION} AS composer

# ---- PHP-Basis: PHP-FPM mit den benötigten Erweiterungen ---------------------
FROM php:${PHP_VERSION}-fpm-${DEBIAN_RELEASE} AS base

# Datenbanktreiber für SQLite, MySQL/MariaDB und PostgreSQL; welcher genutzt wird,
# bestimmt DB_CONNECTION zur Laufzeit.
# Die -dev-Pakete werden nur zum Übersetzen der Erweiterungen gebraucht und danach
# wieder entfernt; erhalten bleiben nur die Laufzeit-Bibliotheken (Vorgehen wie in
# den offiziellen PHP-Images). Debian-Pakete bleiben ungepinnt (Sicherheitsupdates);
# die Pipeline toleriert bewusst einzelne nicht zuordenbare Bibliotheken.
# hadolint ignore=DL3008,DL4006,SC2086
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libpq-dev libsqlite3-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pdo_mysql pdo_pgsql pdo_sqlite zip; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search 2>/dev/null \
        | awk -F': ' '!/^diversion / { print $1 }' | tr ',' '\n' | tr -d ' ' | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    apt-get install -y --no-install-recommends nginx supervisor; \
    rm -rf /var/lib/apt/lists/*; \
    for extension in gd intl zip pdo_mysql pdo_pgsql pdo_sqlite "Zend OPcache"; do \
        php -m | grep -qix "$extension"; \
    done

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/hausverwaltung.ini "$PHP_INI_DIR/conf.d/zz-hausverwaltung.ini"

WORKDIR /var/www/html

# ---- Build: Abhängigkeiten installieren, Autoloader und Anleitung erzeugen ---
FROM base AS build

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

COPY . .
COPY --from=frontend /app/public/build ./public/build

# dump-autoload führt die Composer-Skripte aus (package:discover, docs:generate –
# letzteres erzeugt die Bedienungsanleitung in public/docs).
RUN composer dump-autoload --optimize --no-dev \
    && rm -f .env \
    && mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage

# Bekannte Sicherheitslücken der installierten PHP-Pakete melden. Standard: nur
# warnen; mit --build-arg FAIL_ON_AUDIT=true bricht der Build ab.
ARG FAIL_ON_AUDIT=false
RUN composer audit --no-dev --locked --abandoned=ignore \
    || { echo "WARNUNG: composer audit meldet Sicherheitslücken (siehe oben)." >&2; [ "$FAIL_ON_AUDIT" != "true" ]; }

# ---- Laufzeit-Image -----------------------------------------------------------
FROM base AS app

# Metadaten (OCI). Beim Build über GitHub Actions ergänzt bzw. überschreibt
# docker/metadata-action sie um Version, Commit und Erstellungszeit.
ARG IMAGE_SOURCE=https://github.com/TheAkki/Hausverwaltung
LABEL org.opencontainers.image.title="Hausverwaltung" \
      org.opencontainers.image.description="Verwaltung von Mietimmobilien mit Betriebskostenabrechnung" \
      org.opencontainers.image.source="${IMAGE_SOURCE}" \
      org.opencontainers.image.documentation="${IMAGE_SOURCE}/blob/main/docker/README.md" \
      org.opencontainers.image.licenses="GPL-2.0-or-later"

COPY --from=build /var/www/html /var/www/html

COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/database-ready.php /usr/local/lib/hausverwaltung/database-ready.php

# Der Code gehört root und ist für den Webserver nur lesbar; beschreibbar bleiben
# nur storage/ (Volume) und bootstrap/cache/.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u+rwX,g+rwX,o-rwx storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1/up') === false ? 1 : 0);"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
