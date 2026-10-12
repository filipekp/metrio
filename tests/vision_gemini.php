<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

function assert_vision($truth,string $message): void {
    if(!$truth) throw new RuntimeException('FAIL: '.$message);
    echo 'OK: '.$message.PHP_EOL;
}
function gemini_fixture($value,$note='Zobrazený údaj'): string {
    return json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(['value'=>$value,'note'=>$note],JSON_UNESCAPED_UNICODE)]]]]]],JSON_UNESCAPED_UNICODE);
}

$payload=Energy\Vision::requestPayload("image\0bytes",'image/jpeg','kWh');
assert_vision($payload['contents'][0]['parts'][1]['inline_data']['data']===base64_encode("image\0bytes"),'Gemini dostává obrázek jako Base64');
assert_vision($payload['contents'][0]['parts'][1]['inline_data']['mime_type']==='image/jpeg','Předání správného MIME');
assert_vision($payload['generationConfig']['responseMimeType']==='application/json','Vyžadován JSON výstup');
assert_vision(strpos($payload['contents'][0]['parts'][0]['text'],'kumulativní')!==false,'Prompt požaduje stav počítadla');

$result=Energy\Vision::parseResponse(gemini_fixture('1721.025'));
assert_vision($result['value'] === '1721.025','Rozpoznání desetinného čísla');
$result=Energy\Vision::parseResponse(gemini_fixture('1721,025'));
assert_vision($result['value'] === '1721.025','Převod desetinné čárky');
$result=Energy\Vision::parseResponse(gemini_fixture(null,'Nečitelný displej'));
assert_vision($result['value']===null && strpos($result['note'],'Nečitelný')!==false,'Nejistá hodnota se neukládá');
$result=Energy\Vision::parseResponse(gemini_fixture('1721 kWh'));
assert_vision($result['value']===null,'Číslo s jednotkou se neakceptuje');
$result=Energy\Vision::parseResponse(gemini_fixture('-34'));
assert_vision($result['value']===null,'Záporný odečet se neakceptuje');
$result=Energy\Vision::parseResponse('{"candidates":[]}');
assert_vision($result['value']===null,'Prázdná odpověď nevede k chybnému odečtu');
$result=Energy\Vision::parseResponse('invalid JSON');
assert_vision($result['value']===null,'Poškozená odpověď nevede k chybnému odečtu');
$result=Energy\Vision::parseResponse(gemini_fixture('1.5',str_repeat('a',1000)));
assert_vision(strlen($result['note'])<=250,'Omezení délky poznámky');

// Kontrola při chybějícím klíči pouze při jeho skutečné absenci v prostředí.
if(trim(config('GEMINI_API_KEY'))==='') {
    $result=Energy\Vision::propose('/nonexistent','image/jpeg','kWh');
    assert_vision($result['value']===null,'Bez API klíče zůstává ruční zadání');
}
echo "Gemini testy dokončeny.\n";
