<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$meters=meter_scope($u);
$mopts=[];
foreach($meters as $m) if($m['active']) $mopts[$m['id']]=meter_option_label($m,$labels);
$ids=array_map(static function ($m) { return (int)$m['id']; },$meters);
$recentReadings=[];
if ($ids) {
    $params=implode(',',array_fill(0,count($ids),'?'));
    $recentReadings=all('SELECT r.*,m.name FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$params.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 100',$ids);
}
return compact('meters','mopts','recentReadings');
