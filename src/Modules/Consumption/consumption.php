<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$from=(string)($_GET['from']??date('Y-01-01'));
$to=(string)($_GET['to']??date('Y-m-d'));
$units=unit_scope($u);
$availableUnits=[];
foreach ($units as $unit) $availableUnits[(int)$unit['id']]=$unit;
$selectedId=(int)($_GET['unit_id']??(array_key_first($availableUnits)??0));
$energy=(string)($_GET['energy']??'all');
$options=[];
foreach ($units as $unit) $options[$unit['id']]=$unit['building_name'].' / '.$unit['name'];
$error=null;$report=null;$result=[];$shownEnergies=[];$unitName='';
try {
    valid_day($from);valid_day($to);
    if($from>=$to) throw new RuntimeException('Datum konce musí být pozdější než počátek.');
    if($selectedId===0 || !isset($availableUnits[$selectedId])) throw new RuntimeException('Vyberte jednotku, ke které máte přístup.');
    if($energy!=='all' && !array_key_exists($energy,$labels)) throw new RuntimeException('Neplatný druh energie.');
    $report=Energy\Consumption::report(db(),$from,$to);
    $result=$report['overall'];
    $unit=$availableUnits[$selectedId];
    $unitName=$unit['building_name'].' / '.$unit['name'];
    $shownEnergies=$energy==='all'?array_keys($result['allocations'][$selectedId]??[]):[$energy];
} catch(Throwable $e) { $error=$e->getMessage(); }
return compact('from','to','units','availableUnits','selectedId','energy','options','error','report','result','unitName','shownEnergies');
