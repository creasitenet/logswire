<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * Configured Telegram destination (bot token + chat id): sends Markdown
 * messages, no-ops when unset, and exposes masked values for the settings page.
 */
final class TelegramChannel
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly string $envBotToken = '',
        private readonly string $envChatId = '',
    ) {
    }

    /**
     * @return array{sent: bool, detail: string} `sent` is false when Telegram
     *                                          is not configured or the send failed
     */
    public function notify(string $text): array
    {
        $token = trim($this->envBotToken);
        $chat = trim($this->envChatId);
        if ($token === '' || $chat === '') {
            return ['sent' => false, 'detail' => 'Telegram is not configured.'];
        }

        $result = $this->client->send($token, $chat, $text);

        return [
            'sent' => $result['ok'],
            'detail' => $result['detail'],
        ];
    }

    /**
     * Escapes legacy Markdown control characters in user data (titles,
     * culprits, monitor names) so a stray `_` or backtick cannot break the
     * message formatting.
     */
    public static function escape(string $text): string
    {
        return str_replace(
            ['\\', '_', '*', '`', '['],
            ['\\\\', '\\_', '\\*', '\\`', '\\['],
            $text,
        );
    }

    public function isConfigured(): bool
    {
        return trim($this->envBotToken) !== '' && trim($this->envChatId) !== '';
    }

    public function maskedBotToken(): string
    {
        $token = trim($this->envBotToken);
        if ($token === '') {
            return '—';
        }
        if (strlen($token) <= 8) {
            return '••••••••';
        }

        return substr($token, 0, 4).'…'.substr($token, -4);
    }

    public function maskedChatId(): string
    {
        $chat = trim($this->envChatId);
        if ($chat === '') {
            return '—';
        }
        if (strlen($chat) <= 4) {
            return '••••';
        }

        return substr($chat, 0, 2).'…'.substr($chat, -2);
    }
}
