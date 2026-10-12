<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Energy\Consumption;
use Energy\MeterRegistry;

function device_test(bool $test,string $name): void {
    if(!$test) throw new RuntimeException('FAIL: '.$name);
    echo 'OK: '.$name.PHP_EOL;
}
function should_reject(float $value,array $limits): bool {
    try { MeterRegistry::assertReadingInRange($value,$limits);return false; }
    catch(RuntimeException $e) {return true;}
}
// Přímá hodnota z obrázku uživatele: 1.1. = 100, 1.2. = 80.
device_test(should_reject(80.0,['minimum'=>100.0,'maximum'=>null]),'odmítne pokles 100 => 80 bez výměny');
device_test(should_reject(180.0,['minimum'=>100.0,'maximum'=>150.0]),'odmítne zpětný odečet nad budoucí stav');
MeterRegistry::assertReadingInRange(125.0,['minimum'=>100.0,'maximum'=>150.0]);
device_test(true,'povolí stav uvnitř známých mezí');

// Jedno místo, dva fyzické přístroje; stejný datum výměny, nezávislé číselníky.
$devices=[
    ['id'=>101,'installed_on'=>'1900-01-01','removed_on'=>'2026-02-01',
     'opening_value'=>null,'closing_value'=>'150'],
    ['id'=>102,'installed_on'=>'2026-02-01','removed_on'=>null,
     'opening_value'=>'0','closing_value'=>null],
];
$series=[
    101=>[['reading_date'=>'2026-01-01','value'=>'100']],
    102=>[['reading_date'=>'2026-03-01','value'=>'30']],
];
$overall=Consumption::deviceUsage($devices,$series,'2026-01-01','2026-03-01');
device_test($overall!==null && abs($overall['value']-80.0)<.0001,'50 kWh před výměnou + 30 kWh po výměně = 80 kWh');
device_test($overall!==null && !$overall['estimated'],'skutečné stavy na hranicích bez odhadu');
$before=Consumption::deviceUsage($devices,$series,'2026-01-01','2026-02-01');
device_test($before!==null && abs($before['value']-50)<.0001,'spotřeba před výměnou');
$after=Consumption::deviceUsage($devices,$series,'2026-02-01','2026-03-01');
device_test($after!==null && abs($after['value']-30)<.0001,'spotřeba po výměně');
$missing=Consumption::deviceUsage($devices,[101=>$series[101]],'2026-01-01','2026-03-01');
device_test($missing===null,'neúplný nový číselník nesmí vytvořit částečný součet');
$meters=[['id'=>1,'parent_id'=>null,'building_id'=>1,'unit_id'=>10,'type'=>'electricity','name'=>'Hlavní'],
         ['id'=>2,'parent_id'=>1,'building_id'=>1,'unit_id'=>20,'type'=>'electricity','name'=>'Podružný']];
$result=Consumption::calculate($meters,[1=>['start'=>0,'end'=>80],2=>['start'=>0,'end'=>20]],[1=>1,2=>1]);
device_test(abs($result['allocations'][10]['electricity']-60)<.0001 && abs($result['allocations'][20]['electricity']-20)<.0001,
    'hierarchie se po výměně nemění, zbytek zůstává správný');
