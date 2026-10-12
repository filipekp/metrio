<?php
declare(strict_types=1);

// Zpracování akcí modulu Readings. Oprávnění jsou kontrolována uvnitř akcí.
if($action==='reading_add') {
    save_reading($u,(int)($_POST['meter_id']??0),(string)($_POST['reading_date']??''),(string)($_POST['value']??''));
    redirect('readings','Odečet uložen.');
}

if($action==='photo_upload') {
    $mid=(int)($_POST['meter_id']??0);$meter=meter_for_reading($mid,$u);
    if(!(int)$meter['active']) throw new RuntimeException('Měřidlo není aktivní.');
    $file=$_FILES['photo']??null;
    if(!$file || $file['error']!==UPLOAD_ERR_OK || (int)$file['size']>5*1024*1024 || (int)$file['size']<100)
        throw new RuntimeException('Fotografie musí být JPG/PNG/WEBP a menší než 5 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !@getimagesize($file['tmp_name']))
        throw new RuntimeException('Nepodporovaný nebo poškozený obrázek.');
    $storage=bin2hex(random_bytes(24));$target=APP_ROOT.'/storage/photos/'.$storage;
    if(!move_uploaded_file($file['tmp_name'],$target)) throw new RuntimeException('Fotografii nelze uložit.');
    @chmod($target,0600);
    $proposal=['value'=>null,'note'=>'AI není dostupná.'];
    try { $proposal=Energy\Vision::propose($target,$mime,$labels[$meter['type']]??''); }
    catch(Throwable $e) { error_log('AI OCR: '.$e->getMessage());$proposal=['value'=>null,'note'=>'AI zpracování se nezdařilo. Opište stav ručně.']; }
    try {
        sql('INSERT INTO reading_photos(meter_id,user_id,storage_name,mime,suggested_value,suggestion_note) VALUES(?,?,?,?,?,?)',[$mid,$u['id'],$storage,$mime,$proposal['value'],$proposal['note']]);
        $pid=(int)db()->lastInsertId();
    } catch(Throwable $e) { @unlink($target);throw $e; }
    audit((int)$u['id'],'photo_upload',(string)$pid);
    header('Location: ?page=photo_review&id='.$pid);exit;
}

if($action==='photo_confirm') {
    $photo=photo_for((int)($_POST['photo_id']??0),$u);
    if($photo['status']!=='pending') throw new RuntimeException('Fotografie již byla vyřízena.');
    db()->beginTransaction();
    try {
        save_reading($u,(int)$photo['meter_id'],(string)($_POST['reading_date']??''),(string)($_POST['value']??''),'photo',(int)$photo['id']);
        sql("UPDATE reading_photos SET status='confirmed' WHERE id=? AND status='pending'",[$photo['id']]);
        db()->commit();
    } catch(Throwable $e) {db()->rollBack();throw $e;}
    redirect('readings','Odečet z fotografie potvrzen a uložen.');
}

if($action==='photo_reject') {
    $photo=photo_for((int)($_POST['photo_id']??0),$u);
    sql("UPDATE reading_photos SET status='rejected' WHERE id=? AND status='pending'",[$photo['id']]);
    redirect('readings','Návrh odečtu zamítnut.');
}
