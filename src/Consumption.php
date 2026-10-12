<?php
declare(strict_types=1);
namespace Energy;
use RuntimeException;

/** Čistý výpočet pro testování i webové rozhraní. */
final class Consumption {
    /**
     * @param array $meters rows with id,parent_id,unit_id,type,name
     * @param array $readings [meterId=>['start'=>number,'end'=>number]]
     * @param array $coefficients [meterId=>number]
     * @return array{allocations:array,warnings:array,residuals:array}
     */
    public static function calculate(array $meters,array $readings,array $coefficients): array {
        $index=[];$children=[];$warnings=[];$results=[];$alloc=[];
        foreach($meters as $m) { $id=(int)$m['id'];$index[$id]=$m;$children[$id]=[]; }
        foreach($meters as $m) {
            $id=(int)$m['id'];$parent=(int)($m['parent_id']??0);
            if($parent) {
                if(!isset($index[$parent]) || $index[$parent]['type']!==$m['type'] || $index[$parent]['building_id']!==$m['building_id']) { $warnings[]='Neplatná hierarchie měřidla #'.$id;continue; }
                $children[$parent][]=$id;
            }
        }
        $depth=[];$visit=function(int $id) use (&$visit,&$depth,&$index): int {
            if(isset($depth[$id])) return $depth[$id];
            $seen=[];$current=$id;$n=0;
            while(isset($index[$current]) && $index[$current]['parent_id']) {
                if(isset($seen[$current])) throw new RuntimeException('Cyklus v hierarchii měřidel.');
                $seen[$current]=true;$n++;$current=(int)$index[$current]['parent_id'];
            }
            return $depth[$id]=$n;
        };
        foreach(array_keys($index) as $id) $visit($id);
        foreach($index as $id=>$meter) {
            if(!isset($readings[$id]['start'],$readings[$id]['end'])) {
                $warnings[]='Chybějí hraniční odečty měřidla '.$meter['name'].' (#'.$id.').';continue;
            }
            $delta=(float)$readings[$id]['end']-(float)$readings[$id]['start'];
            if($delta < 0) { $warnings[]='Konečný stav je menší než počáteční u '.$meter['name'].'.';continue; }
            $coeff=(float)($coefficients[$id]??1.0);
            if($coeff<=0) { $warnings[]='Neplatný koeficient měřidla '.$meter['name'];continue; }
            $results[$id]=round($delta*$coeff,4);
        }
        foreach($index as $id=>$meter) {
            if(!isset($results[$id])) continue;
            $res=$results[$id];$complete=true;
            foreach($children[$id] as $childId) {
                if(!isset($results[$childId])) { $complete=false;break; }
                $res-=$results[$childId];
            }
            if(!$complete) { $warnings[]='Nelze vypočítat zbytek '.$meter['name'].': chybí odečet podružného měřidla.';continue; }
            $res=round($res,4);
            if($res < -0.0001) $warnings[]='Záporná zbytková spotřeba '.$meter['name'].' ('.$res.'). Zkontrolujte odečty/koeficienty.';
            $uid=(int)($meter['unit_id']??0);
            if($uid>0) {
                $alloc[$uid][$meter['type']]=round(($alloc[$uid][$meter['type']]??0)+$res,4);
            } else if(abs($res)>0.0001) {
                $warnings[]='Nepřiřazená zbytková spotřeba '.$meter['name'].': '.$res;
            }
            $residuals[$id]=$res;
        }
        return ['allocations'=>$alloc,'warnings'=>$warnings,'residuals'=>$residuals??[]];
    }
    public static function fromDatabase(\PDO $db,string $start,string $end): array {
        $meters=$db->query('SELECT * FROM meters ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $ids=array_column($meters,'id');$reading=[];$coef=[];$warnings=[];
        if($ids) {
            $q=$db->prepare('SELECT meter_id,reading_date,value FROM meter_readings WHERE reading_date IN (?,?)');
            $q->execute([$start,$end]);
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $r) $reading[(int)$r['meter_id']][$r['reading_date']===$start?'start':'end']=(float)$r['value'];
            $rows=$db->query('SELECT meter_id,effective_from,coefficient FROM meter_coefficients ORDER BY meter_id,effective_from')->fetchAll(\PDO::FETCH_ASSOC);
            foreach($rows as $r) {
                $id=(int)$r['meter_id'];$date=$r['effective_from'];
                if($date<=$start) $coef[$id]=(float)$r['coefficient'];
                elseif($date<=$end) {
                    $warnings[]='Během období se změnil koeficient měřidla #'.$id.'. Je nutné období rozdělit a zadat mezilehlý odečet.';
                    unset($reading[$id]);
                }
            }
        }
        $result=self::calculate($meters,$reading,$coef);
        $result['warnings']=array_merge($warnings,$result['warnings']);
        $units=$db->query('SELECT id,wastewater_mode,name FROM units')->fetchAll(\PDO::FETCH_ASSOC);
        foreach($units as $u) {
            $id=(int)$u['id'];
            if($u['wastewater_mode']==='metered' && !isset($result['allocations'][$id]['wastewater']))
                $result['warnings'][]='Odpadní voda '.$u['name'].': režim vlastního měřidla, ale chybí vypočtená spotřeba.';
            if($u['wastewater_mode']==='sum_water') {
                if(isset($result['allocations'][$id]['wastewater'])) {
                    $result['warnings'][]='Jednotka '.$u['name'].': vlastní měřidlo odpadní vody a současně režim součtu vod.';
                    continue;
                }
                // Je to záměrný účetní režim; nesmí se použít při dvojím započtení stejné vody.
                $cold=$result['allocations'][$id]['cold_water']??null;
                $hot=$result['allocations'][$id]['hot_water']??null;
                if($cold===null || $hot===null) $result['warnings'][]='Odpadní voda '.$u['name'].': chybí studená nebo teplá voda.';
                else $result['allocations'][$id]['wastewater']=round($cold+$hot,4);
            }
        }
        return $result;
    }
}
