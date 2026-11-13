<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level Telegram Bot API transport: a single short attempt, so a Telegram
 * outage can never stall Sentry ingest.
 */
final class TelegramClient
{
    private const float TIMEOUT_SECONDS = 3.0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function send(string $botToken, string $chatId, string $text): array
    {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $botToken);

        try {
            $response = $this->http->request('POST', $url, [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return ['ok' => true, 'detail' => 'Message sent'];
            }
            if ($status >= 400 && $status < 500) {
                $this->logger->warning('Telegram 4xx', ['status' => $status]);

                return ['ok' => false, 'detail' => 'Telegram rejected the message (HTTP '.$status.').'];
            }
            $this->logger->warning('Telegram HTTP error', ['status' => $status]);

            return ['ok' => false, 'detail' => 'Telegram HTTP '.$status];
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram network error', ['error' => $e->getMessage()]);

            return ['ok' => false, 'detail' => 'Telegram network error'];
        }
    }
}
