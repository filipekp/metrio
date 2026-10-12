<?php
declare(strict_types=1);

// Zpracování akcí modulu Units. Oprávnění jsou kontrolována uvnitř akcí.
if($action==='unit_create') {
    admin_only($u);$b=(int)($_POST['building_id']??0);if(!one('SELECT id FROM buildings WHERE id=?',[$b])) throw new RuntimeException('Objekt neexistuje.');
    $name=trim((string)($_POST['name']??''));if($name==='') throw new RuntimeException('Zadejte název jednotky.');
    $kind=(string)($_POST['kind']??'apartment');$mode=(string)($_POST['wastewater_mode']??'none');
    if(!in_array($kind,['apartment','common'],true) || !in_array($mode,['none','sum_water','metered'],true)) throw new RuntimeException('Neplatný režim jednotky.');
    sql('INSERT INTO units(building_id,name,kind,wastewater_mode) VALUES(?,?,?,?)',[$b,$name,$kind,$mode]);
    audit((int)$u['id'],'unit_create',$name);redirect('units','Jednotka přidána.');
}

if($action==='unit_mode') {
    admin_only($u);$mode=(string)($_POST['wastewater_mode']??'none');
    if(!in_array($mode,['none','sum_water','metered'],true)) throw new RuntimeException('Neplatný režim.');
    sql('UPDATE units SET wastewater_mode=? WHERE id=?',[$mode,(int)$_POST['unit_id']]);
    audit((int)$u['id'],'unit_mode',(string)$_POST['unit_id'].':'.$mode);redirect('units','Režim změněn.');
}
