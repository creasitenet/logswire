<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Turns malformed UUID route parameters into 404s instead of uncaught
 * InvalidArgumentException (500 + noisy logs).
 */
trait UuidParamTrait
{
    private function uuidOr404(string $value): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Resource not found.');
        }
    }
}
