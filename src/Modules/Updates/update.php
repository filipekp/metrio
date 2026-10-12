<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
$release=null;$updateAvailable=false;$updateError=null;
try {
    $upd=new Energy\Updater(APP_ROOT);
    $release=$upd->latest();
    $updateAvailable=$upd->isNewer($release);
} catch(Throwable $e) { $updateError=$e->getMessage(); }
return compact('release','updateAvailable','updateError');
