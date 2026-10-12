#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $runner=new Energy\Migrations(db(),APP_ROOT.'/migrations');
    if(in_array('--status',$argv,true)) {
        foreach($runner->status() as $row) {
            $label=$row['status']==='CHANGED'
                ? 'provedena, soubor zmenen (pri aktualizaci preskocena)'
                : ($row['status']==='applied'?'provedena':'ceka');
            echo $row['name'].' : '.$label.PHP_EOL;
        }
    } elseif(count($argv)>1) { echo "Použití: php bin/migrate.php [--status]\n";exit(64); }
    else {
        $skipped=[];
        foreach($runner->status() as $row) if($row['status']==='CHANGED') $skipped[]=$row['name'];
        $run=$runner->run();foreach($run as $name) echo 'OK: '.$name.PHP_EOL;
        foreach($skipped as $name) echo 'PRESKOCENO (jiz provedena): '.$name.PHP_EOL;
        if(!$run) echo "Databáze je aktuální.\n";
    }
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
