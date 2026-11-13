<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Service\Ingest\IngestLimits;
use App\Service\Ingest\SentryEnvelopeParser;
use App\Service\Ingest\SentryPayloadMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Envelopes captured verbatim from real Sentry SDKs with a custom transport,
 * then replayed here. Do not hand-edit the fixtures: re-capture them instead.
 *
 * They pin the ingest contract against what SDKs actually send: optional item
 * `length`, float `timestamp`, root-level `breadcrumbs` array (JS) vs
 * `breadcrumbs.values` (PHP), and non-event items such as `session`.
 */
final class SdkEnvelopeFixturesTest extends TestCase
{
    private SentryEnvelopeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SentryEnvelopeParser(new IngestLimits(maxItems: 50, maxItemBytes: 1_048_576), new NullLogger());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function eventEnvelopes(): iterable
    {
        yield 'sentry.php 4.36.0' => ['sentry-php-4.36.0.txt', [
            'sdkName' => 'sentry.php',
            'sdkVersion' => '4.36.0',
            'platform' => 'php',
            'environment' => 'production',
            'release' => 'shop@1.4.2',
            'level' => 8,
            'title' => 'RuntimeException: Payment provider returned HTTP 502',
            'message' => 'Payment provider returned HTTP 502',
            'culprit' => 'capture.php',
            'exceptionType' => 'RuntimeException',
            'eventTimestamp' => '2026-11-11 11:27:28',
        ]];

        yield 'sentry.javascript.browser 11.12.0' => ['sentry-browser-11.12.0.txt', [
            'sdkName' => 'sentry.javascript.browser',
            'sdkVersion' => '11.12.0',
            'platform' => 'javascript',
            'environment' => 'production',
            'release' => 'shop-web@2.3.0',
            'level' => 8,
            'title' => 'Error: Payment provider returned HTTP 502',
            'message' => 'Payment provider returned HTTP 502',
            'culprit' => 'checkout-4f2a.js::HTMLButtonElement.<anonymous>',
            'exceptionType' => 'Error',
            'eventTimestamp' => '2026-11-11 11:39:23',
        ]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('eventEnvelopes')]
    public function testRealSdkEventEnvelopeIsParsedAndMapped(string $file, array $expected): void
    {
        $parsed = $this->parser->parse($this->fixture($file));

        self::assertSame(0, $parsed->dropped);
        self::assertCount(1, $parsed->events);

        $payload = $parsed->events[0];
        self::assertSame($expected['sdkName'], $payload['sdk']['name']);
        self::assertSame($expected['sdkVersion'], $payload['sdk']['version']);
        self::assertSame($expected['platform'], $payload['platform']);
        self::assertSame($expected['environment'], $payload['environment']);
        self::assertSame($expected['release'], $payload['release']);

        $mapped = SentryPayloadMapper::map($payload);

        self::assertSame($expected['level'], $mapped['level']);
        self::assertSame($expected['title'], $mapped['title']);
        self::assertSame($expected['message'], $mapped['message']);
        self::assertSame($expected['culprit'], $mapped['culprit']);
        self::assertSame($expected['exceptionType'], $mapped['exceptionType']);
        self::assertNotNull($mapped['eventTimestamp']);
        self::assertSame($expected['eventTimestamp'], $mapped['eventTimestamp']->format('Y-m-d H:i:s'));
    }

    public function testFingerprintIsStableAcrossReplays(): void
    {
        $body = $this->fixture('sentry-browser-11.12.0.txt');
        $first = SentryPayloadMapper::map($this->parser->parse($body)->events[0]);
        $second = SentryPayloadMapper::map($this->parser->parse($body)->events[0]);

        self::assertSame($first['fingerprint'], $second['fingerprint']);
        self::assertSame(64, \strlen($first['fingerprint']));
    }

    public function testSessionOnlyEnvelopeIsDroppedWithoutError(): void
    {
        $parsed = $this->parser->parse($this->fixture('sentry-browser-11.12.0-session.txt'));

        self::assertSame([], $parsed->events);
        self::assertSame(1, $parsed->dropped);
    }

    private function fixture(string $file): string
    {
        $path = \dirname(__DIR__, 2).'/Fixtures/envelopes/'.$file;
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }
}
