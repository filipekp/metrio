<?php
declare(strict_types=1);

function save_reading(array $user,int $meterId,string $date,string $raw, string $source='manual',?int $photo=null): void {
    $meter=meter_for_reading($meterId,$user);
    if(!(int)$meter['active']) throw new RuntimeException('Měřidlo je neaktivní.');
    $day=valid_day($date);$value=valid_number($raw);
    $device=Energy\MeterRegistry::deviceOn(db(),$meterId,$day);
    if(!$device) throw new RuntimeException('K datu odečtu není evidováno žádné instalované zařízení.');
    if($day===(string)$device['installed_on'] && $device['opening_value']!==null)
        throw new RuntimeException('Počáteční stav nového zařízení již eviduje protokol výměny. Další odečet zadejte až následující den.');
    if(one('SELECT id FROM meter_readings WHERE device_id=? AND reading_date=?',[(int)$device['id'],$day]))
        throw new RuntimeException('Pro tento den již existuje odečet vybraného zařízení.');
    $limits=Energy\MeterRegistry::bounds(db(),$device,$day);
    Energy\MeterRegistry::assertReadingInRange((float)$value,$limits);
    sql('INSERT INTO meter_readings(meter_id,device_id,reading_date,value,source,photo_id,created_by) VALUES(?,?,?,?,?,?,?)',
        [$meterId,(int)$device['id'],$day,$value,$source,$photo,$user['id']]);
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
