<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$units=unit_scope($u); $meters=meter_scope($u); $n=count($units);
$unitIds=array_map(static function ($x) { return (int)$x['id']; },$units);
$meterIds=array_map(static function ($x) { return (int)$x['id']; },$meters);
$readings=[];
if ($meterIds) {
    $placeholders=implode(',',array_fill(0,count($meterIds),'?'));
    $readings=all('SELECT r.reading_date,r.value,r.source,m.name,m.type FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$placeholders.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 6',$meterIds);
}
// Výpočty se provádí na serveru; do šablony se posílají pouze agregace přístupných jednotek.
$report=null;
if ($unitIds && $meterIds) {
    try {
        $from=date('Y-m-01',strtotime('first day of -5 months'));
        $to=date('Y-m-d');
        if ($from<$to) $report=Energy\Consumption::report(db(),$from,$to);
    } catch(Throwable $e) { error_log('Dashboard consumption summary: '.$e->getMessage()); }
}
$overviewTypes=['electricity'=>['Elektřina','bolt','kWh'],'gas'=>['Plyn','flame','m³'],'cold_water'=>['Studená voda','water','m³'],'hot_water'=>['Teplá voda','water','m³']];
// Omezit komodity na měřidla dostupná přihlášenému uživateli.
$linkedTypes = [];
foreach ($meters as $meter) {
    $linkedTypes[(string) $meter['type']] = true;
}
$overviewTypes = array_intersect_key($overviewTypes, $linkedTypes);
$currentPeriod=null;
if ($report) foreach($report['periods'] as $period) $currentPeriod=$period;
return compact('units','meters','n','unitIds','readings','report','overviewTypes','currentPeriod');
