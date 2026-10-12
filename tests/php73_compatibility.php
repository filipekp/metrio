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

// Layout je sdílený na všech stránkách a stále řídí viditelnost položek podle role.
ob_start();
page_header('Přehled', ['id'=>7,'name'=>'Běžný uživatel','role'=>'user','is_active'=>1]);
page_footer();
$userLayout=(string)ob_get_clean();
check_73(strpos($userLayout,'class="app-sidebar"')!==false, 'moderní boční panel');
check_73(strpos($userLayout,'?page=consumption')!==false, 'uživatel vidí spotřeby');
check_73(strpos($userLayout,'?page=users')===false, 'uživatel nevidí správu uživatelů');
check_73(strpos($userLayout,'?page=update')===false, 'uživatel nevidí aktualizace');
ob_start();
page_header('Přehled', ['id'=>1,'name'=>'Administrátor','role'=>'admin','is_active'=>1]);
page_footer();
$adminLayout=(string)ob_get_clean();
check_73(strpos($adminLayout,'?page=users')!==false, 'administrátor vidí uživatele');
check_73(strpos($adminLayout,'?page=update')!==false, 'administrátor vidí aktualizace');
check_73(is_file(APP_ROOT.'/public/assets/vendor/bootstrap.min.css'), 'lokální Bootstrap bez CDN');
check_73(is_file(APP_ROOT.'/public/assets/app.js'), 'mobilní navigace');
