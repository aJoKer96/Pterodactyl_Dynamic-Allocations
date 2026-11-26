<?php
declare(strict_types=1);
namespace DynamicAllocations;
use InvalidArgumentException;
final class Ports {
    /** @return list<int> */
    public static function parse(string $primary, string $additional): array {
        $items = array_merge([trim($primary)], trim($additional) === '' ? [] : preg_split('/[\s,]+/', trim($additional)));
        if (count($items) > 32) throw new InvalidArgumentException('At most 32 ports per server.');
        $ports = [];
        foreach ($items as $item) {
            if (!preg_match('/^[0-9]{1,5}$/D', $item) || (int)$item < 1 || (int)$item > 65535) throw new InvalidArgumentException('Ports must be integers from 1 to 65535.');
            $port = (int)$item;
            if (in_array($port, $ports, true)) throw new InvalidArgumentException('Duplicate port: '.$port);
            $ports[] = $port;
        }
        return $ports;
    }
    /** Comma-separated input, first port is primary. Reject empty entries. */
    public static function parseList(string $input): array {
        $parts = array_map('trim', explode(',', $input));
        if (in_array('', $parts, true)) throw new InvalidArgumentException('Enter a comma-separated list of ports without empty entries.');
        foreach ($parts as $part) {
            if (!preg_match('/^[0-9]{1,5}$/D', $part)) throw new InvalidArgumentException('Ports must be comma-separated integers.');
        }
        return self::parse(array_shift($parts), implode(',', $parts));
    }
    public static function normalizeIp(string $ip): string {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $ip === '0.0.0.0') throw new InvalidArgumentException('Select a specific IPv4 address; wildcard bindings are not supported.');
        return $ip;
    }
}
