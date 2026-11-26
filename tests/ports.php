<?php
require __DIR__.'/../src/Ports.php';
use DynamicAllocations\Ports;
function check($test) { if(!$test) throw new RuntimeException('Test failed'); }
check(Ports::parse('80','443, 25565') === [80,443,25565]);
check(Ports::parse('1','65535') === [1,65535]);
check(Ports::parse('80','') === [80]);
foreach ([['0',''],['65536',''],['80','080'],['80','443-445'],['x',''],['80','-1']] as [$p,$a]) { try { Ports::parse($p,$a); throw new RuntimeException('Accepted invalid input'); } catch(InvalidArgumentException) {} }
check(Ports::normalizeIp('127.0.0.1')==='127.0.0.1');
foreach (['0.0.0.0','::1','example.com'] as $ip) {try {Ports::normalizeIp($ip);throw new RuntimeException('Accepted invalid IP');}catch(InvalidArgumentException){}}
echo "Port parser tests passed\n";
