<?php

declare(strict_types=1);

namespace App\Probe;

/**
 * Always-block list for cloud metadata endpoints.
 *
 * This OSS instance deliberately allows RFC1918 and loopback targets so people
 * can monitor their own LAN (see SECURITY.md). Metadata endpoints are different:
 * they are never a legitimate monitor target, and they are the classic SSRF
 * pivot to instance credentials. They stay blocked even when private networks
 * are allowed.
 *
 * The check runs on the monitor URL and on every redirect hop (HttpProbe).
 */
final class MetadataEndpointGuard
{
    /** @var list<string> */
    private const BLOCKED_IPS = [
        '169.254.169.254', // AWS / GCP / Azure / OpenStack IMDS
        '169.254.170.2',   // AWS ECS task metadata
        '100.100.100.200', // Alibaba Cloud metadata
        'fd00:ec2::254',   // AWS IMDS over IPv6
        'fd00:ec2::23',    // AWS ECS over IPv6
    ];

    /** @var list<string> */
    private const BLOCKED_HOSTS = [
        'metadata.google.internal',
        'metadata.goog',
    ];

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn (string $host): array => self::resolveAll($host);
    }

    /**
     * @throws BlockedTargetException
     */
    public function assertAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedTargetException('unsupported_scheme');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new BlockedTargetException('invalid_url');
        }

        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        if (\in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new BlockedTargetException('metadata_host:'.$host);
        }

        // Literal IP: no DNS lookup, and no IPv6-literal resolution trap.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                throw new BlockedTargetException('metadata_ip:'.$host);
            }

            return;
        }

        // Resolve and reject if ANY address is a metadata endpoint. Catches
        // CNAMEs and numeric forms (gethostbynamel parses 2852039166).
        foreach (($this->resolver)($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new BlockedTargetException('metadata_ip:'.$ip);
            }
        }
    }

    public function isBlockedIp(string $ip): bool
    {
        $bin = @inet_pton($this->normaliseIp($ip));
        if ($bin === false) {
            return false;
        }

        foreach (self::BLOCKED_IPS as $blocked) {
            $blockedBin = @inet_pton($blocked);
            if ($blockedBin !== false && $blockedBin === $bin) {
                return true;
            }
        }

        return false;
    }

    /**
     * ::ffff:169.254.169.254 must be judged as 169.254.169.254.
     */
    private function normaliseIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false || \strlen($bin) !== 16) {
            return $ip;
        }

        if (substr($bin, 0, 10) !== str_repeat("\x00", 10) || substr($bin, 10, 2) !== "\xff\xff") {
            return $ip;
        }

        $v4 = @inet_ntop(substr($bin, 12, 4));

        return $v4 === false ? $ip : $v4;
    }

    /**
     * @return list<string>
     */
    private static function resolveAll(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (\is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        if (\function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (\is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
