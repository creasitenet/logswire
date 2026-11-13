<?php

declare(strict_types=1);

namespace App\Service\Ingest;

final readonly class ParsedEnvelope
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public array $events,
        public int $dropped,
    ) {
    }
}
