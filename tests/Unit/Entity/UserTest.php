<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testIsEqualToRejectsAChangedPasswordHash(): void
    {
        $stored = (new User())
            ->setUsername('admin')
            ->setPassword('hash-before');

        $refreshed = (new User())
            ->setUsername('admin')
            ->setPassword('hash-after');

        self::assertFalse($stored->isEqualTo($refreshed));
    }

    public function testIsEqualToAcceptsTheSameIdentityAndPassword(): void
    {
        $stored = (new User())
            ->setUsername('admin')
            ->setPassword('hash');
        $refreshed = (new User())
            ->setUsername('admin')
            ->setPassword('hash');

        self::assertTrue($stored->isEqualTo($refreshed));
    }
}
