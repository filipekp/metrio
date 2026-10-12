<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$units=unit_scope($u);
$opts=[];
if (is_admin($u)) foreach (all('SELECT id,name FROM buildings ORDER BY name') as $b) $opts[$b['id']]=$b['name'];
return compact('units','opts');
