<?php
declare(strict_types=1);

// Zpracování akcí modulu Buildings. Oprávnění jsou kontrolována uvnitř akcí.
if($action==='building_create') {
    admin_only($u);$name=trim((string)($_POST['name']??''));if($name==='') throw new RuntimeException('Vyplňte název objektu.');
    sql('INSERT INTO buildings(name,address) VALUES(?,?)',[$name,trim((string)($_POST['address']??''))]);
    audit((int)$u['id'],'building_create',$name);redirect('buildings','Objekt přidán.');
}
