<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Energy\Consumption;
function ensure_test(bool $ok,string $message): void { if(!$ok) throw new RuntimeException('FAIL: '.$message); }
$meters=[
 ['id'=>1,'parent_id'=>null,'building_id'=>1,'unit_id'=>10,'type'=>'electricity','name'=>'Hlavní'],
 ['id'=>2,'parent_id'=>1,'building_id'=>1,'unit_id'=>20,'type'=>'electricity','name'=>'Podružný'],
];
$values=[1=>['start'=>10000,'end'=>11000],2=>['start'=>3000,'end'=>3400]];
$r=Consumption::calculate($meters,$values,[1=>1,2=>1.02]);
ensure_test(abs($r['allocations'][10]['electricity']-592)<.0001,'zbytek bytu 1');
ensure_test(abs($r['allocations'][20]['electricity']-408)<.0001,'koeficient bytu 2');
ensure_test(!$r['warnings'],'bez upozornění');
$meters[]=['id'=>3,'parent_id'=>2,'building_id'=>1,'unit_id'=>30,'type'=>'electricity','name'=>'Podpodružný'];
$values[3]=['start'=>1,'end'=>101];
$r=Consumption::calculate($meters,$values,[1=>1,2=>1,3=>1]);
ensure_test($r['allocations'][10]['electricity']===600.0,'vnější zbytek');
ensure_test($r['allocations'][20]['electricity']===300.0,'vnitřní zbytek');
ensure_test($r['allocations'][30]['electricity']===100.0,'vnitřní měřidlo');
$r=Consumption::calculate(array_slice($meters,0,2),$values,[1=>1,2=>3]);
ensure_test(count($r['warnings'])>0,'detekce záporného zbytku');
unset($values[2]);$r=Consumption::calculate(array_slice($meters,0,2),$values,[1=>1,2=>1]);
ensure_test(!isset($r['allocations'][10]),'nesmí se spočítat hlavní zbytek při chybějícím podružném');
ensure_test(count($r['warnings'])>0,'chybějící odečet varuje');
echo "OK: 4 výpočetní scénáře\n";
