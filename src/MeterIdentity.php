<?php
declare(strict_types=1);
namespace Energy;

use PDO;
use RuntimeException;

/** Porovnání sériového čísla a číselného kódu EAN s povolenými fyzickými zařízeními. */
final class MeterIdentity {
    /** EAN/číselný kód se ukládá jako text, aby se neztratily počáteční nuly. */
    public static function ean($value): ?string {
        if(!is_string($value) && !is_numeric($value)) return null;
        $number=preg_replace('/[\s-]+/u','',trim((string)$value));
        return is_string($number) && preg_match('/^[0-9]{8,32}$/D',$number) ? $number : null;
    }
    public static function validatedEan(string $value): string {
        if(trim($value)==='') return '';
        $ean=self::ean($value);
        if($ean===null) throw new RuntimeException('EAN / číslo čárového kódu musí mít 8 až 32 číslic.');
        return $ean;
    }
    public static function serial($value): ?string {
        if(!is_string($value) && !is_numeric($value)) return null;
        $number=trim((string)$value);
        // OCR často vrací i předponu 'SN:' nebo 'S/N:'; nejde o součást čísla.
        $number=preg_replace('/^(?:S\s*\/\s*N|SN)\s*[:#.-]\s*/i','',$number);
        $number=strtoupper($number);
        $number=preg_replace('/[^A-Z0-9]/','',$number);
        return $number!=='' && strlen($number)<=160 ? $number : null;
    }
    /** Načte jen měřidla, která uživatel smí vidět; žádný odkaz na cizí měřidlo neunikne. */
    public static function candidates(PDO $db,array $visibleMeters): array {
        $ids=[];
        foreach($visibleMeters as $meter) if(!empty($meter['active'])) $ids[]=(int)$meter['id'];
        $ids=array_values(array_unique($ids));
        if(!$ids) return [];
        $stmt=$db->prepare('SELECT m.id,m.name,m.serial,m.type,b.name AS building_name,u.name AS unit_name,d.ean,d.serial AS device_serial FROM meters m JOIN buildings b ON b.id=m.building_id LEFT JOIN units u ON u.id=m.unit_id LEFT JOIN meter_devices d ON d.meter_id=m.id AND d.removed_on IS NULL WHERE m.active=1 AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    /**
     * @return array{status:string,suggested_meter_id:?int,note:string}
     * status: match, other, conflict, unknown, unverified
     */
    public static function assess(int $selectedId,$serial,$ean,array $candidates): array {
        $s=self::serial($serial);$e=self::ean($ean);
        if($s===null && $e===null) return ['status'=>'unverified','suggested_meter_id'=>null,'note'=>'Na fotografii se nepodařilo spolehlivě rozpoznat výrobní číslo ani EAN. Ověřte měřidlo ručně.'];
        $byId=[];$eanIds=[];$serialIds=[];
        foreach($candidates as $c) {
            $id=(int)$c['id'];$byId[$id]=$c;
            if($e!==null && self::ean($c['ean']??null)===$e) $eanIds[$id]=true;
            $serialStored=self::serial(($c['device_serial']??'')!=='' ? $c['device_serial'] : ($c['serial']??null));
            if($s!==null && $serialStored===$s) $serialIds[$id]=true;
        }
        if(count($eanIds)>1 || count($serialIds)>1)
            return ['status'=>'conflict','suggested_meter_id'=>null,'note'=>'Kód je evidován u více měřidel. Přiřazení není jednoznačné.'];
        $eanId=$eanIds? (int)array_key_first($eanIds):null;
        $serialId=$serialIds?(int)array_key_first($serialIds):null;
        if($eanId!==null && $serialId!==null && $eanId!==$serialId)
            return ['status'=>'conflict','suggested_meter_id'=>null,'note'=>'EAN a výrobní číslo ukazují na různá měřidla. Zkontrolujte fotografii a evidenci.'];
        $matched=$eanId??$serialId;
        if($matched!==null) {
            $record=$byId[$matched];
            $savedEan=self::ean($record['ean']??null);
            if($savedEan!==null && $e!==null && $e!==$savedEan)
                return ['status'=>'conflict','suggested_meter_id'=>null,'note'=>'Rozpoznaný EAN se neshoduje s EAN evidovaným u zařízení, na které ukazuje výrobní číslo.'];
            if($matched===$selectedId) {
                $note=$eanId!==null ? 'EAN souhlasí s vybraným měřidlem v databázi.'
                    : 'Výrobní číslo souhlasí s vybraným měřidlem v databázi.';
                if($eanId===null && $e!==null) $note.=' Rozpoznaný EAN zatím není v dostupné evidenci; doporučujeme jej doplnit po ruční kontrole.';
                return ['status'=>'match','suggested_meter_id'=>$matched,'note'=>$note];
            }
            return ['status'=>'other','suggested_meter_id'=>$matched,'note'=>'Fotografie podle '.($eanId!==null?'EAN':'výrobního čísla').' patří jinému evidovanému měřidlu. Vyberte navržené měřidlo.'];
        }
        $selected=$byId[$selectedId]??null;
        if($selected && $e!==null && self::ean($selected['ean']??null)!==null)
            return ['status'=>'conflict','suggested_meter_id'=>null,'note'=>'EAN z fotografie nesouhlasí s EAN přiřazeným vybranému měřidlu.'];
        return ['status'=>'unknown','suggested_meter_id'=>null,'note'=>'Výrobní číslo ani EAN z fotografie se nepodařilo dohledat mezi dostupnými měřidly. Ověřte identitu ručně.'];
    }
    /** Zamezí přiřazení jednoho EAN dvěma aktivním fyzickým zařízením. */
    public static function ensureUniqueEan(PDO $db,string $ean,int $exceptDeviceId=0): void {
        if($ean==='') return;
        $stmt=$db->prepare('SELECT id FROM meter_devices WHERE ean=? AND removed_on IS NULL AND id<>? LIMIT 1');
        $stmt->execute([$ean,$exceptDeviceId]);
        if($stmt->fetchColumn()!==false) throw new RuntimeException('Tento EAN už používá jiné aktivní měřidlo.');
    }
}
