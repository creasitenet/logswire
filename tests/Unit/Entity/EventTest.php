<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testSettersTruncateValuesToColumnLength(): void
    {
        $event = (new Event())
            ->setFingerprint(str_repeat('f', 300))
            ->setExceptionType(str_repeat('t', 300))
            ->setPlatform(str_repeat('p', 100))
            ->setEnvironment(str_repeat('e', 100))
            ->setReleaseTag(str_repeat('r', 200))
            ->setSdkName(str_repeat('n', 200))
            ->setSdkVersion(str_repeat('v', 100))
            ->setRequestMethod(str_repeat('m', 20))
            ->setRequestUrl(str_repeat('u', 600))
            ->setOsName(str_repeat('o', 100))
            ->setBrowserName(str_repeat('b', 100))
            ->setServerName(str_repeat('s', 200))
            ->setClientIp(str_repeat('i', 60))
            ->setUserAgent(str_repeat('a', 600));

        self::assertSame(255, mb_strlen((string) $event->getFingerprint()));
        self::assertSame(255, mb_strlen((string) $event->getExceptionType()));
        self::assertSame(64, mb_strlen((string) $event->getPlatform()));
        self::assertSame(64, mb_strlen((string) $event->getEnvironment()));
        self::assertSame(128, mb_strlen((string) $event->getReleaseTag()));
        self::assertSame(128, mb_strlen((string) $event->getSdkName()));
        self::assertSame(64, mb_strlen((string) $event->getSdkVersion()));
        self::assertSame(10, mb_strlen((string) $event->getRequestMethod()));
        self::assertSame(512, mb_strlen((string) $event->getRequestUrl()));
        self::assertSame(64, mb_strlen((string) $event->getOsName()));
        self::assertSame(64, mb_strlen((string) $event->getBrowserName()));
        self::assertSame(128, mb_strlen((string) $event->getServerName()));
        self::assertSame(45, mb_strlen((string) $event->getClientIp()));
        self::assertSame(512, mb_strlen((string) $event->getUserAgent()));
    }

    public function testSettersKeepShortValuesAndNull(): void
    {
        $event = (new Event())
            ->setPlatform('php')
            ->setEnvironment(null)
            ->setReleaseTag(null)
            ->setSdkName(null)
            ->setSdkVersion(null)
            ->setRequestUrl(null);

        self::assertSame('php', $event->getPlatform());
        self::assertNull($event->getEnvironment());
        self::assertNull($event->getReleaseTag());
        self::assertNull($event->getSdkName());
        self::assertNull($event->getSdkVersion());
        self::assertNull($event->getRequestUrl());
    }

    public function testTruncationIsMultibyteSafe(): void
    {
        $event = (new Event())->setPlatform(str_repeat('é', 100));

        self::assertSame(64, mb_strlen((string) $event->getPlatform()));
        self::assertSame(str_repeat('é', 64), $event->getPlatform());
    }
}
