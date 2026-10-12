<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$users=all('SELECT id,name,email,role,is_active FROM users ORDER BY name');
$units=unit_scope($u);
$roleOptions=['user'=>'Uživatel','manager'=>'Správce objektu','admin'=>'Administrátor'];
$opts=[];foreach($users as $x) $opts[$x['id']]=$x['name'].' ('.$x['email'].')';
$buildings=all('SELECT id,name FROM buildings ORDER BY name');$buildingOptions=[];
foreach($buildings as $b) $buildingOptions[$b['id']]=$b['name'];
$managers=array_values(array_filter($users,static function ($x) { return $x['role'] === 'manager'; }));
$managerOptions=[];foreach($managers as $x) $managerOptions[$x['id']]=$x['name'].' ('.$x['email'].')';
$managed=all('SELECT bm.building_id,bm.user_id,u.name AS uname,b.name AS bname FROM building_managers bm JOIN users u ON u.id=bm.user_id JOIN buildings b ON b.id=bm.building_id ORDER BY b.name,u.name');
$unitAccess=[];
foreach(all('SELECT a.user_id,u.id,u.name,b.name AS building_name FROM unit_users a JOIN units u ON u.id=a.unit_id JOIN buildings b ON b.id=u.building_id ORDER BY b.name,u.name') as $access)
    $unitAccess[(int)$access['user_id']][]=$access;
return compact('users','units','roleOptions','opts','buildingOptions','managerOptions','managed','unitAccess');
