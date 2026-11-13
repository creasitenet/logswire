<?php

declare(strict_types=1);

namespace App\Probe;

final readonly class ProbeOutcome
{
    public function __construct(
        public bool $up,
        public ?int $statusCode,
        public ?int $responseMs,
        public ?string $error,
    ) {
    }

    public function status(): string
    {
        return $this->up ? 'up' : 'down';
    }
}
