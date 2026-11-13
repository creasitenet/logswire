<?php

declare(strict_types=1);

namespace App\Message\Maintenance;

/** Worker tick: delete events/issues older than LOGSWIRE_RETENTION_DAYS. */
final class PruneErrorRetentionMessage
{
}
