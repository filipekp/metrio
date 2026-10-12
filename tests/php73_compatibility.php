<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
function check_73($truth,string $name): void { if (!$truth) throw new RuntimeException('FAIL: '.$name); echo 'OK: '.$name.PHP_EOL; }
check_73(html('<&>') === '&lt;&amp;&gt;','HTML escape');
check_73(valid_factor('1,020000') === '1.020000','koeficient');
check_73(energy_labels()['electricity'] === 'Elektřina (kWh)','popisky');
check_73(strpos('Energy\\Consumption','Energy\\')===0,'PHP 7.3 kompatibilní řetězce');
check_73(is_file(APP_ROOT.'/.htaccess'),'sdílený hosting má .htaccess');
check_73(is_file(APP_ROOT.'/public/install.php'),'instalátor bez CLI');

// CI používá skutečný interpreter PHP 7.3, aby zachytilo i chyby, které
// na PHP 8.x nejsou vidět (např. arrow functions a operátor ??=).
check_73(PHP_VERSION_ID >= 70300, 'PHP 7.3 nebo novější');
