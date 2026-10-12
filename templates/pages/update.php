<?php
declare(strict_types=1);

// Šablona stránky update. Zobrazuje data připravená controllerem.
echo '<section class="card"><p>Lokální verze: <strong>'.html(trim((string)@file_get_contents(APP_ROOT.'/VERSION'))).'</strong></p>';
if ($updateError === null) {
    echo '<p>Poslední GitHub Release: <strong>'.html($release['tag']).'</strong></p><p>'.($updateAvailable?'Je dostupná nová verze.':'Aktuální verze je již nainstalována.').'</p><details><summary>Release notes</summary><pre class="notes">'.html($release['notes']).'</pre></details>';
    if($updateAvailable) echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="deploy"><p class="muted">Před aktualizací zazálohujte databázi MariaDB. Skript uloží zálohu měněných PHP souborů, ověří SHA-256 a spustí migrace.</p><button>Nasadit '.html($release['tag']).'</button></form>';
} else { echo '<p class="flash">'.html($updateError).'</p>'; }
echo '</section>';
