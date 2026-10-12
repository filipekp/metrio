<?php
declare(strict_types=1);

// Zpracování akcí modulu Updates. Oprávnění jsou kontrolována uvnitř akcí.
if($action==='deploy') {
    admin_only($u);
    $result=(new Energy\Updater(APP_ROOT))->deploy();
    audit((int)$u['id'],'deploy',(string)($result['tag']??'unknown'));
    redirect('update',$result['status']==='updated'?'Aktualizováno na '.$result['tag'].'.':'Je již nainstalována aktuální verze.');
}
