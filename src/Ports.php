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
    public static function normalizeIp(string $ip): string {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $ip === '0.0.0.0') throw new InvalidArgumentException('Select a specific IPv4 address; wildcard bindings are not supported.');
        return $ip;
    }
}
