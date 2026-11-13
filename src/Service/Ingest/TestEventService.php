<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Project;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Builds a synthetic Sentry-shaped payload and runs it through IngestService.
 */
final class TestEventService
{
    public function __construct(
        private readonly IngestService $ingest,
    ) {
    }

    public function send(Project $project, ?string $message = null): void
    {
        $msg = trim((string) $message);
        if ($msg === '') {
            $msg = 'Logswire test event — payment failed';
        }
        if (mb_strlen($msg) > 500) {
            throw new BadRequestHttpException('Message too long (max 500).');
        }

        $eventId = Uuid::v7()->toRfc4122();
        $fingerprint = hash('sha256', 'RuntimeException|CheckoutController.php::pay|84');

        $payload = [
            'event_id' => $eventId,
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'logswire-test',
            'transaction' => 'CheckoutController::pay',
            'server_name' => 'logswire-self-test',
            'release' => 'logswire-test@1.0.0',
            'environment' => 'test',
            'message' => ['formatted' => $msg, 'message' => $msg],
            'tags' => ['logswire.test' => '1'],
            'exception' => [
                'values' => [[
                    'type' => 'RuntimeException',
                    'value' => $msg,
                    'stacktrace' => [
                        'frames' => [
                            [
                                'filename' => 'CheckoutController.php',
                                'function' => 'pay',
                                'lineno' => 84,
                                'in_app' => true,
                            ],
                            [
                                'filename' => 'index.php',
                                'function' => null,
                                'lineno' => 17,
                                'in_app' => true,
                            ],
                        ],
                    ],
                ]],
            ],
            'sdk' => [
                'name' => 'logswire-test',
                'version' => '1.0.0',
            ],
        ];

        $this->ingest->ingestPayloads($project, [$payload], $fingerprint);
    }
}
