#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $updater=new Energy\Updater(APP_ROOT);
    if(in_array('--check',$argv,true)) {
        $r=$updater->latest();echo 'Nainstalováno: '.$updater->installed().', GitHub: '.$r['tag'].', novější: '.($updater->isNewer($r)?'ano':'ne').PHP_EOL;
    } elseif(in_array('--install',$argv,true) && in_array('--yes',$argv,true)) {
        $r=$updater->deploy();echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    } else { echo "Použití: php bin/deploy.php --check | --install --yes\n";exit(64); }
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
