#!/bin/sh
set -e
cd /app

if [ ! -f vendor/autoload.php ]; then
  echo "==> composer install"
  composer install --prefer-dist --no-interaction
fi

if [ -n "${DATABASE_URL:-}" ]; then
  echo "==> doctrine migrations"
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
fi

exec frankenphp run --config /etc/caddy/Caddyfile --watch
