<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class SentryEnvelopeParser
{
    public function __construct(
        private readonly IngestLimits $limits,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function parse(string $body): ParsedEnvelope
    {
        if ($body === '') {
            throw new BadRequestHttpException('Empty envelope.');
        }

        $offset = 0;
        $envelopeHeader = $this->readLine($body, $offset);
        if ($envelopeHeader === null || trim($envelopeHeader) === '') {
            throw new BadRequestHttpException('Missing envelope header.');
        }

        $events = [];
        $dropped = 0;
        $itemIndex = 0;

        while ($offset < \strlen($body)) {
            $this->skipBlankLines($body, $offset);
            if ($offset >= \strlen($body)) {
                break;
            }

            $headerLine = $this->readLine($body, $offset);
            if ($headerLine === null || trim($headerLine) === '') {
                break;
            }

            ++$itemIndex;
            if ($itemIndex > $this->limits->maxItems) {
                ++$dropped;
                $this->logger->debug('Envelope item dropped: max_items', ['index' => $itemIndex]);

                break;
            }

            try {
                $itemHeader = json_decode($headerLine, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                ++$dropped;

                break;
            }

            if (!\is_array($itemHeader)) {
                ++$dropped;

                continue;
            }

            $type = (string) ($itemHeader['type'] ?? '');
            $length = $itemHeader['length'] ?? null;
            $payload = $this->readItemPayload($body, $offset, $length);

            if ($type !== 'event') {
                ++$dropped;
                if ($type !== '' && $type !== 'client_report') {
                    $this->logger->debug('Envelope item dropped: unsupported type', ['type' => $type]);
                }

                continue;
            }

            if (\strlen($payload) > $this->limits->maxItemBytes) {
                ++$dropped;
                $this->logger->debug('Envelope item dropped: max_item_bytes');

                continue;
            }

            try {
                $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                ++$dropped;

                continue;
            }

            if (!\is_array($event)) {
                ++$dropped;

                continue;
            }

            $events[] = $event;
        }

        if ($events === [] && $dropped === 0) {
            throw new BadRequestHttpException('No envelope items found.');
        }

        return new ParsedEnvelope($events, $dropped);
    }

    private function readItemPayload(string $body, int &$offset, mixed $length): string
    {
        if (\is_int($length) && $length >= 0) {
            $chunk = substr($body, $offset, $length);
            $offset += $length;
            if (\strlen($chunk) < $length) {
                throw new BadRequestHttpException('Unexpected end of envelope item.');
            }
            $this->skipOptionalNewline($body, $offset);

            return $chunk;
        }

        $line = $this->readLine($body, $offset);

        return $line ?? '';
    }

    private function readLine(string $body, int &$offset): ?string
    {
        if ($offset >= \strlen($body)) {
            return null;
        }

        $next = strpos($body, "\n", $offset);
        if ($next === false) {
            $line = substr($body, $offset);
            $offset = \strlen($body);

            return $line;
        }

        $line = substr($body, $offset, $next - $offset);
        $offset = $next + 1;

        return $line;
    }

    private function skipBlankLines(string $body, int &$offset): void
    {
        while ($offset < \strlen($body) && $body[$offset] === "\n") {
            ++$offset;
        }
    }

    private function skipOptionalNewline(string $body, int &$offset): void
    {
        if ($offset < \strlen($body) && $body[$offset] === "\n") {
            ++$offset;
        }
    }
}
