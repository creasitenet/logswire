<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Service\Ingest\PayloadScrubber;
use PHPUnit\Framework\TestCase;

final class PayloadScrubberTest extends TestCase
{
    public function testRedactsSensitiveKeysRecursively(): void
    {
        $scrubber = new PayloadScrubber();

        $payload = [
            'message' => 'checkout failed',
            'request' => [
                'url' => 'https://shop.example/checkout',
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer secret-token',
                    'Cookie' => 'session=abc123',
                    'X-Api-Key' => 'key-123',
                ],
                'env' => [
                    'DB_PASSWORD' => 'hunter2',
                    'REQUEST_METHOD' => 'POST',
                ],
            ],
            'extra' => [
                'access_token' => 'abc',
                'nested' => ['client_secret' => 'shh', 'safe' => 'keep-me'],
            ],
        ];

        $scrubbed = $scrubber->scrub($payload);

        self::assertSame('checkout failed', $scrubbed['message']);
        self::assertSame('https://shop.example/checkout', $scrubbed['request']['url']);
        self::assertSame('application/json', $scrubbed['request']['headers']['Accept']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['request']['headers']['Authorization']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['request']['headers']['Cookie']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['request']['headers']['X-Api-Key']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['request']['env']['DB_PASSWORD']);
        self::assertSame('POST', $scrubbed['request']['env']['REQUEST_METHOD']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['extra']['access_token']);
        self::assertSame(PayloadScrubber::REDACTED, $scrubbed['extra']['nested']['client_secret']);
        self::assertSame('keep-me', $scrubbed['extra']['nested']['safe']);
    }

    public function testRedactsHeaderPairs(): void
    {
        $scrubber = new PayloadScrubber();

        $scrubbed = $scrubber->scrub([
            'request' => [
                'headers' => [
                    ['User-Agent', 'PHPUnit'],
                    ['Cookie', 'a=b'],
                ],
            ],
        ]);

        self::assertSame(['User-Agent', 'PHPUnit'], $scrubbed['request']['headers'][0]);
        self::assertSame(['Cookie', PayloadScrubber::REDACTED], $scrubbed['request']['headers'][1]);
    }

    public function testCanBeDisabled(): void
    {
        $scrubber = new PayloadScrubber(enabled: false);

        self::assertSame(
            ['password' => 'hunter2'],
            $scrubber->scrub(['password' => 'hunter2']),
        );
    }

    public function testCapsExtremelyDeepStructures(): void
    {
        $payload = ['leaf' => 'value'];
        for ($i = 0; $i < 20; ++$i) {
            $payload = ['nested' => $payload];
        }

        $scrubbed = (new PayloadScrubber())->scrub($payload);
        $serialized = json_encode($scrubbed, JSON_THROW_ON_ERROR);

        self::assertStringContainsString(PayloadScrubber::REDACTED, $serialized);
    }
}
