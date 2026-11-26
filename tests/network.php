<?php
require __DIR__.'/../src/Ports.php';
require __DIR__.'/../src/NodeAddress.php';
use DynamicAllocations\Ports;
use DynamicAllocations\NodeAddress;
function check($condition) { if (!$condition) throw new RuntimeException('Network test failed'); }
check(Ports::parseList('80, 443') === [80,443]);
check(Ports::parseList('25565') === [25565]);
check(Ports::parseList('1,65535') === [1,65535]);
foreach (['','80,','80,,443',',80','80 443','80,080','80,65536','0','80,-1','80,443-445',implode(',',range(1,33))] as $bad) {
    try { Ports::parseList($bad); throw new RuntimeException('Accepted invalid list'); } catch (InvalidArgumentException) {}
}
$lookup = fn($host) => ['192.0.2.10'];
check(NodeAddress::resolve(null,'node.example.com',false,$lookup) === ['ip'=>'192.0.2.10','source'=>'node']);
check(NodeAddress::resolve(null,'192.0.2.20',false,fn()=>throw new RuntimeException('Unexpected lookup'))['ip'] === '192.0.2.20');
check(NodeAddress::resolve('192.0.2.30','node.example.com',true,$lookup) === ['ip'=>'192.0.2.30','source'=>'configured']);
check(NodeAddress::resolve(null,'node.example.com',true,$lookup)['ip'] === null);
check(NodeAddress::resolve(null,'node.example.com',false,fn()=>['192.0.2.10','192.0.2.11'])['ip'] === null);
check(NodeAddress::resolve(null,'node.example.com',false,fn()=>false)['ip'] === null);
check(NodeAddress::resolve(null,'node.example.com',false,fn()=>['127.0.0.1'])['ip'] === null);
check(NodeAddress::LOCAL === '127.0.0.1');
foreach(['0.0.0.0','127.0.0.1','127.0.0.2','::1','example.com','224.0.0.1'] as $bad) {
    try { NodeAddress::normalize($bad); throw new RuntimeException('Accepted invalid node IP'); } catch (InvalidArgumentException) {}
}
echo "Network tests passed\n";
