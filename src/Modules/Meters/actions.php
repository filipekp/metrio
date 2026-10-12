<?php
declare(strict_types=1);

// Zpracování akcí modulu Meters. Oprávnění jsou kontrolována uvnitř akcí.
if($action==='meter_create') {
    admin_only($u);$building=(int)($_POST['building_id']??0);$unit=(int)($_POST['unit_id']??0);$parent=(int)($_POST['parent_id']??0);
    $type=(string)($_POST['type']??'');$name=trim((string)($_POST['name']??''));
    if($name==='' || !isset(energy_labels()[$type]) || !one('SELECT 1 FROM buildings WHERE id=?',[$building])) throw new RuntimeException('Název, druh energie nebo objekt je neplatný.');
    if($unit && !one('SELECT 1 FROM units WHERE id=? AND building_id=?',[$unit,$building])) throw new RuntimeException('Jednotka musí být ve stejném objektu.');
    if($parent && !one('SELECT 1 FROM meters WHERE id=? AND building_id=? AND type=?',[$parent,$building,$type])) throw new RuntimeException('Nadřazené měřidlo musí být v témže objektu a stejného druhu.');
    $coef=valid_factor((string)($_POST['coefficient']??'1'));
    db()->beginTransaction();
    try {
        sql('INSERT INTO meters(building_id,unit_id,parent_id,type,name,serial,location) VALUES (?,?,?,?,?,?,?)',[$building,$unit?:null,$parent?:null,$type,$name,trim((string)($_POST['serial']??'')),trim((string)($_POST['location']??''))]);
        $mid=(int)db()->lastInsertId();sql('INSERT INTO meter_coefficients(meter_id,effective_from,coefficient) VALUES(?,?,?)',[$mid,'1900-01-01',$coef]);
        db()->commit();
    } catch(Throwable $e) {db()->rollBack();throw $e;}
    audit((int)$u['id'],'meter_create',$name);redirect('meters','Měřidlo přidáno.');
}

if($action==='meter_active') {
    admin_only($u);$id=(int)($_POST['meter_id']??0);$m=one('SELECT active FROM meters WHERE id=?',[$id]);
    if(!$m) throw new RuntimeException('Měřidlo neexistuje.');
    sql('UPDATE meters SET active=? WHERE id=?',[(int)!$m['active'],$id]);
    audit((int)$u['id'],'meter_active',(string)$id);redirect('meters','Stav měřidla změněn.');
}

if($action==='coefficient_add') {
    admin_only($u);$id=(int)($_POST['meter_id']??0);
    if(!one('SELECT id FROM meters WHERE id=?',[$id])) throw new RuntimeException('Měřidlo neexistuje.');
    $date=valid_day((string)($_POST['effective_from']??''));$factor=valid_factor((string)($_POST['coefficient']??''));
    sql('INSERT INTO meter_coefficients(meter_id,effective_from,coefficient) VALUES(?,?,?)',[$id,$date,$factor]);
    audit((int)$u['id'],'coefficient_add',$id.':'.$date.'='.$factor);redirect('meters','Koeficient přidán.');
}
