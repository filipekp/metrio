<?php
declare(strict_types=1);
/** Regrese: aplikace má jediné společné menu, záhlaví a patičku. Test bez DB. */
require dirname(__DIR__) . '/src/bootstrap.php';

function layout_assert($ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function layout_html(string $route, ?array $user): string {
    $_GET['page'] = $route;
    ob_start();
    page_header('Test stránka', $user);
    echo '<section id="module-content">Obsah modulu</section>';
    page_footer();
    return (string)ob_get_clean();
}
$admin = ['id'=>1,'name'=>'Admin <test>','role'=>'admin','is_active'=>1];
$user = ['id'=>2,'name'=>'Test uživatel','role'=>'user','is_active'=>1];
$manager = ['id'=>3,'name'=>'Správce','role'=>'manager','is_active'=>1];

foreach ([$admin,$user,$manager] as $actor) {
    $html = layout_html('dashboard', $actor);
    foreach (['<html ', '<head>', '<body>', '<main ', '<header ', '<aside ', '<footer ', 'id="module-content"', '</html>'] as $tag) {
        layout_assert(substr_count($html,$tag) === 1, 'Chybí nebo je duplicitní '.$tag);
    }
    layout_assert(strpos($html,'?page=dashboard') !== false, 'Chybí menu');
    layout_assert(strpos($html,'aria-current="page"') !== false, 'Chybí označení aktivní položky');
    layout_assert(strpos($html,'Admin <test>') === false, 'Neescapovaný vstup v layoutu');
    layout_assert(strpos($html,'name="_csrf"') !== false, 'Chybí CSRF v odhlašovacím formuláři');
    $isAdmin = $actor['role'] === 'admin';
    foreach (['?page=users','?page=buildings','?page=update'] as $adminLink) {
        layout_assert((strpos($html,$adminLink) !== false) === $isAdmin, 'Chybná navigace: '.$adminLink);
    }
}
$photo = layout_html('photo_review',$user);
layout_assert(strpos($photo,'href="?page=readings"') !== false, 'Chybí navigace k odečtům');
$auth = layout_html('login',null);
layout_assert(strpos($auth,'id="app-sidebar"') === false, 'Přihlášení nesmí mít hlavní menu');
layout_assert(strpos($auth,'class="auth-shell"') !== false, 'Chybí přihlašovací layout');
layout_assert(strpos($auth,'<footer ') === false, 'Přihlášení nemá dashboard patičku');
layout_assert(substr_count($auth,'<main ')===1 && substr_count($auth,'</html>')===1, 'Vadný auth dokument');

echo "Společný layout a navigace OK\n";
