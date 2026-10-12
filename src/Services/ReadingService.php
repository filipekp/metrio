<?php
declare(strict_types=1);

function save_reading(array $user,int $meterId,string $date,string $raw, string $source='manual',?int $photo=null): void {
    $meter=meter_for_reading($meterId,$user);
    if(!(int)$meter['active']) throw new RuntimeException('Měřidlo je neaktivní.');
    $day=valid_day($date);$value=valid_number($raw);
    $prev=one('SELECT value FROM meter_readings WHERE meter_id=? AND reading_date<? ORDER BY reading_date DESC LIMIT 1',[$meterId,$day]);
    $next=one('SELECT value FROM meter_readings WHERE meter_id=? AND reading_date>? ORDER BY reading_date ASC LIMIT 1',[$meterId,$day]);
    if($prev && (float)$prev['value']>(float)$value) throw new RuntimeException('Odečet je nižší než předchozí stav. Při výměně měřidla založte nové měřidlo.');
    if($next && (float)$next['value']<(float)$value) throw new RuntimeException('Odečet je vyšší než následující stav.');
    sql('INSERT INTO meter_readings(meter_id,reading_date,value,source,photo_id,created_by) VALUES(?,?,?,?,?,?)',[$meterId,$day,$value,$source,$photo,$user['id']]);
    audit((int)$user['id'],'reading_create','měřidlo='.$meterId.' datum='.$day.' stav='.$value);
}
function photo_for(int $id,array $user): array {
    $photo=one('SELECT p.*,m.name AS meter_name,m.type AS meter_type,m.unit_id,m.building_id,m.id AS meter_id FROM reading_photos p JOIN meters m ON m.id=p.meter_id WHERE p.id=?',[$id]);
    if(!$photo || !can_read_meter($user,$photo)
        || (!is_admin($user) && !is_manager($user) && (int)$photo['user_id'] !== (int)$user['id'])) {
        http_response_code(403); throw new RuntimeException('Fotografie není dostupná.');
    }
    return $photo;
}
