<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function check(Connection $connection, LoggerInterface $logger): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');

            return new JsonResponse(['status' => 'ok', 'db' => 'up']);
        } catch (\Throwable $e) {
            $logger->error('Health check failed', ['error' => $e->getMessage()]);

            return new JsonResponse(
                ['status' => 'down', 'db' => 'down'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
