<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait CsrfGuardTrait
{
    protected function guardCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
