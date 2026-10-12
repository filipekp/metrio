<?php
declare(strict_types=1);

// Šablona stránky users. Zobrazuje data připravená controllerem.

echo '<section class="card"><h2>Nový uživatel</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_create"><label>Jméno <input name="name" required></label><label>E-mail <input type="email" name="email" required></label><label>Heslo (12+ znaků) <input type="password" name="password" minlength="12" required></label><label>Role '.form_select('role',$roleOptions).'</label><button>Vytvořit</button></form></section>';
echo '<section class="card"><h2>Přiřadit přístup k jednotce</h2><p class="muted">Uživatel uvidí měřidla přidělená této jednotce, včetně hlavního měřidla s podružnými měřidly. Odečty cizích měřidel neuvidí.</p><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_user"><label>Uživatel ';

echo form_select('user_id',$opts).'</label><label>Jednotka '.form_select('unit_id',unit_options($units)).'</label><button>Přiřadit</button></form></section>';


echo '<section class="card"><h2>Správci objektů</h2><p class="muted">Správce vidí všechna měřidla a odečty přiřazených objektů a může zadávat ruční i fotografické odečty. Nemá právo měnit uživatele, objekty, nastavení měřidel ani koeficienty.</p>';
if($managerOptions && $buildingOptions) {
    echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_manager_add"><label>Správce '.form_select('user_id',$managerOptions).'</label><label>Objekt '.form_select('building_id',$buildingOptions).'</label><button>Přiřadit objekt</button></form>';
} else echo '<p class="muted">Nejdříve založte (nebo upravte) uživatele s rolí Správce objektu a vytvořte objekt.</p>';

echo '<table><tr><th>Správce</th><th>Objekt</th><th>Akce</th></tr>';
foreach($managed as $access) {
    echo '<tr><td>'.html($access['uname']).'</td><td>'.html($access['bname']).'</td><td><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_manager_remove"><input type="hidden" name="user_id" value="'.(int)$access['user_id'].'"><input type="hidden" name="building_id" value="'.(int)$access['building_id'].'"><button class="small secondary" onclick="return confirm(&quot;Odebrat správci přístup k objektu?&quot;)">Odebrat přístup</button></form></td></tr>';
}
echo '</table></section>';

echo '<section class="card"><h2>Seznam uživatelů a jejich jednotek</h2><p class="muted">Přístup k jednotlivé jednotce odeberete přímo u uživatele tlačítkem Odebrat.</p><table><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Přístupy k jednotkám</th><th>Stav</th></tr>';
foreach($users as $x) {
    $owned=$unitAccess[(int)$x['id']]??[];
    echo '<tr><td>'.html($x['name']).'</td><td>'.html($x['email']).'</td><td>'.html(role_label($x['role'])).'</td><td>';
    if(!$owned) echo '<span class="muted">Bez jednotky</span>';
    foreach($owned as $unit) {
        echo '<form method="post" class="inline access-entry">'.post_token().'<input type="hidden" name="action" value="unit_user_remove"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><input type="hidden" name="unit_id" value="'.(int)$unit['id'].'"><span>'.html($unit['building_name'].' / '.$unit['name']).'</span><button class="small secondary" onclick="return confirm(&quot;Odebrat přístup k jednotce?&quot;)">Odebrat</button></form>';
    }
    echo '</td><td><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_toggle"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><button class="small secondary">'.($x['is_active']?'Aktivní – deaktivovat':'Neaktivní – aktivovat').'</button></form></td></tr>';
}
echo '</table></section><section class="card"><h2>Upravit uživatele</h2><p class="muted">Heslo nechte prázdné, pokud jej nechcete měnit. Při odebrání role správce se odstraní také jeho přiřazení k objektům.</p><div class="user-edit-grid">';
foreach($users as $x) {
    echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_update"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><label>Jméno <input name="name" value="'.html($x['name']).'" required></label><label>Role '.form_select('role',$roleOptions,$x['role']).'</label><label>Nové heslo <input name="password" type="password" minlength="12" autocomplete="new-password"></label><button>Upravit '.html($x['email']).'</button></form><hr>';
}
echo '</div></section>';
