<?php
declare(strict_types=1);
namespace DynamicAllocations;

use InvalidArgumentException;

final class NodeAddress {
    public const LOCAL = '127.0.0.1';

    public static function normalize(string $ip): string {
        $ip = Ports::normalizeIp(trim($ip));
        $first = (int) explode('.', $ip)[0];
        if ($first === 0 || $first === 127 || $first >= 224 || $ip === '169.254.169.254') {
            throw new InvalidArgumentException('Enter a usable non-loopback node IPv4 address.');
        }
        return $ip;
    }

    /** Use a confirmed override, otherwise a literal or unambiguous DNS address. */
    public static function resolve(?string $override, string $fqdn, bool $behindProxy, callable $lookup): array {
        if ($override !== null) return ['ip'=>self::normalize($override), 'source'=>'configured'];
        if ($behindProxy) return ['ip'=>null, 'source'=>'proxy'];
        $fqdn = trim($fqdn);
        $addresses = filter_var($fqdn, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$fqdn] : ($lookup($fqdn) ?: []);
        $addresses = array_values(array_unique($addresses));
        if (count($addresses) !== 1) return ['ip'=>null, 'source'=>'unresolved'];
        try { return ['ip'=>self::normalize($addresses[0]), 'source'=>'node']; }
        catch (InvalidArgumentException) { return ['ip'=>null, 'source'=>'unresolved']; }
    }
}
