<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
function check_74($truth,string $name): void { if (!$truth) throw new RuntimeException('FAIL: '.$name); echo 'OK: '.$name.PHP_EOL; }
check_74(html('<&>') === '&lt;&amp;&gt;','HTML escape');
check_74(valid_factor('1,020000') === '1.020000','koeficient');
check_74(energy_labels()['electricity'] === 'Elektřina (kWh)','popisky');
check_74(strpos('Energy\\Consumption','Energy\\')===0,'PHP 7.4 kompatibilní řetězce');
check_74(is_file(APP_ROOT.'/.htaccess'),'sdílený hosting má .htaccess');
check_74(is_file(APP_ROOT.'/public/install.php'),'instalátor bez CLI');
