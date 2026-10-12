<?php
declare(strict_types=1);

$from=valid_day((string)($_GET['from']??''));$to=valid_day((string)($_GET['to']??''));
if($from>=$to) exit('Datum od musí být menší než datum do.');
$result=Energy\Consumption::fromDatabase(db(),$from,$to);$units=unit_scope($u);$labels=energy_labels();
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="spotreby.csv"');
echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['Období od','Období do','Objekt','Jednotka','Energie','Spotřeba','Jednotka měření','Původ výsledku'],';');
foreach($units as $unit) foreach(($result['allocations'][(int)$unit['id']]??[]) as $type=>$v)
    fputcsv($out,[$from,$to,csv_cell((string)$unit['building_name']),csv_cell((string)$unit['name']),csv_cell((string)($labels[$type]??$type)),str_replace('.',',',(string)$v),in_array($type,['electricity'])?'kWh':'m³',!empty($result['estimated'][(int)$unit['id']][$type])?'Odhad':'Skutečné hranice odečtů'],';');
fclose($out);exit;
