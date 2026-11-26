<?php
declare(strict_types=1);
namespace DynamicAllocations;

use InvalidArgumentException;

final class Presets {
    /** @return array{primary:string,additional:string} */
    public static function fromInput(string $primary, string $additional): array {
        $ports=Ports::parse($primary,$additional);
        return ['primary'=>(string)array_shift($ports),'additional'=>implode(', ',$ports)];
    }

    /** Ignore malformed legacy/corrupt settings rather than prefill unsafe values. */
    public static function fromStored(mixed $value): ?array {
        if (!is_array($value) || !is_string($value['primary']??null) || !is_string($value['additional']??null)) return null;
        try { return self::fromInput($value['primary'],$value['additional']); }
        catch (InvalidArgumentException) { return null; }
    }
}
