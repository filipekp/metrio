<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use Energy\MeterIdentity;
use Energy\Vision;

function check_identity(bool $result,string $message): void {
    if(!$result) throw new RuntimeException('FAIL: '.$message);
    echo 'OK: '.$message.PHP_EOL;
}
function vision_identity_response(array $fields): string {
    return json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($fields)]]]]]]);
}

$photo=Vision::parseResponse(vision_identity_response([
    'value'=>'1216','serial'=>'3000030434','ean'=>'9081319000033204','note'=>'Čitelný displej'
]));
check_identity($photo['value']==='1216','Stav odečtu je nezávislý na sériovém čísle');
check_identity($photo['serial']==='3000030434','Gemini získalo výrobní číslo');
check_identity(MeterIdentity::serial('SN:3000030434')==='3000030434','OCR předpona SN není součást výrobního čísla');
check_identity($photo['ean']==='9081319000033204','Gemini získalo číselný čárový kód');
check_identity(MeterIdentity::ean('0000 1234 5678 9012')==='0000123456789012','EAN zachovává úvodní nuly');
check_identity(MeterIdentity::ean('1234ab567')===null,'Chybně rozpoznaný EAN se nepoužije');

$visible=[
 ['id'=>1,'name'=>'Hlavní elektroměr','serial'=>'3000030434','device_serial'=>'3000030434','ean'=>'9081319000033204'],
 ['id'=>2,'name'=>'Podružný','serial'=>'S2','device_serial'=>'S2','ean'=>'0000123456789'],
];
$a=MeterIdentity::assess(1,'3000030434','9081319000033204',$visible);
check_identity($a['status']==='match' && $a['suggested_meter_id']===1,'EAN souhlasí s vybraným měřidlem');
$a=MeterIdentity::assess(2,'3000030434','9081319000033204',$visible);
check_identity($a['status']==='other' && $a['suggested_meter_id']===1,'EAN existuje u jiného měřidla v databázi');
$a=MeterIdentity::assess(1,'S2','9081319000033204',$visible);
check_identity($a['status']==='conflict' && $a['suggested_meter_id']===null,'Rozpor EAN a SN nevytváří falešný návrh');
$a=MeterIdentity::assess(1,null,'0000123456789',$visible);
check_identity($a['status']==='other' && $a['suggested_meter_id']===2,'Samotný EAN stačí k návrhu jiného měřidla');
$a=MeterIdentity::assess(2,null,'9081319000033204',[$visible[1]]);
check_identity($a['suggested_meter_id']===null,'Neoprávněné měřidlo není nabízeno');
$a=MeterIdentity::assess(1,null,'9081319000033204',[$visible[0],$visible[0]]);
check_identity($a['status']==='match','Stejný záznam ve výběru není konflikt');
$a=MeterIdentity::assess(1,null,'1234567890123456',$visible);
check_identity($a['status']==='conflict','Odlišný EAN vybraného měřidla vyvolá varování');
$a=MeterIdentity::assess(1,null,null,$visible);
check_identity($a['status']==='unverified','Bez AI údajů nelze tvrdit, že měřidlo odpovídá');

$prompt=Vision::requestPayload('img','image/jpeg','kWh')['contents'][0]['parts'][0]['text'];
check_identity(strpos($prompt,'ean')!==false && strpos($prompt,'serial')!==false,'Prompt nezaměňuje odečet, výrobní číslo a EAN');
echo "Identifikace EAN úspěšně otestována.\n";
