<?php
declare(strict_types=1);
/** Nevyžaduje databázi; ověřuje vykreslení šablon s prázdným modelem. */
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Http/Router.php';

set_error_handler(static function (int $level, string $message): void {
    if (error_reporting() & $level) throw new ErrorException($message, 0, $level);
});

$u=['id'=>1, 'name'=>'Testovací správce', 'role'=>'admin', 'is_active'=>1];
$labels=energy_labels();
$views=[
    'login'=>[],
    'dashboard'=>['u'=>$u,'labels'=>$labels,'units'=>[],'meters'=>[],'n'=>0,'unitIds'=>[],
        'readings'=>[],'report'=>null,'overviewTypes'=>['electricity'=>['Elektřina','bolt','kWh']], 'currentPeriod'=>null],
    'buildings'=>['u'=>$u, 'buildings'=>[]],
    'units'=>['u'=>$u, 'units'=>[], 'opts'=>[]],
    'users'=>['u'=>$u,'users'=>[],'units'=>[],'opts'=>[],'buildingOptions'=>[],
        'managerOptions'=>[],'managed'=>[],'unitAccess'=>[],
        'roleOptions'=>['user'=>'Uživatel','manager'=>'Správce','admin'=>'Administrátor']],
    'meters'=>['u'=>$u,'labels'=>$labels,'meters'=>[],'buildings'=>[],'mopts'=>[],'uopts'=>[], 'coefficients'=>[]],
    'readings'=>['u'=>$u,'labels'=>$labels,'meters'=>[],'mopts'=>[],'recentReadings'=>[]],
    'photo_review'=>['u'=>$u,'labels'=>$labels,
        'p'=>['id'=>1,'meter_name'=>'Měřidlo','meter_type'=>'electricity', 'suggestion_note'=>'Nezjištěno',
            'status'=>'rejected','detected_serial'=>null,'detected_ean'=>null],
        'identity'=>['status'=>'unverified','note'=>'Ověření identifikace není dostupné'],
        'meterChoices'=>[], 'selectedMeterId'=>0,'reviewUnit'=>'kWh'],
    'consumption'=>['u'=>$u,'labels'=>$labels,'from'=>'2026-01-01','to'=>'2026-02-01',
        'selectedId'=>0,'energy'=>'all','options'=>[],'error'=>'Bez jednotky'],
    'update'=>['u'=>$u,'release'=>null,'updateAvailable'=>false,'updateError'=>'Bez spojení'],
];
foreach ($views as $name=>$data) {
    ob_start();
    try { metrio_render($name,$data); $html=(string)ob_get_clean(); }
    catch (Throwable $e) { ob_end_clean(); throw new RuntimeException('Šablona '.$name.': '.$e->getMessage(),0,$e); }
    if (strlen($html) < 20) throw new RuntimeException('Prázdná šablona: '.$name);
}
restore_error_handler();
echo 'OK: '.count($views)." šablon bez databáze\n";
