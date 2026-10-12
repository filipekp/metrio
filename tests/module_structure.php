<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$pages=['login','dashboard','buildings','units','users','meters','readings','photo_review','consumption','update'];
foreach ($pages as $page) {
    if(!is_file($root.'/templates/pages/'.$page.'.php')) throw new RuntimeException('Chybí šablona: '.$page);
}
foreach (['Http/Router.php','Http/PostHandler.php','Repositories/AccessScopes.php','Services/ReadingService.php','View/ConsumptionChart.php'] as $file) {
    if(!is_file($root.'/src/'.$file)) throw new RuntimeException('Chybí modul: '.$file);
}
require $root.'/src/bootstrap.php';
require $root.'/src/Http/Router.php';
$routes=metrio_routes();
foreach ($pages as $page) {
    if(!isset($routes[$page])) throw new RuntimeException('Neznámá route: '.$page);
    if($page!=='login' && !is_file($root.'/src/Modules/'.$routes[$page][0].'/'.$page.'.php')) throw new RuntimeException('Chybí controller: '.$page);
}
foreach (['users','buildings','update'] as $page) if(!$routes[$page][2]) throw new RuntimeException('Není chráněna administrace: '.$page);
foreach (['dashboard','units','meters','readings','consumption','photo_review'] as $page) if($routes[$page][2]) throw new RuntimeException('Běžná stránka chybně omezena na admina: '.$page);
echo "Struktura modulů a rout OK\n";
