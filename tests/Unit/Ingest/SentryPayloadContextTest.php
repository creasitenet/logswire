<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Service\Ingest\SentryEnvelopeParser;
use App\Service\Ingest\IngestLimits;
use App\Service\Ingest\SentryPayloadMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SentryPayloadContextTest extends TestCase
{
    private SentryEnvelopeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SentryEnvelopeParser(new IngestLimits(maxItems: 50, maxItemBytes: 1_048_576), new NullLogger());
    }

    public function testBrowserFixtureExtractsUserAgentAndRequestUrl(): void
    {
        $body = (string) file_get_contents(__DIR__.'/../../Fixtures/envelopes/sentry-browser-11.12.0.txt');
        $payload = $this->parser->parse($body)->events[0];

        $context = SentryPayloadMapper::mapContext($payload);

        self::assertSame('https://shop.example.com/checkout/pay', $context['requestUrl']);
        self::assertStringContainsString('Mozilla/5.0', (string) $context['userAgent']);
        self::assertNull($context['clientIp']);
    }

    public function testPhpRequestEnvRemoteAddr(): void
    {
        $context = SentryPayloadMapper::mapContext([
            'request' => [
                'url' => 'https://api.example.com/orders',
                'method' => 'POST',
                'headers' => ['User-Agent' => 'LogswireTest/1.0'],
                'env' => ['REMOTE_ADDR' => '203.0.113.10'],
            ],
            'contexts' => ['os' => ['name' => 'Linux']],
        ]);

        self::assertSame('POST', $context['requestMethod']);
        self::assertSame('203.0.113.10', $context['clientIp']);
        self::assertSame('LogswireTest/1.0', $context['userAgent']);
        self::assertSame('Linux', $context['osName']);
    }

    public function testSkipsSentryAutoIpPlaceholder(): void
    {
        $context = SentryPayloadMapper::mapContext([
            'user' => ['ip_address' => '{{auto}}'],
        ]);

        self::assertNull($context['clientIp']);
    }
}
