<?php
declare(strict_types=1);

// Šablona stránky buildings. Zobrazuje data připravená controllerem.
echo '<section class="card"><h2>Nový objekt</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_create"><label>Název <input name="name" required></label><label>Adresa <input name="address"></label><button>Přidat objekt</button></form></section>';
echo '<section class="card"><h2>Seznam objektů</h2><table><tr><th>Název</th><th>Adresa</th></tr>';
foreach($buildings as $b) echo '<tr><td>'.html($b['name']).'</td><td>'.html($b['address']).'</td></tr>';
echo '</table></section>';
