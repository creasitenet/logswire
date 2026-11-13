# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1.12-php8.5-bookworm AS base

RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        zip

# pg_dump / psql power app:db:backup and app:db:restore.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- Composer dependencies ---
FROM base AS vendor
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- Runtime ---
FROM base AS runtime

# /data holds PHP sessions and other writable state (the database is Postgres).
RUN mkdir -p /data \
    && chown -R www-data:www-data /data

COPY --from=vendor /app /app
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY frankenphp/docker-entrypoint.prod.sh /usr/local/bin/docker-entrypoint.prod.sh

# Symfony's Dotenv requires the file to exist; real config comes from Compose env_file.
RUN touch /app/.env \
    && mkdir -p /app/var/cache /app/var/log \
    && chown -R www-data:www-data /app /data \
    && chmod +x /usr/local/bin/docker-entrypoint.prod.sh

ENV SERVER_NAME=:8080
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV LOG_LEVEL=WARN

EXPOSE 8080

# Entrypoint starts as root to chown volume mounts, then drops to www-data before FrankenPHP.
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.prod.sh"]
