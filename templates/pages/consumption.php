<?php
declare(strict_types=1);

// Šablona stránky consumption. Zobrazuje data připravená controllerem.
echo '<section class="card"><h2>Vybrat období a jednotku</h2><form method="get" class="inline"><input type="hidden" name="page" value="consumption"><label>Období od <input type="date" name="from" value="'.html($from).'" required></label><label>Období do <input type="date" name="to" value="'.html($to).'" required></label>';
if ($options) echo '<label>Jednotka '.form_select('unit_id',$options,$selectedId).'</label>';
echo '<label>Graf energie '.form_select('energy',['all'=>'Všechny energie']+$labels,$energy).'</label><button>Zobrazit spotřebu</button></form>';
echo '<p class="muted">Datum nemusí odpovídat dni odečtu. V takovém případě systém použije poměrný odhad podle sousedních odečtů. Pokud chybí podklady, výsledek nevypočítá.</p></section>';
if ($error === null) {
    echo '<section class="card"><div class="section-head"><h2>Celková spotřeba: '.html($unitName).'</h2><a class="button secondary" href="?page=export&amp;from='.html(rawurlencode($from)).'&amp;to='.html(rawurlencode($to)).'">Export CSV všech dostupných jednotek</a></div>';
    echo '<table><tr><th>Energie</th><th>Spotřeba za období</th><th>Výpočet</th></tr>';
    $any=false;
    foreach (($result['allocations'][$selectedId]??[]) as $type=>$value) {
        $any=true;
        $estimated=!empty($result['estimated'][$selectedId][$type]);
        echo '<tr><td>'.html($labels[$type]??$type).'</td><td><strong>'.html(fmt((float)$value)).'</strong> '.($type==='electricity'?'kWh':'m³').'</td><td>'.($estimated?'<span class="estimate-pill">Odhad</span>':'<span class="actual-pill">Podle odečtů</span>').'</td></tr>';
    }
    if (!$any) echo '<tr><td colspan="3">K tomuto období není možné vypočítat spotřebu. Zkontrolujte dostupné odečty.</td></tr>';
    echo '</table></section>';

    if(!$shownEnergies) echo '<section class="card"><p class="muted">Zatím není možné vykreslit žádný graf spotřeby. Zkontrolujte odečty.</p></section>';
    foreach($shownEnergies as $shownType) {
        echo '<section class="card"><h2>Graf vývoje – '.html($labels[$shownType]??$shownType).'</h2>';
        render_consumption_chart($report['periods'],$selectedId,$shownType);
        echo '</section>';
    }
    echo '<section class="card"><h2>Vývoj po obdobích – '.html($unitName).'</h2><table><tr><th>Období od</th><th>Do</th><th>Energie</th><th>Spotřeba</th><th>Výpočet</th></tr>';
    $rows=0;
    foreach ($report['periods'] as $period) foreach(($period['result']['allocations'][$selectedId]??[]) as $type=>$value) {
        $rows++;
        $estimated=!empty($period['result']['estimated'][$selectedId][$type]);
        echo '<tr><td>'.html($period['from']).'</td><td>'.html($period['to']).'</td><td>'.html($labels[$type]??$type).'</td><td>'.html(fmt((float)$value)).' '.($type==='electricity'?'kWh':'m³').'</td><td>'.($estimated?'Odhad':'Podle odečtů').'</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5">Žádné vypočitatelné období.</td></tr>';
    echo '</table></section>';
    if(is_admin($u) && $result['warnings']) {
        echo '<section class="card warning"><h2>Upozornění při výpočtu</h2><ul>';
        foreach (array_unique($result['warnings']) as $warning) echo '<li>'.html($warning).'</li>';
        echo '</ul></section>';
    }
    if(!is_admin($u) && !is_manager($u)) echo '<p class="muted">Zobrazeny jsou pouze spotřeby vašich jednotek. Odečty cizích měřidel nejsou zpřístupněny.</p>';
    if(is_manager($u)) echo '<p class="muted">Zobrazeny jsou spotřeby spravovaných objektů. Měřidla a koeficienty nastavuje administrátor.</p>';
} else { echo '<p class="flash">'.html($error).'</p>'; }
