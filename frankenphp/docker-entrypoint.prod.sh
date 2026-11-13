#!/bin/sh
set -e

cd /app

# Dotenv requires the file to exist; real config is injected by Compose (apps/.env → container env).
if [ ! -f .env ]; then
    touch .env
fi

# Bind mounts and fresh named volumes are often root-owned; the image ends with USER www-data
# but we start the container as root so we can fix ownership once, then drop privileges.
prepare_writable_dirs() {
    mkdir -p /data/sessions /data/locks /app/var/data /app/var/cache /app/var/log /app/var/backups
    if [ "$(id -u)" = 0 ]; then
        chown -R www-data:www-data /data /app/var
    fi
}

run_as_www() {
    if [ "$(id -u)" = 0 ]; then
        su -s /bin/sh www-data -c "cd /app && $*"
    else
        sh -c "cd /app && $*"
    fi
}


# Background roles reuse this image but must not run FrankenPHP.
if [ "${LOGSWIRE_ROLE:-web}" = "probe" ] || [ "${LOGSWIRE_ROLE}" = "worker" ]; then
    prepare_writable_dirs
    run_as_www "php bin/console cache:warmup --env=prod --no-debug --no-interaction"
    if [ "$(id -u)" = 0 ]; then
        exec su -s /bin/sh www-data -c 'cd /app && exec "$@"' -- "$@"
    fi
    exec "$@"
fi

prepare_writable_dirs

# Single-replica migrate on boot (safe while only one container starts at a time).
run_as_www "php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"
# var/ persists on the bind mount — always rebuild container cache so new action
# args (e.g. Security $security) are wired; warmup alone can keep a stale locator.
run_as_www "php bin/console cache:clear --env=prod --no-debug --no-interaction"

if [ "$(id -u)" = 0 ]; then
    exec su -s /bin/sh www-data -c 'cd /app && exec frankenphp run --config /etc/caddy/Caddyfile'
fi
exec frankenphp run --config /etc/caddy/Caddyfile
