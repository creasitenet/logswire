<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        if ('prod' === $this->environment) {
            $this->guardProductionSecrets();
        }
    }

    /**
     * A default APP_SECRET makes remember-me cookies and CSRF tokens forgeable.
     * Refuse to serve production traffic with the placeholder from .env.example.
     */
    private function guardProductionSecrets(): void
    {
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');

        if (!\is_string($secret) || '' === trim($secret) || str_starts_with($secret, 'change-me') || \strlen($secret) < 32) {
            throw new \RuntimeException(
                'APP_SECRET must be a random string of at least 32 characters in production. '
                .'Generate one with: openssl rand -hex 32',
            );
        }
    }
}
