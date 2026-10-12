<?php
declare(strict_types=1);

$p=photo_for((int)($_GET['id']??0),$u);
$path=APP_ROOT.'/storage/photos/'.$p['storage_name'];
if(!is_file($path)) {http_response_code(404);exit('Fotografie nenalezena');}
header('Content-Type: '.$p['mime']);header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
readfile($path);exit;
