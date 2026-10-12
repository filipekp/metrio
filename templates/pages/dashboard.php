<?php
declare(strict_types=1);

// Šablona stránky dashboard. Zobrazuje data připravená controllerem.
echo '<div class="dashboard-welcome"><div><span class="welcome-eyebrow">VÁŠ ENERGETICKÝ PŘEHLED</span><h2>Dobrý den, '.html($u['name']).' <span class="welcome-wave" aria-hidden="true">✦</span></h2><p>Aktuální přehled vašich objektů, měřidel a spotřeby.</p></div><a class="button welcome-action" href="?page=readings">'.ui_icon('plus').' Nový odečet</a></div>';
echo '<div class="stats"><section class="card stat-card stat-primary"><span class="stat-icon">'.ui_icon('units').'</span><small>'.(is_admin($u)?'Všechny jednotky':(is_manager($u)?'Spravované jednotky':'Moje jednotky')).'</small><strong>'.(int)$n.'</strong><span class="stat-note">Byty a prostory s přístupem</span></section>';
echo '<section class="card stat-card"><span class="stat-icon stat-violet">'.ui_icon('meters').'</span><small>Dostupná měřidla</small><strong>'.count($meters).'</strong><span class="stat-note">Hlavní i podružná</span></section>';
echo '<section class="card stat-card"><span class="stat-icon stat-cyan">'.ui_icon('readings').'</span><small>Poslední odečty</small><strong>'.count($readings).'</strong><span class="stat-note">Až 6 posledních záznamů</span></section>';
echo '<section class="card stat-card"><span class="stat-icon stat-green">'.ui_icon('shield').'</span><small>Oprávnění</small><strong class="stat-role">'.html(role_label($u['role'])).'</strong><span class="stat-note">Vaše aktuální role</span></section></div>';

if ($overviewTypes) {
    echo '<section class="card dashboard-energy"><div class="section-head"><div><span class="eyebrow">AKTUÁLNÍ SPOTŘEBA</span><h2>Spotřeba za aktuální měsíc</h2><p class="muted">Součet dostupných jednotek. Neúplná data jsou označena.</p></div><a href="?page=consumption" class="text-action">Podrobné grafy '.ui_icon('arrow').'</a></div><div class="energy-tiles" data-count="'.count($overviewTypes).'">';
foreach($overviewTypes as $type=>$def) {
    $total=0.0;$found=false;$estimated=false;
    if($currentPeriod) foreach($unitIds as $unitId) {
        if(isset($currentPeriod['result']['allocations'][$unitId][$type])) {
            $total+=(float)$currentPeriod['result']['allocations'][$unitId][$type]; $found=true;
            if(!empty($currentPeriod['result']['estimated'][$unitId][$type])) $estimated=true;
        }
    }
    echo '<div class="energy-tile"><span class="energy-icon energy-'.html($type).'">'.ui_icon($def[1]).'</span><span class="energy-label">'.html($def[0]).'</span><div class="energy-reading">'.($found?html(fmt($total)):'—').' <small>'.html($def[2]).'</small></div><span class="energy-foot">'.($found?($estimated?'Odhad dle odečtů':'Dle odečtů'):'Zatím bez údajů').'</span></div>';
}
    echo '</div></section>';
}
echo '<div class="dashboard-columns"><section class="card dashboard-trend"><div class="section-head"><div><span class="eyebrow">VÝVOJ SPOTŘEBY</span><h2>Posledních šest měsíců</h2></div></div>';
if($report && $unitIds) {
    $first=$unitIds[0]; $shown=null;
    foreach (array_keys($overviewTypes) as $type) {
        foreach($report['periods'] as $period) {
            if(isset($period['result']['allocations'][$first][$type])) {$shown=$type;break 2;}
        }
    }
    if($shown!==null) {
        $firstUnitName=(string)$units[0]['name'];
        echo '<p class="muted dashboard-trend-label">'.html($firstUnitName).' · '.html($overviewTypes[$shown][0]).'</p>';
        render_consumption_chart($report['periods'],$first,$shown);
    } else echo '<div class="empty-state">'.ui_icon('chart').'<p>Pro zobrazení grafu zatím chybí odečty.</p><a href="?page=readings">Zadat první odečet</a></div>';
} else echo '<div class="empty-state">'.ui_icon('chart').'<p>Zatím nejsou dostupná data spotřeby.</p><a href="?page=readings">Zadat odečet</a></div>';
echo '</section><section class="card dashboard-activity"><div class="section-head"><div><span class="eyebrow">AKTIVITA</span><h2>Poslední odečty</h2></div><a class="text-action" href="?page=readings">Všechny '.ui_icon('arrow').'</a></div>';
if ($readings) {
    echo '<div class="recent-readings">';
    foreach($readings as $reading) {
        echo '<div class="recent-reading"><span class="reading-dot">'.ui_icon($reading['type']==='electricity'?'bolt':'water').'</span><div class="reading-detail"><strong>'.html($reading['name']).'</strong><small>'.html(date('d. m. Y',strtotime($reading['reading_date']))).' · '.html(reading_source_label($reading['source'])).'</small></div><span class="reading-number">'.html(fmt((float)$reading['value'])).'</span></div>';
    }
    echo '</div>';
} else echo '<div class="empty-state">'.ui_icon('readings').'<p>Dosud žádné odečty.</p><a href="?page=readings">Přidat odečet</a></div>';
echo '</section></div>';
echo '<section class="card dashboard-links"><div class="section-head"><div><span class="eyebrow">RYCHLÉ AKCE</span><h2>Co potřebujete udělat?</h2></div></div><div class="quick-links"><a href="?page=readings">'.ui_icon('camera').'<span><strong>Zapsat odečet</strong><small>Ručně nebo fotografií</small></span>'.ui_icon('arrow').'</a><a href="?page=consumption">'.ui_icon('chart').'<span><strong>Prohlédnout spotřeby</strong><small>Grafy a přehledy jednotek</small></span>'.ui_icon('arrow').'</a><a href="?page=meters">'.ui_icon('meters').'<span><strong>Seznam měřidel</strong><small>Všechna dostupná měřidla</small></span>'.ui_icon('arrow').'</a></div></section>';
