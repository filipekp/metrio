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

if($action==='meter_update') {
    admin_only($u);
    $id=(int)($_POST['meter_id']??0);
    $m=one('SELECT * FROM meters WHERE id=?',[$id]);
    if(!$m) throw new RuntimeException('Měřidlo neexistuje.');
    $building=(int)($_POST['building_id']??0);$unit=(int)($_POST['unit_id']??0);$parent=(int)($_POST['parent_id']??0);
    $type=(string)($_POST['type']??'');$name=trim((string)($_POST['name']??''));
    $serial=trim((string)($_POST['serial']??''));$location=trim((string)($_POST['location']??''));
    if($name==='' || strlen($name)>160 || strlen($serial)>160 || strlen($location)>255)
        throw new RuntimeException('Neplatný název nebo příliš dlouhé údaje měřidla.');
    if(!isset(energy_labels()[$type]) || !one('SELECT id FROM buildings WHERE id=?',[$building]))
        throw new RuntimeException('Neplatný druh energie nebo objekt.');
    if($unit && !one('SELECT id FROM units WHERE id=? AND building_id=?',[$unit,$building]))
        throw new RuntimeException('Jednotka musí patřit do zvoleného objektu.');
    if($parent===$id) throw new RuntimeException('Měřidlo nemůže být nadřazené samo sobě.');
    if($parent) {
        $p=one('SELECT id,parent_id FROM meters WHERE id=? AND building_id=? AND type=?',[$parent,$building,$type]);
        if(!$p) throw new RuntimeException('Nadřazené měřidlo musí být stejného objektu a energie.');
        $visited=[];
        while($p) {
            if((int)$p['id']===$id || isset($visited[$p['id']])) throw new RuntimeException('Nepovolený cyklus v hierarchii měřidel.');
            $visited[$p['id']]=true;
            $p=$p['parent_id']?one('SELECT id,parent_id FROM meters WHERE id=?',[(int)$p['parent_id']]):null;
        }
    }
    if(one('SELECT id FROM meters WHERE parent_id=? AND (building_id<>? OR type<>?) LIMIT 1',[$id,$building,$type]))
        throw new RuntimeException('Změna objektu nebo energie by narušila podřízená měřidla.');
    $structural=$building!==(int)$m['building_id'] || $type!==$m['type'] || $unit!==(int)$m['unit_id'] || $parent!==(int)$m['parent_id'];
    $hasReadings=(bool)one('SELECT 1 FROM meter_readings WHERE meter_id=? LIMIT 1',[$id]);
    $hasReplacement=(bool)one('SELECT 1 FROM meter_devices WHERE meter_id=? AND removed_on IS NOT NULL LIMIT 1',[$id]);
    if($structural && ($hasReadings || $hasReplacement))
        throw new RuntimeException('U měřidla s historií nelze zpětně změnit objekt, energii, byt ani hierarchii: přepočítalo by to historické spotřeby. Pro změnu rozvodů založte nové měřicí místo.');
    if($serial!==(string)$m['serial'] && ($hasReadings || $hasReplacement))
        throw new RuntimeException('Nové výrobní číslo fyzického přístroje evidujte funkcí Výměna měřidla.');
    db()->beginTransaction();
    try {
        sql('UPDATE meters SET building_id=?,unit_id=?,parent_id=?,type=?,name=?,serial=?,location=? WHERE id=?',
            [$building,$unit?:null,$parent?:null,$type,$name,$serial,$location,$id]);
        if($serial!==(string)$m['serial'])
            sql('UPDATE meter_devices SET serial=? WHERE meter_id=? AND removed_on IS NULL',[$serial,$id]);
        db()->commit();
    } catch(Throwable $e) {db()->rollBack();throw $e;}
    audit((int)$u['id'],'meter_update','měřidlo='.$id);redirect('meters','Údaje měřidla uloženy.');
}

if($action==='meter_replace') {
    admin_only($u);
    $id=(int)($_POST['meter_id']??0);
    Energy\MeterRegistry::replace(db(),$id,(string)($_POST['replacement_date']??''),
        (string)($_POST['old_final']??''),(string)($_POST['new_initial']??''),
        (string)($_POST['new_serial']??''),(string)($_POST['note']??''));
    audit((int)$u['id'],'meter_replace','měřicí místo='.$id.' datum='.(string)$_POST['replacement_date']);
    redirect('meters','Výměna zařízení uložena. Staré odečty zůstávají zachované.');
}
