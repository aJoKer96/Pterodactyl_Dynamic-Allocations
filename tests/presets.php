<?php
require __DIR__.'/../src/Ports.php';
require __DIR__.'/../src/Presets.php';
use DynamicAllocations\Presets;
function check($condition) { if (!$condition) throw new RuntimeException('Preset test failed'); }
check(Presets::fromInput('080','443, 25565')===['primary'=>'80','additional'=>'443, 25565']);
check(Presets::fromInput('1','65535')===['primary'=>'1','additional'=>'65535']);
check(Presets::fromInput('80','')===['primary'=>'80','additional'=>'']);
check(Presets::fromStored(['primary'=>'80','additional'=>'443'])===['primary'=>'80','additional'=>'443']);
foreach ([null, 80, [], ['primary'=>80,'additional'=>'443'], ['primary'=>'80','additional'=>'080'], ['primary'=>'0','additional'=>''], ['primary'=>'80','additional'=>'65536']] as $invalid) check(Presets::fromStored($invalid)===null);
foreach ([['0',''],['80','80'],['80','443-445'],['65536',''],['1',implode(',',range(2,33))]] as [$p,$a]) {try {Presets::fromInput($p,$a);throw new RuntimeException('Accepted invalid preset');}catch(InvalidArgumentException){}}
echo "Preset tests passed\n";
