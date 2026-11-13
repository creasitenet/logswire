<?php

declare(strict_types=1);

namespace App\Service\Issue;

/**
 * Sentry-compatible issue levels (stored as SMALLINT on {@see \App\Entity\Issue}).
 */
final class IssueLevel
{
    public const int FATAL = 10;
    public const int ERROR = 8;
    public const int WARNING = 6;
    public const int INFO = 4;
    public const int DEBUG = 2;
    public const int TRACE = 0;

    /** Level query param from the issues UI → stored severity value. */
    public static function fromFilter(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match (strtolower($raw)) {
            'fatal', 'critical' => self::FATAL,
            'error' => self::ERROR,
            'warning', 'warn' => self::WARNING,
            'info', 'log' => self::INFO,
            'debug' => self::DEBUG,
            'trace' => self::TRACE,
            default => is_numeric($raw) ? max(0, min(10, (int) $raw)) : null,
        };
    }

    public static function label(int $level): string
    {
        return match (true) {
            $level >= self::FATAL => 'fatal',
            $level >= self::ERROR => 'error',
            $level >= self::WARNING => 'warning',
            $level >= self::INFO => 'info',
            $level >= self::DEBUG => 'debug',
            default => 'trace',
        };
    }
}
