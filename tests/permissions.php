<?php
/** Statické kontrakty autorizace po oddělení modulů. */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);
$scopes=file_get_contents($root.'/src/Repositories/AccessScopes.php');
$bootstrap=file_get_contents($root.'/src/bootstrap.php');
$users=file_get_contents($root.'/src/Modules/Users/actions.php');
$routes=file_get_contents($root.'/src/Http/Router.php');
$templates=file_get_contents($root.'/templates/pages/users.php');
$checks=[
    'manager building scope'=>strpos($scopes,'JOIN building_managers bm ON bm.building_id=m.building_id WHERE bm.user_id=?')!==false,
    'user unit scope'=>strpos($scopes,'JOIN unit_users au ON au.unit_id=m.unit_id WHERE au.user_id=?')!==false,
    'central meter is not blocked by children'=>strpos($bootstrap,"return !one('SELECT 1 FROM meters WHERE parent_id=?")===false,
    'manager role only'=>strpos($bootstrap,"$"."user['role'] === 'manager'")!==false,
    'remove unit access'=>strpos($users,"unit_user_remove")!==false && strpos($templates,'value="unit_user_remove"')!==false,
    'remove manager access'=>strpos($users,"building_manager_remove")!==false && strpos($templates,'value="building_manager_remove"')!==false,
    'admin routes protected'=>strpos($routes,'if ($route[2]) admin_only($u);')!==false,
];
foreach($checks as $name=>$ok) {
    echo ($ok?'OK':'FAIL').' '.$name.PHP_EOL;
    if(!$ok) exit(1);
}
