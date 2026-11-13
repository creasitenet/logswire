<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\Event;

final readonly class IngestResult
{
    /**
     * @param list<Event> $events
     */
    public function __construct(
        public array $events,
        public int $dropped,
    ) {
    }
}
