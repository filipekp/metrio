<?php
declare(strict_types=1);
namespace Energy;

use PDO;
use RuntimeException;

/** Fyzická zařízení jednoho měřicího místa; původní meter_id zůstává stabilní. */
final class MeterRegistry {
    public static function deviceOn(PDO $db, int $meterId, string $date): ?array {
        $stmt=$db->prepare('SELECT * FROM meter_devices WHERE meter_id=? AND installed_on<=? AND (removed_on IS NULL OR removed_on>=?) ORDER BY (removed_on IS NULL) DESC,installed_on DESC,id DESC LIMIT 1');
        $stmt->execute([$meterId,$date,$date]);
        $device=$stmt->fetch(PDO::FETCH_ASSOC);
        return $device ?: null;
    }

    /** Meze kumulativního číselníku z okolních odečtů a z protokolu výměny. */
    public static function bounds(PDO $db, array $device, string $date, bool $low = false): array {
        $id = (int) $device['id'];
        $column = $low ? 'value_low' : 'value';
        $open = $low ? 'opening_value_low' : 'opening_value';
        $close = $low ? 'closing_value_low' : 'closing_value';
        $stmt=$db->prepare('SELECT '.$column.' FROM meter_readings WHERE device_id=? AND '.$column.' IS NOT NULL AND reading_date<? ORDER BY reading_date DESC LIMIT 1');
        $stmt->execute([$id,$date]);$prev=$stmt->fetchColumn();
        $stmt=$db->prepare('SELECT '.$column.' FROM meter_readings WHERE device_id=? AND '.$column.' IS NOT NULL AND reading_date>? ORDER BY reading_date ASC LIMIT 1');
        $stmt->execute([$id,$date]);$next=$stmt->fetchColumn();
        $minimum=$prev===false?null:(float)$prev;
        $maximum=$next===false?null:(float)$next;
        if(($device[$open] ?? null)!==null) {
            $v=(float)$device[$open];
            $minimum=$minimum===null?$v:max($minimum,$v);
        }
        if(($device[$close] ?? null)!==null) {
            $v=(float)$device[$close];
            $maximum=$maximum===null?$v:min($maximum,$v);
        }
        return ['minimum'=>$minimum,'maximum'=>$maximum];
    }

    /** Odmítne i zpětně vložený odečet, který by rozbil monotónnost číselníku. */
    public static function assertReadingInRange(float $value,array $limits): void {
        if($limits['minimum']!==null && $value+0.000001<$limits['minimum'])
            throw new RuntimeException('Stav číselníku nesmí být nižší než předchozí odečet / počáteční stav ('.number_format($limits['minimum'],4,',',' ').'). Pro jiné výrobní číslo použijte Výměnu měřidla.');
        if($limits['maximum']!==null && $value-0.000001>$limits['maximum'])
            throw new RuntimeException('Stav číselníku nesmí být vyšší než následující odečet / konečný stav ('.number_format($limits['maximum'],4,',',' ').').');
    }

    /** Výmena se serializuje přes zamčený záznam měřicího místa. */
    public static function replace(PDO $db, int $meterId, string $date, string $final, string $start, string $serial, string $note, string $ean = '', ?string $finalLow = null, ?string $startLow = null): void {
        $date=\valid_day($date);
        if($date>date('Y-m-d')) throw new RuntimeException('Výměnu lze zapsat nejdříve v den skutečné výměny.');
        $final=\valid_number($final);$start=\valid_number($start);
        $finalLow = $finalLow !== null && trim($finalLow) !== '' ? \valid_number($finalLow) : null;
        $startLow = $startLow !== null && trim($startLow) !== '' ? \valid_number($startLow) : null;
        $serial=trim($serial);$note=trim($note);
        $ean=MeterIdentity::validatedEan($ean);
        if($serial==='' || strlen($serial)>160 || strlen($note)>255) throw new RuntimeException('Vyplňte výrobní číslo nového zařízení (max. 160 znaků) a případně poznámku (max. 255 znaků).');
        $db->beginTransaction();
        try {
            $stmt=$db->prepare('SELECT * FROM meters WHERE id=? FOR UPDATE');
            $stmt->execute([$meterId]);$meter=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$meter || !(int)$meter['active']) throw new RuntimeException('Měřicí místo neexistuje nebo je neaktivní.');
            if ($meter['type'] !== 'electricity' && ($finalLow !== null || $startLow !== null)) {
                throw new RuntimeException('Nízký tarif lze zadat pouze u elektroměru.');
            }
            if (($finalLow === null) !== ($startLow === null)) {
                throw new RuntimeException('Při výměně vyplňte oba stavy NT, nebo oba ponechte prázdné.');
            }
            $stmt=$db->prepare('SELECT * FROM meter_devices WHERE meter_id=? AND removed_on IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$meterId]);$old=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$old) throw new RuntimeException('Měřidlo nemá aktivní fyzické zařízení.');
            if($date<=(string)$old['installed_on']) throw new RuntimeException('Datum výměny musí být později než datum instalace předchozího zařízení.');
            if($serial===(string)$old['serial']) throw new RuntimeException('Nové zařízení musí mít jiné výrobní číslo.');
            $stmt=$db->prepare('SELECT reading_date,value FROM meter_readings WHERE device_id=? ORDER BY reading_date DESC LIMIT 1');
            $stmt->execute([(int)$old['id']]);$last=$stmt->fetch(PDO::FETCH_ASSOC);
            if($last && $last['reading_date']>$date) throw new RuntimeException('Na původním zařízení již existují odečty po datu výměny.');
            if($last && (float)$final<(float)$last['value']) throw new RuntimeException('Konečný stav starého zařízení nesmí být nižší než jeho poslední odečet.');
            if($last && $last['reading_date']===$date && abs((float)$final-(float)$last['value'])>0.00001)
                throw new RuntimeException('V den výměny už existuje odečet starého zařízení. Konečný stav v protokolu musí být stejný.');
            if($old['opening_value']!==null && (float)$final<(float)$old['opening_value']) throw new RuntimeException('Konečný stav je nižší než počáteční stav starého zařízení.');
            $stmt = $db->prepare('SELECT reading_date,value_low FROM meter_readings WHERE device_id=? AND value_low IS NOT NULL ORDER BY reading_date DESC LIMIT 1');
            $stmt->execute([(int) $old['id']]);
            $lastLow = $stmt->fetch(PDO::FETCH_ASSOC);
            $hadLow = $lastLow || ($old['opening_value_low'] ?? null) !== null;
            if ($hadLow && ($finalLow === null || $startLow === null)) {
                throw new RuntimeException('Zařízení již eviduje NT. Při výměně zadejte konečný i počáteční stav NT.');
            }
            if ($finalLow !== null) {
                if ($lastLow && (float) $finalLow < (float) $lastLow['value_low']) {
                    throw new RuntimeException('Konečný stav NT nesmí být nižší než předchozí odečet NT.');
                }
                if ($lastLow && $lastLow['reading_date'] === $date && abs((float) $finalLow - (float) $lastLow['value_low']) > 0.00001) {
                    throw new RuntimeException('Stav NT v den výměny musí odpovídat existujícímu odečtu NT.');
                }
                if (($old['opening_value_low'] ?? null) !== null && (float) $finalLow < (float) $old['opening_value_low']) {
                    throw new RuntimeException('Konečný stav NT nesmí být nižší než počáteční stav NT.');
                }
            }
            $stmt=$db->prepare('UPDATE meter_devices SET removed_on=?,closing_value=?,closing_value_low=? WHERE id=? AND removed_on IS NULL');
            $stmt->execute([$date,$final,$finalLow,(int)$old['id']]);
            MeterIdentity::ensureUniqueEan($db,$ean);
            $stmt=$db->prepare('INSERT INTO meter_devices(meter_id,serial,ean,installed_on,opening_value,opening_value_low,note) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$meterId,$serial,$ean,$date,$start,$startLow,$note]);
            $stmt=$db->prepare('UPDATE meters SET serial=? WHERE id=?');
            $stmt->execute([$serial,$meterId]);
            $db->commit();
        } catch(\Throwable $e) {
            if($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
