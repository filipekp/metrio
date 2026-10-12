<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$meters=meter_scope($u);
$mopts = [];
$electricMeterIds = [];
foreach ($meters as $meter) {
    if (!$meter['active']) {
        continue;
    }
    $mopts[$meter['id']] = meter_option_label($meter, $labels);
    if ($meter['type'] === 'electricity') {
        $electricMeterIds[] = (int) $meter['id'];
    }
}
$ids=array_map(static function ($m) { return (int)$m['id']; },$meters);
$recentReadings=[];
if ($ids) {
    $params=implode(',',array_fill(0,count($ids),'?'));
    $recentReadings=all('SELECT r.*,m.name FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$params.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 100',$ids);
}
return compact('meters', 'mopts', 'recentReadings', 'electricMeterIds');
