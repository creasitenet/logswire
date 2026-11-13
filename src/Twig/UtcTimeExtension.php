<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Human-readable timestamps; all stored values are interpreted as UTC for display.
 */
final class UtcTimeExtension extends AbstractExtension
{
    private const string DISPLAY = 'j M Y, H:i \U\T\C';

    public function getFilters(): array
    {
        return [
            new TwigFilter('utc_human', $this->formatUtcHuman(...), ['is_safe' => ['html']]),
            new TwigFilter('ts_human', $this->formatTsHuman(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Uptime tables store Unix seconds instead of ISO strings.
     */
    public function formatTsHuman(int|string|null $ts): string
    {
        if ($ts === null || $ts === '') {
            return '—';
        }

        $value = (int) $ts;
        if ($value <= 0) {
            return '—';
        }

        return (new \DateTimeImmutable('@'.$value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::DISPLAY);
    }

    public function formatUtcHuman(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return $iso;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format(self::DISPLAY);
    }
}
