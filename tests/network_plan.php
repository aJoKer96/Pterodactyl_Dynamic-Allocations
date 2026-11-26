<?php
require __DIR__.'/../src/NetworkPlan.php';
use DynamicAllocations\NetworkPlan;
function check($ok) {if(!$ok) throw new RuntimeException('Network plan test failed');}
$rows=[['id'=>2,'ip'=>'127.0.0.1','port'=>443],['id'=>1,'ip'=>'127.0.0.1','port'=>80]];
$s=NetworkPlan::snapshot(1,$rows);
check($s['ports']==='80, 443' && !$s['mixed']);
check(NetworkPlan::snapshot(1,array_reverse($rows))['revision']===$s['revision']);
check(NetworkPlan::snapshot(2,$rows)['ports']==='443, 80');
check(NetworkPlan::snapshot(2,$rows)['revision']!==$s['revision']);
check(NetworkPlan::diff([1,2],[2,3])===['primary'=>2,'add'=>[3],'remove'=>[1]]);
check(NetworkPlan::diff([1,2],[1,2])===['primary'=>1,'add'=>[],'remove'=>[]]);
check(NetworkPlan::diff([1,2],[3,4])===['primary'=>3,'add'=>[3,4],'remove'=>[1,2]]);
$rows[0]['ip']='192.0.2.1';check(NetworkPlan::snapshot(1,$rows)['mixed']);
foreach ([[],[1,1]] as $bad) {try {NetworkPlan::diff([1],$bad);throw new RuntimeException('Accepted bad desired list');}catch(InvalidArgumentException){}}
echo "Network diff and snapshot tests passed\n";
