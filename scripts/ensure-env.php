<?php

declare(strict_types=1);

/**
 * Create a local .env from .env.example when it is missing.
 *
 * A fresh clone has no .env (it is git-ignored). Symfony's runtime and the
 * Composer post-install scripts both need one to boot, so create it before
 * cache:clear runs. Existing .env files are never overwritten.
 */

$root = dirname(__DIR__);
$env = $root.'/.env';
$example = $root.'/.env.example';

if (is_file($env)) {
    return;
}

if (!is_file($example)) {
    fwrite(STDERR, "warning: .env.example not found; skipped .env creation.\n");

    return;
}

if (!copy($example, $env)) {
    fwrite(STDERR, "warning: could not create .env from .env.example.\n");

    return;
}

fwrite(STDOUT, "Created .env from .env.example. Edit APP_SECRET and POSTGRES_PASSWORD before exposing this instance.\n");
