<?php
declare(strict_types=1);

/** Sloupcový graf vývoje spotřeby jedné jednotky a jednoho druhu energie. */
function render_consumption_chart(array $periods,int $unitId,string $type): void {
    $values=[]; $max=0.0;
    foreach ($periods as $period) {
        $value=$period['result']['allocations'][$unitId][$type]??null;
        $number=$value===null?null:(float)$value;
        $values[]=['from'=>$period['from'],'to'=>$period['to'],'value'=>$number,
            'estimated'=>!empty($period['result']['estimated'][$unitId][$type])];
        if ($number!==null) $max=max($max,$number);
    }
    $unit=$type==='electricity'?'kWh':'m³';
    if (!array_filter($values,static function ($x) { return $x['value'] !== null; })) {
        echo '<p class="muted">Pro zvolenou energii a období nejsou dostupné dostatečné odečty.</p>';
        return;
    }
    echo '<div class="consumption-chart" role="img" aria-label="Sloupcový graf vývoje spotřeby; podrobné hodnoty jsou v tabulce níže">';
    foreach ($values as $row) {
        $number=$row['value']; $estimated=$row['estimated'];
        $height=$number===null?0:($max>0?max(3,round(max(0,$number)/$max*164)):3);
        $short=substr($row['from'],5,2).'/'.substr($row['from'],2,2);
        $full=$row['from'].' – '.$row['to'];
        $caption=$number===null?'Bez údajů':fmt($number).' '.$unit.($estimated?' (odhad)':' (přesný odečet)');
        echo '<div class="chart-column"><div class="chart-value">'.($number===null?'—':html(fmt($number))).($number!==null&&$estimated?'<sup title="Odhad">*</sup>':'').'</div>';
        echo '<div class="chart-bar-area">';
        if ($number!==null) echo '<div class="chart-bar'.($estimated?' estimated':'').($number<0?' negative':'').'" style="height:'.$height.'px" title="'.html($full.' · '.$caption).'"></div>';
        echo '</div><div class="chart-label" title="'.html($full).'">'.html($short).'</div></div>';
    }
    echo '</div><p class="muted">* Odhad: hodnota mezi odečty je rozpočítaná rovnoměrně v čase; nejvýše 45 dní mimo krajní odečet je možné promítnout poslední známé tempo spotřeby. Nejde o skutečný odečet.</p>';
}
