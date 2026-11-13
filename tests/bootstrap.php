<?php

use DG\BypassFinals;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

BypassFinals::enable();

if (method_exists(Dotenv::class, 'bootEnv')) {
    $envFile = dirname(__DIR__).'/.env';
    if (!is_file($envFile)) {
        // A fresh clone has no .env; tests only need .env.test.
        $envFile = dirname(__DIR__).'/.env.test';
    }

    (new Dotenv())->bootEnv($envFile);
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
