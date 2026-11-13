<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Service\Telegram\TelegramClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramClientTest extends TestCase
{
    public function testSingleAttemptOnTransientFailure(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        });

        $telegram = new TelegramClient($client, new NullLogger());
        $result = $telegram->send('token', 'chat', 'hello');

        self::assertFalse($result['ok']);
        self::assertSame(1, $calls);
    }

    public function testSuccess(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"ok":true}', ['http_code' => 200]),
        ]);

        $telegram = new TelegramClient($client, new NullLogger());
        $result = $telegram->send('token', 'chat', 'hello');

        self::assertTrue($result['ok']);
    }
}
