<?php

declare(strict_types=1);

namespace App\Service\Ingest;

final readonly class IngestLimits
{
    public function __construct(
        public int $maxBodyBytes = 10_485_760,
        public int $maxItems = 50,
        public int $maxItemBytes = 1_048_576,
    ) {
    }
}
