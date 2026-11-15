<?php

declare(strict_types=1);

namespace App\Tests\Unit\Probe;

use App\Probe\BlockedTargetException;
use App\Probe\MetadataEndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;

final class MetadataEndpointGuardTest extends \PHPUnit\Framework\TestCase
{
    public function testBlocksLiteralMetadataIpv4(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new MetadataEndpointGuard())->assertAllowed('http://169.254.169.254/latest/meta-data/');
    }

    public function testBlocksLiteralMetadataIpv6(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new MetadataEndpointGuard())->assertAllowed('http://[fd00:ec2::254]/');
    }

    public function testBlocksMetadataHostname(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new MetadataEndpointGuard())->assertAllowed('http://metadata.google.internal/computeMetadata/v1/');
    }

    public function testBlocksMetadataHostnameWithTrailingDot(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new MetadataEndpointGuard())->assertAllowed('http://metadata.google.internal./');
    }

    public function testBlocksHostnameThatResolvesToMetadata(): void
    {
        $guard = new MetadataEndpointGuard(static fn (string $host): array => ['169.254.169.254']);

        $this->expectException(BlockedTargetException::class);

        $guard->assertAllowed('http://rebind.example/');
    }

    public function testBlocksIpv4MappedMetadata(): void
    {
        $guard = new MetadataEndpointGuard(static fn (string $host): array => ['::ffff:169.254.169.254']);

        $this->expectException(BlockedTargetException::class);

        $guard->assertAllowed('http://rebind.example/');
    }

    public function testBlocksUnsupportedScheme(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new MetadataEndpointGuard())->assertAllowed('file:///etc/passwd');
    }

    public function testAllowsPublicAddress(): void
    {
        (new MetadataEndpointGuard(static fn (string $host): array => ['93.184.216.34']))
            ->assertAllowed('https://example.com/health');

        $this->addToAssertionCount(1);
    }

    public function testAllowsPrivateNonMetadataTarget(): void
    {
        // Homelab is the whole point of the OSS mode: a LAN address is fine.
        (new MetadataEndpointGuard())->assertAllowed('http://192.168.1.10:8080/');

        $this->addToAssertionCount(1);
    }

    #[DataProvider('blockedIps')]
    public function testIsBlockedIp(string $ip): void
    {
        self::assertTrue((new MetadataEndpointGuard())->isBlockedIp($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedIps(): iterable
    {
        yield 'aws imds' => ['169.254.169.254'];
        yield 'aws ecs' => ['169.254.170.2'];
        yield 'alibaba' => ['100.100.100.200'];
        yield 'aws ipv6' => ['fd00:ec2::254'];
        yield 'aws ecs ipv6' => ['fd00:ec2::23'];
        yield 'ipv4-mapped' => ['::ffff:169.254.169.254'];
    }

    public function testIsBlockedIpReturnsFalseForPublicIp(): void
    {
        self::assertFalse((new MetadataEndpointGuard())->isBlockedIp('93.184.216.34'));
    }
}
