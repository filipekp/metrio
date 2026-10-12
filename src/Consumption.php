<?php
declare(strict_types=1);
namespace Energy;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Výpočty měřidel a poměrné odhady mezi známými odečty. */
final class Consumption {
    /**
     * @param array $meters Měřidla se sloupci id,parent_id,unit_id,type,building_id,name
     * @param array $readings [id=>['start'=>stav,'end'=>stav]]
     * @param array $coefficients [id=>kladný koeficient]
     * @param array $estimatedMeterIds [id=>bool] Má měřidlo alespoň jednu odhadovanou hranici?
     */
    public static function calculate(array $meters, array $readings, array $coefficients, array $estimatedMeterIds = []): array {
        $index=[]; $children=[]; $warnings=[]; $results=[]; $alloc=[]; $quality=[]; $residuals=[];
        foreach ($meters as $m) { $id=(int)$m['id']; $index[$id]=$m; $children[$id]=[]; }
        foreach ($meters as $m) {
            $id=(int)$m['id']; $parent=(int)($m['parent_id']??0);
            if ($parent) {
                if (!isset($index[$parent]) || $index[$parent]['type']!==$m['type'] || $index[$parent]['building_id']!==$m['building_id']) {
                    $warnings[]='Neplatná hierarchie měřidla #'.$id; continue;
                }
                $children[$parent][]=$id;
            }
        }
        foreach ($index as $id=>$m) {
            $seen=[]; $current=$id;
            while (isset($index[$current]) && $index[$current]['parent_id']) {
                if (isset($seen[$current])) throw new RuntimeException('Cyklus v hierarchii měřidel.');
                $seen[$current]=true; $current=(int)$index[$current]['parent_id'];
            }
        }
        foreach ($index as $id=>$meter) {
            if (!isset($readings[$id]['start'],$readings[$id]['end'])) {
                $warnings[]='Chybí potřebné odečty měřidla '.$meter['name'].' (#'.$id.').'; continue;
            }
            $delta=(float)$readings[$id]['end']-(float)$readings[$id]['start'];
            if ($delta < -0.00001) { $warnings[]='Konečný stav je menší než počáteční u '.$meter['name'].'.'; continue; }
            $coef=(float)($coefficients[$id]??1);
            if ($coef<=0) { $warnings[]='Neplatný koeficient měřidla '.$meter['name']; continue; }
            $results[$id]=round(max(0,$delta)*$coef,4);
        }
        foreach ($index as $id=>$meter) {
            if (!isset($results[$id])) continue;
            $res=$results[$id]; $complete=true; $estimated=!empty($estimatedMeterIds[$id]);
            foreach ($children[$id] as $childId) {
                if (!isset($results[$childId])) { $complete=false; break; }
                $res-=$results[$childId];
                $estimated=$estimated || !empty($estimatedMeterIds[$childId]);
            }
            if (!$complete) { $warnings[]='Nelze vypočítat zbytek '.$meter['name'].': chybí odečet podružného měřidla.'; continue; }
            $res=round($res,4);
            if ($res < -0.0001) $warnings[]='Záporná zbytková spotřeba '.$meter['name'].' ('.$res.'). Zkontrolujte odečty/koeficienty.';
            $uid=(int)($meter['unit_id']??0);
            if ($uid>0) {
                $alloc[$uid][$meter['type']]=round(($alloc[$uid][$meter['type']]??0)+$res,4);
                $quality[$uid][$meter['type']]=($quality[$uid][$meter['type']]??false) || $estimated;
            } elseif (abs($res)>0.0001) {
                $warnings[]='Nepřiřazená zbytková spotřeba '.$meter['name'].': '.$res;
            }
            $residuals[$id]=$res;
        }
        return ['allocations'=>$alloc,'warnings'=>$warnings,'residuals'=>$residuals,'estimated'=>$quality];
    }

    /** Dny počítáme v UTC, aby výpočty nenarušovaly změny letního času. */
    private static function dayNumber(string $date): int {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d')!==$date) throw new RuntimeException('Neplatné datum: '.$date);
        return (int)($d->getTimestamp()/86400);
    }

    /**
     * Zjistí stav pro datum. Mezi odečty lineární interpolace; až 45 dní mimo
     * první/poslední odečet opatrná extrapolace se zřetelným označením odhadu.
     * Mimo pokrytí a limit se číslo nesmí vymýšlet.
     * @param array $series Seřazeno dle data, [['reading_date'=>..., 'value'=>...], ...]
     * @return array{value:float,method:string,span:int}|null
     */
    public static function boundary(array $series,string $date,int $maxProjectionDays=45): ?array {
        $target=self::dayNumber($date); $count=count($series);
        if ($count===0) return null;
        foreach ($series as $r) if ($r['reading_date']===$date)
            return ['value'=>(float)$r['value'],'method'=>'actual','span'=>0];
        if ($count<2) return null;
        $left=null; $right=null;
        for ($i=0;$i<$count-1;$i++) {
            $a=self::dayNumber((string)$series[$i]['reading_date']);
            $b=self::dayNumber((string)$series[$i+1]['reading_date']);
            if ($a<$target && $target<$b) { $left=$series[$i]; $right=$series[$i+1]; break; }
        }
        $method='interpolated';
        if ($left===null) {
            if ($target<self::dayNumber((string)$series[0]['reading_date'])) {
                $left=$series[0]; $right=$series[1];
                $distance=self::dayNumber((string)$left['reading_date'])-$target;
            } else {
                $left=$series[$count-2]; $right=$series[$count-1];
                $distance=$target-self::dayNumber((string)$right['reading_date']);
            }
            if ($distance>$maxProjectionDays) return null;
            $method='projected';
        }
        $a=self::dayNumber((string)$left['reading_date']);
        $b=self::dayNumber((string)$right['reading_date']);
        if ($b<=$a || (float)$right['value']<(float)$left['value']) return null;
        $value=(float)$left['value']+((float)$right['value']-(float)$left['value'])*($target-$a)/($b-$a);
        if ($value<0) return null;
        return ['value'=>$value,'method'=>$method,'span'=>$b-$a];
    }

    /**
     * Spotřeba měřicího místa s výměnami zařízení. Číselníky jednotlivých
     * fyzických přístrojů nikdy neodečítáme navzájem.
     * @param array $devices Zařízení patřící jedinému logickému meter_id.
     * @param array $seriesByDevice Odečty indexované podle device_id.
     * @return array|null Null při neúplných podkladech, nikdy částečný součet.
     */
    public static function deviceUsage(array $devices,array $seriesByDevice,string $start,string $end, bool $includeLowTariff = false): ?array {
        self::dayNumber($start);self::dayNumber($end);
        if($start >= $end) throw new RuntimeException('Chybné období.');
        $total=0.0;$estimated=false;$count=0;$firstMethod=null;$lastMethod=null;$warnings=[];
        usort($devices,static function ($a,$b) {return strcmp($a['installed_on'],$b['installed_on']);});
        foreach($devices as $device) {
            $installed=(string)$device['installed_on'];$removed=$device['removed_on'];
            if($installed >= $end || ($removed!==null && $removed <= $start)) continue;
            $aDate=max($start,$installed);$bDate=$removed===null?$end:min($end,(string)$removed);
            if($aDate >= $bDate) continue;
            $dates=[];
            foreach($seriesByDevice[(int)$device['id']]??[] as $reading)
                $dates[(string)$reading['reading_date']]=['reading_date'=>(string)$reading['reading_date'],'value'=>$reading['value']];
            // Protokol má při výměně přednost před běžným odečtem ze stejného dne.
            if($device['opening_value']!==null)
                $dates[$installed]=['reading_date'=>$installed,'value'=>$device['opening_value']];
            if($removed!==null && $device['closing_value']!==null)
                $dates[(string)$removed]=['reading_date'=>(string)$removed,'value'=>$device['closing_value']];
            ksort($dates,SORT_STRING);$observations=array_values($dates);
            $a=self::boundary($observations,$aDate);$b=self::boundary($observations,$bDate);
            if(!$a || !$b || $b['value']+0.00001 < $a['value']) return null;
            $total+=$b['value']-$a['value'];$count++;
            if($firstMethod===null) $firstMethod=$a['method'];
            $lastMethod=$b['method'];
            $estimated=$estimated || $a['method']!=='actual' || $b['method']!=='actual';
            if($a['method']==='projected' || $b['method']==='projected')
                $warnings[]='Odhad přesahuje dostupné odečty zařízení #'.$device['id'].' (max. 45 dní).';
            if(($a['span']>92 && $a['method']!=='actual') || ($b['span']>92 && $b['method']!=='actual'))
                $warnings[]='Odhad ze vzdálených odečtů zařízení #'.$device['id'].' (více než 92 dní).';

            if ($includeLowTariff) {
                $lowDates = [];
                foreach ($seriesByDevice[(int) $device['id']] ?? [] as $reading) {
                    if (($reading['value_low'] ?? null) !== null) {
                        $lowDates[(string) $reading['reading_date']] = [
                            'reading_date' => (string) $reading['reading_date'],
                            'value' => $reading['value_low'],
                        ];
                    }
                }
                if (($device['opening_value_low'] ?? null) !== null) {
                    $lowDates[$installed] = ['reading_date' => $installed, 'value' => $device['opening_value_low']];
                }
                if ($removed !== null && ($device['closing_value_low'] ?? null) !== null) {
                    $lowDates[(string) $removed] = ['reading_date' => (string) $removed, 'value' => $device['closing_value_low']];
                }
                if ($lowDates) {
                    ksort($lowDates, SORT_STRING);
                    $firstLowDate = (string) key($lowDates);
                    if ($firstLowDate < $bDate) {
                        // NT starts during the requested period: never report a partial total.
                        if ($firstLowDate > $aDate) {
                            return null;
                        }
                        $lowObservations = array_values($lowDates);
                        $lowA = self::boundary($lowObservations, $aDate);
                        $lowB = self::boundary($lowObservations, $bDate);
                        if (!$lowA || !$lowB || $lowB['value'] + 0.00001 < $lowA['value']) {
                            return null;
                        }
                        $total += $lowB['value'] - $lowA['value'];
                        $estimated = $estimated || $lowA['method'] !== 'actual' || $lowB['method'] !== 'actual';
                        if ($lowA['method'] === 'projected' || $lowB['method'] === 'projected') {
                            $warnings[] = 'Nízký tarif: extrapolace zařízení #'.$device['id'].'.';
                        }
                        if (($lowA['span'] > 92 && $lowA['method'] !== 'actual') || ($lowB['span'] > 92 && $lowB['method'] !== 'actual')) {
                            $warnings[] = 'Nízký tarif: vzdálené odečty zařízení #'.$device['id'].'.';
                        }
                    }
                }
            }
        }
        if($count===0) return null;
        return ['value'=>round($total,4),'estimated'=>$estimated,'from'=>$firstMethod,'to'=>$lastMethod,
            'warnings'=>$warnings,'segments'=>$count];
    }

    /** Načtení podkladů jednou; graf je pak může použít pro řadu období bez opakování SQL. */
    private static function dataset(PDO $db): array {
        $meters=$db->query('SELECT * FROM meters ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $devices=[];
        foreach ($db->query('SELECT * FROM meter_devices ORDER BY meter_id,installed_on,id')->fetchAll(PDO::FETCH_ASSOC) as $row)
            $devices[(int)$row['meter_id']][]=$row;
        $series=[];
        foreach ($db->query('SELECT device_id,reading_date,value,value_low FROM meter_readings ORDER BY device_id,reading_date')->fetchAll(PDO::FETCH_ASSOC) as $row)
            $series[(int)$row['device_id']][]=$row;
        $coefficients=[];
        foreach ($db->query('SELECT meter_id,effective_from,coefficient FROM meter_coefficients ORDER BY meter_id,effective_from')->fetchAll(PDO::FETCH_ASSOC) as $row)
            $coefficients[(int)$row['meter_id']][]=$row;
        $units=$db->query('SELECT id,wastewater_mode,name FROM units')->fetchAll(PDO::FETCH_ASSOC);
        return compact('meters','devices','series','coefficients','units');
    }

    private static function interval(array $data,string $start,string $end): array {
        if (self::dayNumber($start)>=self::dayNumber($end)) throw new RuntimeException('Konec období musí být později než začátek.');
        $readings=[]; $coefs=[]; $warnings=[]; $estimated=[]; $details=[];
        foreach ($data['meters'] as $meter) {
            $id=(int)$meter['id'];
            $usage=self::deviceUsage($data['devices'][$id]??[],$data['series'],$start,$end,$meter['type']==='electricity');
            if($usage===null) continue;
            $changes=$data['coefficients'][$id]??[];
            $current=1.0; $changed=false;
            foreach ($changes as $row) {
                $date=(string)$row['effective_from'];
                if ($date<=$start) $current=(float)$row['coefficient'];
                elseif ($date<$end) $changed=true;
            }
            if ($changed) {
                $warnings[]='Měřidlo '.$meter['name'].': koeficient se během období změnil; rozdělte období v místě změny a zajistěte mezilehlý odečet.';
                continue;
            }
            $coefs[$id]=$current;
            // calculate() dál pracuje s logickým meter_id a jeho hierarchií.
            $readings[$id]=['start'=>0.0,'end'=>$usage['value']];
            $estimated[$id]=$usage['estimated'];
            $details[$id]=['from'=>$usage['from'],'to'=>$usage['to']];
            foreach($usage['warnings'] as $warning) $warnings[]='Měřidlo '.$meter['name'].': '.$warning;
        }
        $result=self::calculate($data['meters'],$readings,$coefs,$estimated);
        $result['warnings']=array_merge($warnings,$result['warnings']);
        $result['boundary_methods']=$details;
        foreach ($data['units'] as $unit) {
            $id=(int)$unit['id'];
            if ($unit['wastewater_mode']==='metered' && !isset($result['allocations'][$id]['wastewater']))
                $result['warnings'][]='Odpadní voda '.$unit['name'].': vlastní měřidlo nemá vypočtenou spotřebu.';
            if ($unit['wastewater_mode']==='sum_water') {
                if (isset($result['allocations'][$id]['wastewater'])) {
                    $result['warnings'][]='Jednotka '.$unit['name'].': vlastní měřidlo odpadní vody a současně režim součtu vod.';
                    continue;
                }
                $cold=$result['allocations'][$id]['cold_water']??null;
                $hot=$result['allocations'][$id]['hot_water']??null;
                if ($cold===null || $hot===null) $result['warnings'][]='Odpadní voda '.$unit['name'].': chybí studená nebo teplá voda.';
                else {
                    $result['allocations'][$id]['wastewater']=round($cold+$hot,4);
                    $result['estimated'][$id]['wastewater']=!empty($result['estimated'][$id]['cold_water'])||!empty($result['estimated'][$id]['hot_water']);
                }
            }
        }
        return $result;
    }

    public static function fromDatabase(PDO $db,string $start,string $end): array {
        return self::interval(self::dataset($db),$start,$end);
    }

    /** Souhrn + vývoj po měsících (u dlouhých období seskupení), jediné načtení podkladů. */
    public static function report(PDO $db,string $start,string $end): array {
        $data=self::dataset($db);
        $overall=self::interval($data,$start,$end);
        $utc=new DateTimeZone('UTC');
        $cursor=new DateTimeImmutable($start,$utc); $stop=new DateTimeImmutable($end,$utc);
        $months=((int)$stop->format('Y')-(int)$cursor->format('Y'))*12+(int)$stop->format('n')-(int)$cursor->format('n')+1;
        $step=$months<=24?1:($months<=72?3:($months<=240?12:(int)ceil($months/24)));
        $periods=[];
        while ($cursor<$stop) {
            $next=$cursor->modify('first day of this month')->modify('+'.$step.' months');
            if ($next>$stop) $next=$stop;
            if ($next<=$cursor) break;
            $from=$cursor->format('Y-m-d'); $to=$next->format('Y-m-d');
            $periods[]=['from'=>$from,'to'=>$to,'result'=>self::interval($data,$from,$to)];
            $cursor=$next;
        }
        return ['overall'=>$overall,'periods'=>$periods,'group_months'=>$step];
    }
}
