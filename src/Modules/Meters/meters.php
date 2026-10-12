<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$meters=meter_scope($u);
$buildings=[];$uopts=[];$mopts=[];
if (is_admin($u)) {
    foreach(all('SELECT id,name FROM buildings ORDER BY name') as $b) $buildings[$b['id']]=$b['name'];
    $uopts=unit_options(unit_scope($u));
    foreach($meters as $m) $mopts[$m['id']]=meter_option_label($m,$labels);
}
$devicesByMeter=[];$activeDeviceEans=[];
if(is_admin($u)) foreach(all('SELECT * FROM meter_devices ORDER BY meter_id,installed_on DESC,id DESC') as $device) {
    $devicesByMeter[(int)$device['meter_id']][]=$device;
    if($device['removed_on']===null) $activeDeviceEans[(int)$device['meter_id']]=$device['ean'];
}
$coefficients=[];
foreach(all('SELECT mc.meter_id,mc.coefficient FROM meter_coefficients mc JOIN (SELECT meter_id,MAX(effective_from) AS last_date FROM meter_coefficients WHERE effective_from<=CURRENT_DATE GROUP BY meter_id) latest ON latest.meter_id=mc.meter_id AND latest.last_date=mc.effective_from') as $row)
    $coefficients[(int)$row['meter_id']]=$row['coefficient'];
return compact('meters','buildings','uopts','mopts','coefficients','devicesByMeter','activeDeviceEans');
