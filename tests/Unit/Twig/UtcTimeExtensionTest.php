<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\UtcTimeExtension;
use PHPUnit\Framework\TestCase;

final class UtcTimeExtensionTest extends TestCase
{
    public function testFormatsAtomInUtc(): void
    {
        $ext = new UtcTimeExtension();
        self::assertSame(
            '9 Nov 2026, 15:13 UTC',
            $ext->formatUtcHuman('2026-11-09T15:13:39+00:00'),
        );
    }

    public function testFormatsUnixTimestampsInUtc(): void
    {
        $ext = new UtcTimeExtension();
        self::assertSame('15 Jan 2027, 08:00 UTC', $ext->formatTsHuman(1800000000));
        self::assertSame('15 Jan 2027, 08:00 UTC', $ext->formatTsHuman('1800000000'));
        self::assertSame('—', $ext->formatTsHuman(null));
        self::assertSame('—', $ext->formatTsHuman(0));
    }
}
