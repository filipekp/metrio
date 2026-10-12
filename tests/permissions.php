<?php
/** Offline ověření základních pravidel přístupu. php tests/permissions.php */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

function contains_74(string $haystack,string $needle): bool { return strpos($haystack,$needle)!==false; }
// Testy zde provádí statickou kontrolu souborů. Integrační SQL testy vyžadují MariaDB.
$bootstrap=file_get_contents(__DIR__.'/../src/bootstrap.php');
$web=file_get_contents(__DIR__.'/../public/index.php');
$checks=[
 'manager building scope'=>contains_74($web,'JOIN building_managers bm ON bm.building_id=m.building_id WHERE bm.user_id=?'),
 'user unit scope'=>contains_74($web,'JOIN unit_users au ON au.unit_id=m.unit_id WHERE au.user_id=?'),
 'central meter is not blocked by children'=>!contains_74($bootstrap,'return !one(\'SELECT 1 FROM meters WHERE parent_id=?'),
 'manager role only'=>contains_74($bootstrap,"$"."user['role'] === 'manager'"),
 'remove unit access'=>contains_74($web,"value=\"unit_user_remove\""),
 'remove manager access'=>contains_74($web,"value=\"building_manager_remove\""),
];
foreach($checks as $name=>$ok) {
    echo ($ok?'OK':'FAIL').' '.$name.PHP_EOL;
    if(!$ok) exit(1);
}
