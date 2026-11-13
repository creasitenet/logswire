<?php

declare(strict_types=1);

namespace App\Tests\Unit\Issue;

use App\Service\Issue\IssueLevel;
use PHPUnit\Framework\TestCase;

final class IssueLevelTest extends TestCase
{
    public function testFromFilterMapsUiValuesToStoredSeverity(): void
    {
        self::assertSame(IssueLevel::ERROR, IssueLevel::fromFilter('error'));
        self::assertSame(IssueLevel::WARNING, IssueLevel::fromFilter('warn'));
        self::assertNull(IssueLevel::fromFilter(''));
        self::assertNull(IssueLevel::fromFilter(null));
    }

    public function testLabelMatchesFilterBuckets(): void
    {
        self::assertSame('error', IssueLevel::label(IssueLevel::ERROR));
        self::assertSame('warning', IssueLevel::label(IssueLevel::WARNING));
    }
}
