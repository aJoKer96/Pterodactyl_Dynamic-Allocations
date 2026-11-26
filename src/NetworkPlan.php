<?php
declare(strict_types=1);
namespace DynamicAllocations;
use InvalidArgumentException;
final class NetworkPlan {
    /** Canonical ordering: primary first, then numeric port and ID. */
    public static function snapshot(int $primary, array $bindings): array {
        usort($bindings,fn($a,$b)=>[$a['id']!==$primary,$a['port'],$a['id']] <=> [$b['id']!==$primary,$b['port'],$b['id']]);
        if (!$bindings || $bindings[0]['id']!==$primary) throw new InvalidArgumentException('Server has no valid primary allocation.');
        $ips=array_unique(array_column($bindings,'ip'));
        return ['ip'=>$bindings[0]['ip'],'ports'=>implode(', ',array_column($bindings,'port')),'mixed'=>count($ips)!==1,
            'bindings'=>$bindings,'revision'=>hash('sha256',json_encode([$primary,$bindings],JSON_THROW_ON_ERROR))];
    }
    public static function diff(array $current,array $desired): array {
        if (!$desired || count(array_unique($desired))!==count($desired)) throw new InvalidArgumentException('Invalid desired allocations.');
        return ['primary'=>$desired[0],'add'=>array_values(array_diff($desired,$current)),'remove'=>array_values(array_diff($current,$desired))];
    }
}
