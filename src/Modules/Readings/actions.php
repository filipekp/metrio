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
        $identity=Energy\MeterIdentity::assess($mid,$proposal['serial']??null,$proposal['ean']??null,
            Energy\MeterIdentity::candidates(db(),meter_scope($u)));
        sql('INSERT INTO reading_photos(meter_id,user_id,storage_name,mime,suggested_value,suggestion_note,detected_serial,detected_ean,identity_status,suggested_meter_id,original_meter_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$mid,$u['id'],$storage,$mime,$proposal['value'],$proposal['note'],
             $proposal['serial']??null,$proposal['ean']??null,$identity['status'],$identity['suggested_meter_id'],$mid]);
        $pid=(int)db()->lastInsertId();
    } catch(Throwable $e) { @unlink($target);throw $e; }
    audit((int)$u['id'],'photo_upload',(string)$pid);
    header('Location: ?page=photo_review&id='.$pid);exit;
}

if($action==='photo_confirm') {
    $photo=photo_for((int)($_POST['photo_id']??0),$u);
    if($photo['status']!=='pending') throw new RuntimeException('Fotografie již byla vyřízena.');
    $chosen=(int)($_POST['confirmed_meter_id']??0);
    $candidates=Energy\MeterIdentity::candidates(db(),meter_scope($u));
    $identity=Energy\MeterIdentity::assess((int)($photo['original_meter_id']??$photo['meter_id']),
        $photo['detected_serial'],$photo['detected_ean'],$candidates);
    $suggested=$identity['suggested_meter_id'];
    $safe=($identity['status']==='match' && $chosen===(int)($photo['original_meter_id']??$photo['meter_id']))
        || ($identity['status']==='other' && $suggested!==null && $chosen===$suggested);
    if(!$safe && empty($_POST['confirm_identity_override']))
        throw new RuntimeException('Fotografie neodpovídá ověřenému měřidlu. Vyberte měřidlo podle EAN/výrobního čísla, nebo výslovně potvrďte ruční ověření.');
    // Oprávnění musí být znovu ověřeno na serveru; hodnotě z formuláře nelze důvěřovat.
    meter_for_reading($chosen,$u);
    db()->beginTransaction();
    try {
        sql("UPDATE reading_photos SET meter_id=? WHERE id=? AND status='pending' AND user_id=?",[$chosen,$photo['id'],$photo['user_id']]);
        save_reading($u,$chosen,(string)($_POST['reading_date']??''),(string)($_POST['value']??''),'photo',(int)$photo['id']);
        sql("UPDATE reading_photos SET status='confirmed' WHERE id=? AND status='pending'",[$photo['id']]);
        audit((int)$u['id'],'photo_identity_confirm','foto='.$photo['id'].' původní='.($photo['original_meter_id']??$photo['meter_id']).' potvrzené='.$chosen.' status='.$identity['status'].' ručně='.(!$safe?'ano':'ne'));
        db()->commit();
    } catch(Throwable $e) {db()->rollBack();throw $e;}
    redirect('readings','Odečet z fotografie potvrzen a uložen.');
}

if($action==='photo_reject') {
    $photo=photo_for((int)($_POST['photo_id']??0),$u);
    sql("UPDATE reading_photos SET status='rejected' WHERE id=? AND status='pending'",[$photo['id']]);
    redirect('readings','Návrh odečtu zamítnut.');
}
