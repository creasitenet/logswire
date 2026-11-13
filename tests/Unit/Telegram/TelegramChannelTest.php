<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Service\Telegram\TelegramChannel;
use PHPUnit\Framework\TestCase;

final class TelegramChannelTest extends TestCase
{
    public function testEscapesLegacyMarkdownControlCharacters(): void
    {
        self::assertSame('a\_b\*c\`d\[e\\\\f', TelegramChannel::escape('a_b*c`d[e\\f'));
    }

    public function testLeavesPlainTextUntouched(): void
    {
        self::assertSame('RuntimeException: boom', TelegramChannel::escape('RuntimeException: boom'));
    }
}
