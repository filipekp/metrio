<?php
declare(strict_types=1);

function unit_scope(array $user): array {
    if(is_admin($user)) return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id ORDER BY b.name,u.name');
    if(is_manager($user)) return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id JOIN building_managers bm ON bm.building_id=b.id WHERE bm.user_id=? ORDER BY b.name,u.name',[$user['id']]);
    return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id JOIN unit_users au ON au.unit_id=u.id WHERE au.user_id=? ORDER BY b.name,u.name',[$user['id']]);
}
function meter_scope(array $user,bool $readable=false): array {
    $base='SELECT m.*,b.name AS building_name,u.name AS unit_name,p.name AS parent_name FROM meters m JOIN buildings b ON b.id=m.building_id LEFT JOIN units u ON u.id=m.unit_id LEFT JOIN meters p ON p.id=m.parent_id';
    $order=' ORDER BY b.name,m.type,m.parent_id,m.name';
    if(is_admin($user)) return all($base.$order);
    if(is_manager($user)) return all($base.' JOIN building_managers bm ON bm.building_id=m.building_id WHERE bm.user_id=?'.$order,[(int)$user['id']]);
    // SQL omezuje výsledek na přístupné jednotky (ne načíst všechna cizí měřidla a filtrovat v PHP).
    return all($base.' JOIN unit_users au ON au.unit_id=m.unit_id WHERE au.user_id=?'.$order,[(int)$user['id']]);
}

/** Shodný popisek ve všech výběrech (objekt / energie / měřidlo). */
function meter_option_label(array $meter,array $energyLabels): string {
    $text=(string)$meter['building_name'].' / '.($energyLabels[$meter['type']]??$meter['type']).' / '.(string)$meter['name'];
    if(!empty($meter['unit_name'])) $text.=' — jednotka: '.$meter['unit_name'];
    return $text;
}
