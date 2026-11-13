<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingest;

use App\Service\Ingest\IngestLimits;
use App\Service\Ingest\SentryEnvelopeParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SentryEnvelopeParserTest extends TestCase
{
    private SentryEnvelopeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SentryEnvelopeParser(new IngestLimits(maxItems: 10, maxItemBytes: 65536), new NullLogger());
    }

    public function testParsesSingleEvent(): void
    {
        $event = json_encode(['level' => 'error', 'message' => 'hello'], JSON_THROW_ON_ERROR);
        $itemHeader = json_encode(['type' => 'event', 'length' => \strlen($event)], JSON_THROW_ON_ERROR);
        $body = "{}\n{$itemHeader}\n{$event}";

        $parsed = $this->parser->parse($body);

        self::assertCount(1, $parsed->events);
        self::assertSame('hello', $parsed->events[0]['message']);
        self::assertSame(0, $parsed->dropped);
    }

    public function testDropsTransactionKeepsEvent(): void
    {
        $event = json_encode(['level' => 'error', 'message' => 'e1'], JSON_THROW_ON_ERROR);
        $txn = json_encode(['transaction' => 'GET /'], JSON_THROW_ON_ERROR);
        $h1 = json_encode(['type' => 'transaction', 'length' => \strlen($txn)], JSON_THROW_ON_ERROR);
        $h2 = json_encode(['type' => 'event', 'length' => \strlen($event)], JSON_THROW_ON_ERROR);
        $body = "{}\n{$h1}\n{$txn}\n{$h2}\n{$event}";

        $parsed = $this->parser->parse($body);

        self::assertCount(1, $parsed->events);
        self::assertGreaterThanOrEqual(1, $parsed->dropped);
    }

    public function testGzipRoundTripViaControllerHelper(): void
    {
        $event = json_encode(['message' => 'gz'], JSON_THROW_ON_ERROR);
        $itemHeader = json_encode(['type' => 'event', 'length' => \strlen($event)], JSON_THROW_ON_ERROR);
        $raw = "{}\n{$itemHeader}\n{$event}";
        $gz = gzencode($raw);
        self::assertNotFalse($gz);
        $decoded = gzdecode($gz);
        self::assertIsString($decoded);
        $parsed = $this->parser->parse($decoded);
        self::assertCount(1, $parsed->events);
    }
}
