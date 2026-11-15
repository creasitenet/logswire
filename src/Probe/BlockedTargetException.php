<?php

declare(strict_types=1);

namespace App\Probe;

/**
 * Thrown when a monitor URL (or a redirect hop) targets a cloud metadata
 * endpoint. Kept separate from transport errors: it is a policy rejection,
 * not a network failure.
 */
final class BlockedTargetException extends \RuntimeException
{
}
