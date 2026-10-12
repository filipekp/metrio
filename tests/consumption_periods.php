<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Energy\Consumption;

function check_period(bool $okay,string $label): void {
    if (!$okay) throw new RuntimeException('FAIL: '.$label);
    echo 'OK: '.$label.PHP_EOL;
}
function approx_period(float $left,float $right): bool { return abs($left-$right)<0.0001; }

// První známý odečet 1. ledna, další 12. září a 12. října.
// Dříve bez odečtu k přesnému 1. únoru a 31. říjnu výsledek zcela chyběl.
$main=[
    ['reading_date'=>'2026-01-01','value'=>'0'],
    ['reading_date'=>'2026-09-12','value'=>'3546'],
    ['reading_date'=>'2026-10-12','value'=>'4126'],
];
$child=[
    ['reading_date'=>'2026-01-01','value'=>'154'],
    ['reading_date'=>'2026-09-12','value'=>'1583'],
    ['reading_date'=>'2026-10-12','value'=>'1721'],
];
$a=Consumption::boundary($main,'2026-02-01');
$b=Consumption::boundary($main,'2026-10-31');
$c=Consumption::boundary($child,'2026-02-01');
$d=Consumption::boundary($child,'2026-10-31');
check_period($a!==null && $a['method']==='interpolated','1. únor se lineárně interpoluje');
check_period($b!==null && $b['method']==='projected','31. říjen po 12. říjnu je viditelný odhad');
check_period($c!==null && $d!==null && $c['method']==='interpolated' && $d['method']==='projected','podružný elektroměr se odhaduje samostatně');
check_period(Consumption::boundary($main,'2026-12-31')===null,'příliš vzdálené datum se neodhaduje');
check_period(Consumption::boundary($main,'2026-01-01')['method']==='actual','existující odečet je vždy skutečný');

$meters=[
    ['id'=>1,'parent_id'=>null,'building_id'=>1,'unit_id'=>10,'type'=>'electricity','name'=>'Hlavní'],
    ['id'=>2,'parent_id'=>1,'building_id'=>1,'unit_id'=>20,'type'=>'electricity','name'=>'Podružný'],
];
$r=Consumption::calculate($meters,[
    1=>['start'=>$a['value'],'end'=>$b['value']],
    2=>['start'=>$c['value'],'end'=>$d['value']],
], [1=>1,2=>1.02], [1=>true,2=>true]);
check_period(isset($r['allocations'][10]['electricity'],$r['allocations'][20]['electricity']),
    'za období bez přesných odečtů lze spočítat oba byty');
check_period(!empty($r['estimated'][10]['electricity']) && !empty($r['estimated'][20]['electricity']),
    'oba výsledky jsou správně označené jako odhad');
check_period(approx_period($r['allocations'][10]['electricity']+$r['allocations'][20]['electricity'],
    ($b['value']-$a['value'])), 'rozdělení na byty se rovná hlavnímu měřidlu');

// Nulová spotřeba, nepřítomnost odečtů a nerealistická záporná extrapolace.
$constant=[['reading_date'=>'2026-09-01','value'=>100],['reading_date'=>'2026-10-01','value'=>100]];
check_period(Consumption::boundary($constant,'2026-10-15')['value']===100.0,'konstantní číselník je v pořádku');
check_period(Consumption::boundary([],'2026-05-01')===null,'bez odečtů žádný odhad');
check_period(Consumption::boundary([['reading_date'=>'2026-10-01','value'=>4]],'2026-10-10')===null,
    'samotný jediný odečet nestačí k projekci');
$small=[['reading_date'=>'2026-01-01','value'=>5],['reading_date'=>'2026-01-11','value'=>15]];
check_period(Consumption::boundary($small,'2025-12-15')===null,'nepovolí zápornou odhadnutou hodnotu číselníku');

// Počítání kalendářních dnů musí být nezávislé na letním čase.
$dst=[['reading_date'=>'2026-03-28','value'=>10],['reading_date'=>'2026-03-30','value'=>30]];
check_period(approx_period(Consumption::boundary($dst,'2026-03-29')['value'],20),
    'interpolace přes přechod na letní čas je ve dnech');
