<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$p=photo_for((int)($_GET['id']??0),$u);
$candidates=Energy\MeterIdentity::candidates(db(),meter_scope($u));
$identity=Energy\MeterIdentity::assess((int)($p['original_meter_id']??$p['meter_id']),
    $p['detected_serial']??null,$p['detected_ean']??null,$candidates);
$selectedMeterId=(int)($p['original_meter_id']??$p['meter_id']);
if($identity['status']==='other' && $identity['suggested_meter_id']!==null && !empty($_GET['use_suggested']))
    $selectedMeterId=(int)$identity['suggested_meter_id'];
$meterChoices=[];$reviewUnit=$labels[$p['meter_type']]??'';
foreach($candidates as $meter) {
    $meterChoices[(int)$meter['id']]=meter_option_label($meter,$labels);
    if((int)$meter['id']===$selectedMeterId) $reviewUnit=$labels[$meter['type']]??$reviewUnit;
}
$electricMeterIds = [];
foreach ($candidates as $candidate) {
    if ($candidate['type'] === 'electricity') {
        $electricMeterIds[] = (int) $candidate['id'];
    }
}
return compact('p','identity','meterChoices','selectedMeterId','reviewUnit','electricMeterIds');
