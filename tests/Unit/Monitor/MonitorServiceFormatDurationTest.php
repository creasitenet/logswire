<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitor;

use App\Service\Monitor\MonitorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonitorServiceFormatDurationTest extends TestCase
{
    #[DataProvider('durations')]
    public function testFormatsDuration(int $seconds, string $expected): void
    {
        self::assertSame($expected, MonitorService::formatDuration($seconds));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function durations(): iterable
    {
        yield 'seconds' => [45, '45s'];
        yield 'last second below a minute' => [59, '59s'];
        yield 'minutes' => [300, '5m'];
        yield 'hours and minutes' => [7500, '2h05m'];
        yield 'days and hours' => [273600, '3d04h'];
    }
}
