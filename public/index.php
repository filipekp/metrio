<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
start_session();
$page=(string)($_GET['page']??'dashboard');
$labels=energy_labels();

function save_reading(array $user,int $meterId,string $date,string $raw, string $source='manual',?int $photo=null): void {
    $meter=meter_for_reading($meterId,$user);
    if(!(int)$meter['active']) throw new RuntimeException('Měřidlo je neaktivní.');
    $day=valid_day($date);$value=valid_number($raw);
    $prev=one('SELECT value FROM meter_readings WHERE meter_id=? AND reading_date<? ORDER BY reading_date DESC LIMIT 1',[$meterId,$day]);
    $next=one('SELECT value FROM meter_readings WHERE meter_id=? AND reading_date>? ORDER BY reading_date ASC LIMIT 1',[$meterId,$day]);
    if($prev && (float)$prev['value']>(float)$value) throw new RuntimeException('Odečet je nižší než předchozí stav. Při výměně měřidla založte nové měřidlo.');
    if($next && (float)$next['value']<(float)$value) throw new RuntimeException('Odečet je vyšší než následující stav.');
    sql('INSERT INTO meter_readings(meter_id,reading_date,value,source,photo_id,created_by) VALUES(?,?,?,?,?,?)',[$meterId,$day,$value,$source,$photo,$user['id']]);
    audit((int)$user['id'],'reading_create','měřidlo='.$meterId.' datum='.$day.' stav='.$value);
}
function photo_for(int $id,array $user): array {
    $photo=one('SELECT p.*,m.name AS meter_name,m.type AS meter_type,m.unit_id,m.building_id,m.id AS meter_id FROM reading_photos p JOIN meters m ON m.id=p.meter_id WHERE p.id=?',[$id]);
    if(!$photo || !can_read_meter($user,$photo)
        || (!is_admin($user) && !is_manager($user) && (int)$photo['user_id'] !== (int)$user['id'])) {
        http_response_code(403); throw new RuntimeException('Fotografie není dostupná.');
    }
    return $photo;
}
function unit_scope(array $user): array {
    if(is_admin($user)) return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id ORDER BY b.name,u.name');
    if(is_manager($user)) return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id JOIN building_managers bm ON bm.building_id=b.id WHERE bm.user_id=? ORDER BY b.name,u.name',[$user['id']]);
    return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id JOIN unit_users au ON au.unit_id=u.id WHERE au.user_id=? ORDER BY b.name,u.name',[$user['id']]);
}
function meter_scope(array $user,bool $readable=false): array {
    $base='SELECT m.*,b.name AS building_name,u.name AS unit_name,p.name AS parent_name FROM meters m JOIN buildings b ON b.id=m.building_id LEFT JOIN units u ON u.id=m.unit_id LEFT JOIN meters p ON p.id=m.parent_id';
    $order=' ORDER BY b.name,m.type,m.parent_id,m.name';
    if(is_admin($user)) return all($base.$order);
    if(is_manager($user)) return all($base.' JOIN building_managers bm ON bm.building_id=m.building_id WHERE bm.user_id=?'.$order,[(int)$user['id']]);
    // SQL omezuje výsledek na přístupné jednotky (ne načíst všechna cizí měřidla a filtrovat v PHP).
    return all($base.' JOIN unit_users au ON au.unit_id=m.unit_id WHERE au.user_id=?'.$order,[(int)$user['id']]);
}

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

if($page==='photo_file') {
    $u=require_login();$p=photo_for((int)($_GET['id']??0),$u);
    $path=APP_ROOT.'/storage/photos/'.$p['storage_name'];
    if(!is_file($path)) {http_response_code(404);exit('Fotografie nenalezena');}
    header('Content-Type: '.$p['mime']);header('Content-Length: '.filesize($path));
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    readfile($path);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    try {
        if($page==='login') {
            $now=time();$attempts=array_filter($_SESSION['attempts']??[],static function ($t) use ($now) { return $now - $t < 600; });
            if(count($attempts)>=10) throw new RuntimeException('Příliš mnoho pokusů. Zkuste přihlášení později.');
            $email=strtolower(trim((string)($_POST['email']??'')));
            $u=one('SELECT * FROM users WHERE email=?',[$email]);
            if(!$u || !(int)$u['is_active'] || !password_verify((string)($_POST['password']??''),$u['password_hash'])) {
                $attempts[]=$now;$_SESSION['attempts']=$attempts;
                throw new RuntimeException('Nesprávný e-mail nebo heslo.');
            }
            session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['attempts']=[];redirect('dashboard','Přihlášení proběhlo úspěšně.');
        }
        $u=require_login();$action=(string)($_POST['action']??'');
        if($page==='logout') { $_SESSION=[];session_destroy();header('Location: ?page=login');exit; }
        if($action==='building_create') {
            admin_only($u);$name=trim((string)($_POST['name']??''));if($name==='') throw new RuntimeException('Vyplňte název objektu.');
            sql('INSERT INTO buildings(name,address) VALUES(?,?)',[$name,trim((string)($_POST['address']??''))]);
            audit((int)$u['id'],'building_create',$name);redirect('buildings','Objekt přidán.');
        }
        if($action==='unit_create') {
            admin_only($u);$b=(int)($_POST['building_id']??0);if(!one('SELECT id FROM buildings WHERE id=?',[$b])) throw new RuntimeException('Objekt neexistuje.');
            $name=trim((string)($_POST['name']??''));if($name==='') throw new RuntimeException('Zadejte název jednotky.');
            $kind=(string)($_POST['kind']??'apartment');$mode=(string)($_POST['wastewater_mode']??'none');
            if(!in_array($kind,['apartment','common'],true) || !in_array($mode,['none','sum_water','metered'],true)) throw new RuntimeException('Neplatný režim jednotky.');
            sql('INSERT INTO units(building_id,name,kind,wastewater_mode) VALUES(?,?,?,?)',[$b,$name,$kind,$mode]);
            audit((int)$u['id'],'unit_create',$name);redirect('units','Jednotka přidána.');
        }
        if($action==='unit_mode') {
            admin_only($u);$mode=(string)($_POST['wastewater_mode']??'none');
            if(!in_array($mode,['none','sum_water','metered'],true)) throw new RuntimeException('Neplatný režim.');
            sql('UPDATE units SET wastewater_mode=? WHERE id=?',[$mode,(int)$_POST['unit_id']]);
            audit((int)$u['id'],'unit_mode',(string)$_POST['unit_id'].':'.$mode);redirect('units','Režim změněn.');
        }
        if($action==='user_create') {
            admin_only($u);$email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['name']??''));$password=(string)($_POST['password']??'');
            $role=(string)($_POST['role']??'user');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL) || $name==='' || strlen($password)<12 || !in_array($role,['user','manager','admin'],true)) throw new RuntimeException('Zadejte jméno, platný e-mail a heslo alespoň 12 znaků.');
            sql('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
            audit((int)$u['id'],'user_create',$email);redirect('users','Uživatel přidán.');
        }
        if($action==='user_toggle') {
            admin_only($u);$id=(int)($_POST['user_id']??0);
            if($id===(int)$u['id']) throw new RuntimeException('Vlastní účet nelze deaktivovat.');
            $target=one('SELECT is_active,role FROM users WHERE id=?',[$id]);
            if(!$target) throw new RuntimeException('Uživatel neexistuje.');
            if($target['role']==='admin' && $target['is_active']) {
                $count=one("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1");
                if((int)$count['c']<=1) throw new RuntimeException('Posledního administrátora nelze deaktivovat.');
            }
            sql('UPDATE users SET is_active=? WHERE id=?',[(int)!$target['is_active'],$id]);
            audit((int)$u['id'],'user_toggle','uid='.$id);redirect('users','Stav uživatele změněn.');
        }
        if($action==='user_update') {
            admin_only($u);$id=(int)($_POST['user_id']??0);
            $target=one('SELECT * FROM users WHERE id=?',[$id]);
            if(!$target) throw new RuntimeException('Uživatel neexistuje.');
            $name=trim((string)($_POST['name']??''));$role=(string)($_POST['role']??'user');$password=(string)($_POST['password']??'');
            if($name==='' || !in_array($role,['admin','manager','user'],true) || ($password!=='' && strlen($password)<12))
                throw new RuntimeException('Neplatné jméno, role nebo heslo kratší než 12 znaků.');
            if($id===(int)$u['id'] && $role!=='admin') throw new RuntimeException('Nelze odebrat vlastní administrátorskou roli.');
            if($target['role']==='admin' && $target['is_active'] && $role!=='admin') {
                $count=one("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1");
                if((int)$count['c']<=1) throw new RuntimeException('Posledního administrátora nelze změnit na uživatele.');
            }
            db()->beginTransaction();
            try {
                if($password==='') sql('UPDATE users SET name=?,role=? WHERE id=?',[$name,$role,$id]);
                else sql('UPDATE users SET name=?,role=?,password_hash=? WHERE id=?',[$name,$role,password_hash($password,PASSWORD_DEFAULT),$id]);
                // Při odebrání role správce odebrat i stará přiřazení, aby se po opětovném povýšení neoživila.
                if($role!=='manager') sql('DELETE FROM building_managers WHERE user_id=?',[$id]);
                db()->commit();
            } catch(Throwable $e) { db()->rollBack(); throw $e; }
            audit((int)$u['id'],'user_update',(string)$id);redirect('users','Uživatel upraven.');
        }
        if($action==='unit_user_remove') {
            admin_only($u);$id=(int)($_POST['unit_id']??0);$uid=(int)($_POST['user_id']??0);
            sql('DELETE FROM unit_users WHERE unit_id=? AND user_id=?',[$id,$uid]);
            audit((int)$u['id'],'unit_user_remove',$id.':'.$uid);redirect('users','Přístup odebrán.');
        }
        if($action==='building_manager_add') {
            admin_only($u); $id=(int)($_POST['building_id']??0); $uid=(int)($_POST['user_id']??0);
            if(!one('SELECT id FROM buildings WHERE id=?',[$id])) throw new RuntimeException('Objekt neexistuje.');
            $target=one('SELECT id,role FROM users WHERE id=?',[$uid]);
            if(!$target || $target['role']!=='manager') throw new RuntimeException('Přístup k objektu lze přiřadit jen uživateli s rolí Správce objektu.');
            sql('INSERT IGNORE INTO building_managers(building_id,user_id) VALUES(?,?)',[$id,$uid]);
            audit((int)$u['id'],'building_manager_add',$id.':'.$uid);
            redirect('users','Správce přiřazen k objektu.');
        }
        if($action==='building_manager_remove') {
            admin_only($u); $id=(int)($_POST['building_id']??0); $uid=(int)($_POST['user_id']??0);
            sql('DELETE FROM building_managers WHERE building_id=? AND user_id=?',[$id,$uid]);
            audit((int)$u['id'],'building_manager_remove',$id.':'.$uid);
            redirect('users','Přístup správce k objektu odebrán.');
        }
        if($action==='unit_user') {
            admin_only($u);$id=(int)($_POST['unit_id']??0);$uid=(int)($_POST['user_id']??0);
            if(!one('SELECT id FROM units WHERE id=?',[$id]) || !one('SELECT id FROM users WHERE id=?',[$uid])) throw new RuntimeException('Neplatný uživatel/jednotka.');
            sql('INSERT IGNORE INTO unit_users(unit_id,user_id) VALUES(?,?)',[$id,$uid]);
            audit((int)$u['id'],'unit_user',$id.':'.$uid);redirect('users','Oprávnění přiřazeno.');
        }
        if($action==='meter_create') {
            admin_only($u);$building=(int)($_POST['building_id']??0);$unit=(int)($_POST['unit_id']??0);$parent=(int)($_POST['parent_id']??0);
            $type=(string)($_POST['type']??'');$name=trim((string)($_POST['name']??''));
            if($name==='' || !isset(energy_labels()[$type]) || !one('SELECT 1 FROM buildings WHERE id=?',[$building])) throw new RuntimeException('Název, druh energie nebo objekt je neplatný.');
            if($unit && !one('SELECT 1 FROM units WHERE id=? AND building_id=?',[$unit,$building])) throw new RuntimeException('Jednotka musí být ve stejném objektu.');
            if($parent && !one('SELECT 1 FROM meters WHERE id=? AND building_id=? AND type=?',[$parent,$building,$type])) throw new RuntimeException('Nadřazené měřidlo musí být v témže objektu a stejného druhu.');
            $coef=valid_factor((string)($_POST['coefficient']??'1'));
            db()->beginTransaction();
            try {
                sql('INSERT INTO meters(building_id,unit_id,parent_id,type,name,serial,location) VALUES (?,?,?,?,?,?,?)',[$building,$unit?:null,$parent?:null,$type,$name,trim((string)($_POST['serial']??'')),trim((string)($_POST['location']??''))]);
                $mid=(int)db()->lastInsertId();sql('INSERT INTO meter_coefficients(meter_id,effective_from,coefficient) VALUES(?,?,?)',[$mid,'1900-01-01',$coef]);
                db()->commit();
            } catch(Throwable $e) {db()->rollBack();throw $e;}
            audit((int)$u['id'],'meter_create',$name);redirect('meters','Měřidlo přidáno.');
        }
        if($action==='meter_active') {
            admin_only($u);$id=(int)($_POST['meter_id']??0);$m=one('SELECT active FROM meters WHERE id=?',[$id]);
            if(!$m) throw new RuntimeException('Měřidlo neexistuje.');
            sql('UPDATE meters SET active=? WHERE id=?',[(int)!$m['active'],$id]);
            audit((int)$u['id'],'meter_active',(string)$id);redirect('meters','Stav měřidla změněn.');
        }
        if($action==='coefficient_add') {
            admin_only($u);$id=(int)($_POST['meter_id']??0);
            if(!one('SELECT id FROM meters WHERE id=?',[$id])) throw new RuntimeException('Měřidlo neexistuje.');
            $date=valid_day((string)($_POST['effective_from']??''));$factor=valid_factor((string)($_POST['coefficient']??''));
            sql('INSERT INTO meter_coefficients(meter_id,effective_from,coefficient) VALUES(?,?,?)',[$id,$date,$factor]);
            audit((int)$u['id'],'coefficient_add',$id.':'.$date.'='.$factor);redirect('meters','Koeficient přidán.');
        }
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
        if($action==='deploy') {
            admin_only($u);
            $result=(new Energy\Updater(APP_ROOT))->deploy();
            audit((int)$u['id'],'deploy',(string)($result['tag']??'unknown'));
            redirect('update',$result['status']==='updated'?'Aktualizováno na '.$result['tag'].'.':'Je již nainstalována aktuální verze.');
        }
        throw new RuntimeException('Neznámá akce.');
    } catch(Throwable $e) {
        if(!($e instanceof RuntimeException)) error_log((string)$e);
        $msg=($e instanceof PDOException)?'Databázová operace se nezdařila (např. duplicitní údaj).':$e->getMessage();
        $_SESSION['flash']='Chyba: '.$msg;
        if($page==='login') redirect('login');
        if($page==='photo_review') { header('Location: ?page=photo_review&id='.(int)($_POST['photo_id']??0));exit; }
        redirect(in_array($page,['users','units','meters','readings','buildings','update'],true)?$page:'dashboard');
    }
}

if($page==='login') {
    if(($existing=current_user()) && (int)$existing['is_active']) redirect('dashboard');
    page_header('Přihlášení');
    echo '<section class="card narrow"><form method="post">'.post_token().'<label>E-mail <input type="email" name="email" required autocomplete="username"></label><label>Heslo <input type="password" name="password" required autocomplete="current-password"></label><button>Přihlásit se</button></form><p class="muted">Prvního administrátora vytvořte pomocí bin/create-admin.php.</p></section>';
    page_footer();exit;
}
$u=require_login();
if($page==='export') {
    $from=valid_day((string)($_GET['from']??''));$to=valid_day((string)($_GET['to']??''));
    if($from>=$to) exit('Datum od musí být menší než datum do.');
    $result=Energy\Consumption::fromDatabase(db(),$from,$to);$units=unit_scope($u);$labels=energy_labels();
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="spotreby.csv"');
    echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['Období od','Období do','Objekt','Jednotka','Energie','Spotřeba','Jednotka měření','Původ výsledku'],';');
    foreach($units as $unit) foreach(($result['allocations'][(int)$unit['id']]??[]) as $type=>$v)
        fputcsv($out,[$from,$to,csv_cell((string)$unit['building_name']),csv_cell((string)$unit['name']),csv_cell((string)($labels[$type]??$type)),str_replace('.',',',(string)$v),in_array($type,['electricity'])?'kWh':'m³',!empty($result['estimated'][(int)$unit['id']][$type])?'Odhad':'Skutečné hranice odečtů'],';');
    fclose($out);exit;
}

if($page==='dashboard') {
    page_header('Přehled',$u);
    $units=unit_scope($u); $meters=meter_scope($u); $n=count($units);
    $unitIds=array_map(static function ($x) { return (int)$x['id']; },$units);
    $meterIds=array_map(static function ($x) { return (int)$x['id']; },$meters);
    $readings=[];
    if ($meterIds) {
        $placeholders=implode(',',array_fill(0,count($meterIds),'?'));
        $readings=all('SELECT r.reading_date,r.value,r.source,m.name,m.type FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$placeholders.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 6',$meterIds);
    }
    echo '<div class="dashboard-welcome"><div><span class="welcome-eyebrow">VÁŠ ENERGETICKÝ PŘEHLED</span><h2>Dobrý den, '.html($u['name']).' <span class="welcome-wave" aria-hidden="true">✦</span></h2><p>Aktuální přehled vašich objektů, měřidel a spotřeby.</p></div><a class="button welcome-action" href="?page=readings">'.ui_icon('plus').' Nový odečet</a></div>';
    echo '<div class="stats"><section class="card stat-card stat-primary"><span class="stat-icon">'.ui_icon('units').'</span><small>'.(is_admin($u)?'Všechny jednotky':(is_manager($u)?'Spravované jednotky':'Moje jednotky')).'</small><strong>'.(int)$n.'</strong><span class="stat-note">Byty a prostory s přístupem</span></section>';
    echo '<section class="card stat-card"><span class="stat-icon stat-violet">'.ui_icon('meters').'</span><small>Dostupná měřidla</small><strong>'.count($meters).'</strong><span class="stat-note">Hlavní i podružná</span></section>';
    echo '<section class="card stat-card"><span class="stat-icon stat-cyan">'.ui_icon('readings').'</span><small>Poslední odečty</small><strong>'.count($readings).'</strong><span class="stat-note">Až 6 posledních záznamů</span></section>';
    echo '<section class="card stat-card"><span class="stat-icon stat-green">'.ui_icon('shield').'</span><small>Oprávnění</small><strong class="stat-role">'.html(role_label($u['role'])).'</strong><span class="stat-note">Vaše aktuální role</span></section></div>';

    // Spotřeby jsou vyhodnocené serverem podle přístupných jednotek. Žádné cizí odečty zde nezobrazujeme.
    $report=null;
    if ($unitIds && $meterIds) {
        try {
            $from=date('Y-m-01',strtotime('first day of -5 months'));
            $to=date('Y-m-d');
            if ($from<$to) $report=Energy\Consumption::report(db(),$from,$to);
        } catch(Throwable $e) { error_log('Dashboard consumption summary: '.$e->getMessage()); }
    }
    $overviewTypes=['electricity'=>['Elektřina','bolt','kWh'],'gas'=>['Plyn','flame','m³'],'cold_water'=>['Studená voda','water','m³'],'hot_water'=>['Teplá voda','water','m³']];
    $currentPeriod=null;
    if ($report) foreach($report['periods'] as $period) $currentPeriod=$period;
    echo '<section class="card dashboard-energy"><div class="section-head"><div><span class="eyebrow">AKTUÁLNÍ SPOTŘEBA</span><h2>Spotřeba za aktuální měsíc</h2><p class="muted">Součet dostupných jednotek. Neúplná data jsou označena.</p></div><a href="?page=consumption" class="text-action">Podrobné grafy '.ui_icon('arrow').'</a></div><div class="energy-tiles">';
    foreach($overviewTypes as $type=>$def) {
        $total=0.0;$found=false;$estimated=false;
        if($currentPeriod) foreach($unitIds as $unitId) {
            if(isset($currentPeriod['result']['allocations'][$unitId][$type])) {
                $total+=(float)$currentPeriod['result']['allocations'][$unitId][$type]; $found=true;
                if(!empty($currentPeriod['result']['estimated'][$unitId][$type])) $estimated=true;
            }
        }
        echo '<div class="energy-tile"><span class="energy-icon energy-'.html($type).'">'.ui_icon($def[1]).'</span><span class="energy-label">'.html($def[0]).'</span><div class="energy-reading">'.($found?html(fmt($total)):'—').' <small>'.html($def[2]).'</small></div><span class="energy-foot">'.($found?($estimated?'Odhad dle odečtů':'Dle odečtů'):'Zatím bez údajů').'</span></div>';
    }
    echo '</div></section>';
    echo '<div class="dashboard-columns"><section class="card dashboard-trend"><div class="section-head"><div><span class="eyebrow">VÝVOJ SPOTŘEBY</span><h2>Posledních šest měsíců</h2></div></div>';
    if($report && $unitIds) {
        $first=$unitIds[0]; $shown=null;
        foreach (array_keys($overviewTypes) as $type) {
            foreach($report['periods'] as $period) {
                if(isset($period['result']['allocations'][$first][$type])) {$shown=$type;break 2;}
            }
        }
        if($shown!==null) {
            $firstUnitName=(string)$units[0]['name'];
            echo '<p class="muted dashboard-trend-label">'.html($firstUnitName).' · '.html($overviewTypes[$shown][0]).'</p>';
            render_consumption_chart($report['periods'],$first,$shown);
        } else echo '<div class="empty-state">'.ui_icon('chart').'<p>Pro zobrazení grafu zatím chybí odečty.</p><a href="?page=readings">Zadat první odečet</a></div>';
    } else echo '<div class="empty-state">'.ui_icon('chart').'<p>Zatím nejsou dostupná data spotřeby.</p><a href="?page=readings">Zadat odečet</a></div>';
    echo '</section><section class="card dashboard-activity"><div class="section-head"><div><span class="eyebrow">AKTIVITA</span><h2>Poslední odečty</h2></div><a class="text-action" href="?page=readings">Všechny '.ui_icon('arrow').'</a></div>';
    if ($readings) {
        echo '<div class="recent-readings">';
        foreach($readings as $reading) {
            echo '<div class="recent-reading"><span class="reading-dot">'.ui_icon($reading['type']==='electricity'?'bolt':'water').'</span><div class="reading-detail"><strong>'.html($reading['name']).'</strong><small>'.html(date('d. m. Y',strtotime($reading['reading_date']))).' · '.html(reading_source_label($reading['source'])).'</small></div><span class="reading-number">'.html(fmt((float)$reading['value'])).'</span></div>';
        }
        echo '</div>';
    } else echo '<div class="empty-state">'.ui_icon('readings').'<p>Dosud žádné odečty.</p><a href="?page=readings">Přidat odečet</a></div>';
    echo '</section></div>';
    echo '<section class="card dashboard-links"><div class="section-head"><div><span class="eyebrow">RYCHLÉ AKCE</span><h2>Co potřebujete udělat?</h2></div></div><div class="quick-links"><a href="?page=readings">'.ui_icon('camera').'<span><strong>Zapsat odečet</strong><small>Ručně nebo fotografií</small></span>'.ui_icon('arrow').'</a><a href="?page=consumption">'.ui_icon('chart').'<span><strong>Prohlédnout spotřeby</strong><small>Grafy a přehledy jednotek</small></span>'.ui_icon('arrow').'</a><a href="?page=meters">'.ui_icon('meters').'<span><strong>Seznam měřidel</strong><small>Všechna dostupná měřidla</small></span>'.ui_icon('arrow').'</a></div></section>';
    page_footer();exit;
}
if($page==='buildings') {
    admin_only($u);page_header('Objekty',$u);
    echo '<section class="card"><h2>Nový objekt</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_create"><label>Název <input name="name" required></label><label>Adresa <input name="address"></label><button>Přidat objekt</button></form></section>';
    echo '<section class="card"><h2>Seznam objektů</h2><table><tr><th>Název</th><th>Adresa</th></tr>';
    foreach(all('SELECT * FROM buildings ORDER BY name') as $b) echo '<tr><td>'.html($b['name']).'</td><td>'.html($b['address']).'</td></tr>';
    echo '</table></section>';page_footer();exit;
}
if($page==='units') {
    page_header('Byty a jednotky',$u);$units=unit_scope($u);
    if(is_admin($u)) {
        $opts=[];foreach(all('SELECT id,name FROM buildings ORDER BY name') as $b) $opts[$b['id']]=$b['name'];
        echo '<section class="card"><h2>Nová jednotka</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_create"><label>Objekt '.form_select('building_id',$opts).'</label><label>Název <input name="name" required placeholder="Byt 1"></label><label>Typ '.form_select('kind',['apartment'=>'Byt','common'=>'Společné prostory']).'</label><label>Odpadní voda '.form_select('wastewater_mode',['none'=>'Bez automatického výpočtu','sum_water'=>'Studená + teplá voda','metered'=>'Vlastní měřidlo']).'</label><button>Přidat</button></form></section>';
    }
    echo '<section class="card"><h2>Přiřazené jednotky</h2><table><tr><th>Objekt</th><th>Jednotka</th><th>Typ</th><th>Odpadní voda</th></tr>';
    foreach($units as $unit) {
        echo '<tr><td>'.html($unit['building_name']).'</td><td>'.html($unit['name']).'</td><td>'.html(unit_kind_label($unit['kind'])).'</td><td>';
        if(is_admin($u)) echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_mode"><input type="hidden" name="unit_id" value="'.(int)$unit['id'].'">'.form_select('wastewater_mode',['none'=>'Bez výpočtu','sum_water'=>'SV + TV','metered'=>'Vlastní měřidlo'],$unit['wastewater_mode']).'<button class="small">Uložit</button></form>';
        else echo html(wastewater_mode_label($unit['wastewater_mode']));
        echo '</td></tr>';
    }
    echo '</table></section>';page_footer();exit;
}
if($page==='users') {
    admin_only($u);page_header('Uživatelé',$u);
    $users=all('SELECT * FROM users ORDER BY name');$units=unit_scope($u);
    $roleOptions=['user'=>'Uživatel','manager'=>'Správce objektu','admin'=>'Administrátor'];
    echo '<section class="card"><h2>Nový uživatel</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_create"><label>Jméno <input name="name" required></label><label>E-mail <input type="email" name="email" required></label><label>Heslo (12+ znaků) <input type="password" name="password" minlength="12" required></label><label>Role '.form_select('role',$roleOptions).'</label><button>Vytvořit</button></form></section>';
    echo '<section class="card"><h2>Přiřadit přístup k jednotce</h2><p class="muted">Uživatel uvidí měřidla přidělená této jednotce, včetně hlavního měřidla s podružnými měřidly. Odečty cizích měřidel neuvidí.</p><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_user"><label>Uživatel ';
    $opts=[];foreach($users as $x) $opts[$x['id']]=$x['name'].' ('.$x['email'].')';
    echo form_select('user_id',$opts).'</label><label>Jednotka '.form_select('unit_id',unit_options($units)).'</label><button>Přiřadit</button></form></section>';

    $buildings=all('SELECT id,name FROM buildings ORDER BY name');$buildingOptions=[];
    foreach($buildings as $b) $buildingOptions[$b['id']]=$b['name'];
    $managers=array_values(array_filter($users,static function ($x) { return $x['role'] === 'manager'; }));
    $managerOptions=[];foreach($managers as $x) $managerOptions[$x['id']]=$x['name'].' ('.$x['email'].')';
    echo '<section class="card"><h2>Správci objektů</h2><p class="muted">Správce vidí všechna měřidla a odečty přiřazených objektů a může zadávat ruční i fotografické odečty. Nemá právo měnit uživatele, objekty, nastavení měřidel ani koeficienty.</p>';
    if($managerOptions && $buildingOptions) {
        echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_manager_add"><label>Správce '.form_select('user_id',$managerOptions).'</label><label>Objekt '.form_select('building_id',$buildingOptions).'</label><button>Přiřadit objekt</button></form>';
    } else echo '<p class="muted">Nejdříve založte (nebo upravte) uživatele s rolí Správce objektu a vytvořte objekt.</p>';
    $managed=all('SELECT bm.building_id,bm.user_id,u.name AS uname,b.name AS bname FROM building_managers bm JOIN users u ON u.id=bm.user_id JOIN buildings b ON b.id=bm.building_id ORDER BY b.name,u.name');
    echo '<table><tr><th>Správce</th><th>Objekt</th><th>Akce</th></tr>';
    foreach($managed as $access) {
        echo '<tr><td>'.html($access['uname']).'</td><td>'.html($access['bname']).'</td><td><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="building_manager_remove"><input type="hidden" name="user_id" value="'.(int)$access['user_id'].'"><input type="hidden" name="building_id" value="'.(int)$access['building_id'].'"><button class="small secondary" onclick="return confirm(&quot;Odebrat správci přístup k objektu?&quot;)">Odebrat přístup</button></form></td></tr>';
    }
    echo '</table></section>';

    echo '<section class="card"><h2>Seznam uživatelů a jejich jednotek</h2><p class="muted">Přístup k jednotlivé jednotce odeberete přímo u uživatele tlačítkem Odebrat.</p><table><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Přístupy k jednotkám</th><th>Stav</th></tr>';
    foreach($users as $x) {
        $owned=all('SELECT u.id,u.name,b.name AS building_name FROM units u JOIN unit_users a ON a.unit_id=u.id JOIN buildings b ON b.id=u.building_id WHERE a.user_id=? ORDER BY b.name,u.name',[$x['id']]);
        echo '<tr><td>'.html($x['name']).'</td><td>'.html($x['email']).'</td><td>'.html(role_label($x['role'])).'</td><td>';
        if(!$owned) echo '<span class="muted">Bez jednotky</span>';
        foreach($owned as $unit) {
            echo '<form method="post" class="inline access-entry">'.post_token().'<input type="hidden" name="action" value="unit_user_remove"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><input type="hidden" name="unit_id" value="'.(int)$unit['id'].'"><span>'.html($unit['building_name'].' / '.$unit['name']).'</span><button class="small secondary" onclick="return confirm(&quot;Odebrat přístup k jednotce?&quot;)">Odebrat</button></form>';
        }
        echo '</td><td><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_toggle"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><button class="small secondary">'.($x['is_active']?'Aktivní – deaktivovat':'Neaktivní – aktivovat').'</button></form></td></tr>';
    }
    echo '</table></section><section class="card"><h2>Upravit uživatele</h2><p class="muted">Heslo nechte prázdné, pokud jej nechcete měnit. Při odebrání role správce se odstraní také jeho přiřazení k objektům.</p><div class="user-edit-grid">';
    foreach($users as $x) {
        echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_update"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><label>Jméno <input name="name" value="'.html($x['name']).'" required></label><label>Role '.form_select('role',$roleOptions,$x['role']).'</label><label>Nové heslo <input name="password" type="password" minlength="12" autocomplete="new-password"></label><button>Upravit '.html($x['email']).'</button></form><hr>';
    }
    echo '</div></section>';page_footer();exit;
}
if($page==='meters') {
    page_header('Měřidla',$u);$meters=meter_scope($u);
    if(is_admin($u)) {
        $buildings=[];foreach(all('SELECT id,name FROM buildings ORDER BY name') as $b) $buildings[$b['id']]=$b['name'];
        $units=unit_scope($u);$uopts=unit_options($units);$mopts=[];foreach($meters as $m) $mopts[$m['id']]=$m['building_name'].' / '.$labels[$m['type']].' / '.$m['name'];
        echo '<section class="card"><h2>Nové měřidlo</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_create"><label>Objekt '.form_select('building_id',$buildings).'</label><label>Energie '.form_select('type',$labels).'</label><label>Název <input name="name" required></label><label>Sériové číslo <input name="serial"></label><label>Umístění <input name="location"></label><label>Jednotka (zbytková spotřeba) '.form_select('unit_id',$uopts,null,true).'</label><label>Nadřazené měřidlo '.form_select('parent_id',$mopts,null,true).'</label><label>Koeficient <input name="coefficient" value="1.0000" required inputmode="decimal"></label><button>Založit měřidlo</button></form><p class="muted">Nadřazené měřidlo musí být ve stejném objektu a se stejným typem energie. Jednotka určuje, komu připadne spotřeba po odečtení podřízených měřidel.</p></section>';
        echo '<section class="card"><h2>Změna koeficientu</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="coefficient_add"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Platnost od <input type="date" name="effective_from" value="'.date('Y-m-d').'" required></label><label>Nový koeficient <input name="coefficient" inputmode="decimal" placeholder="1,0200" required></label><button>Uložit nový koeficient</button></form></section>';
    }
    echo '<section class="card"><h2>Seznam měřidel</h2><table><tr><th>Objekt / měřidlo</th><th>Energie</th><th>Nadřazené</th><th>Spotřeba patří</th><th>Koeficient nyní</th><th>Stav</th></tr>';
    foreach($meters as $m) {
        $coef=one('SELECT coefficient FROM meter_coefficients WHERE meter_id=? AND effective_from<=CURRENT_DATE ORDER BY effective_from DESC LIMIT 1',[$m['id']]);
        echo '<tr><td><strong>'.html($m['building_name']).' / '.html($m['name']).'</strong><small>'.html($m['serial']).' · '.html($m['location']).'</small></td><td>'.html($labels[$m['type']]).'</td><td>'.html($m['parent_name']??'—').'</td><td>'.html($m['unit_name']??'nepřiřazeno').'</td><td>'.html($coef['coefficient']??'1').'</td><td>';
        if(is_admin($u)) echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="meter_active"><input type="hidden" name="meter_id" value="'.(int)$m['id'].'"><button class="small secondary">'.($m['active']?'Aktivní':'Neaktivní').'</button></form>';
        else echo $m['active']?'Aktivní':'Neaktivní';
        echo '</td></tr>';
    }
    echo '</table></section>';page_footer();exit;
}
if($page==='readings') {
    page_header('Odečty',$u);$meters=meter_scope($u);$mopts=[];foreach($meters as $m) if($m['active'])$mopts[$m['id']]=$m['name'].' · '.$labels[$m['type']];
    echo '<section class="card"><h2>Ručně zadat odečet</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="reading_add"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Datum odečtu <input type="date" name="reading_date" value="'.date('Y-m-d').'" required></label><label>Stav číselníku <input name="value" inputmode="decimal" required placeholder="1234,567"></label><button>Uložit</button></form></section>';
    echo '<section class="card"><h2>Vyfotit měřidlo telefonem</h2><p class="muted">Otevřete tuto stránku na mobilu přes HTTPS. Po vyfotografování server navrhne hodnotu (pokud je nastaven AI klíč); návrh vždy před uložením zkontrolujete.</p><form method="post" enctype="multipart/form-data" class="inline">'.post_token().'<input type="hidden" name="action" value="photo_upload"><label>Měřidlo '.form_select('meter_id',$mopts).'</label><label>Fotografie <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required></label><button>Nahrát a rozpoznat</button></form></section>';
    echo '<section class="card"><h2>Poslední odečty</h2><table><tr><th>Datum</th><th>Měřidlo</th><th>Stav</th><th>Zdroj</th></tr>';
    $ids=array_map(static function ($m) { return (int)$m['id']; },$meters);
    if($ids) {
        $param=implode(',',array_fill(0,count($ids),'?'));
        foreach(all('SELECT r.*,m.name FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$param.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 100',$ids) as $r)
            echo '<tr><td>'.html($r['reading_date']).'</td><td>'.html($r['name']).'</td><td>'.html($r['value']).'</td><td>'.html(reading_source_label($r['source'])).($r['photo_id']?' · <a href="?page=photo_review&id='.(int)$r['photo_id'].'">foto</a>':'').'</td></tr>';
    }
    echo '</table></section>';page_footer();exit;
}
if($page==='photo_review') {
    $p=photo_for((int)($_GET['id']??0),$u);page_header('Kontrola odečtu z fotografie',$u);
    echo '<section class="card narrow"><h2>'.html($p['meter_name']).'</h2><img class="meter-photo" src="?page=photo_file&amp;id='.(int)$p['id'].'" alt="Fotografie měřidla"><p class="muted">Návrh AI: '.html($p['suggestion_note']).'. Rozpoznaný údaj není automaticky potvrzen.</p>';
    if($p['status']==='pending') {
        echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_confirm"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><label>Datum <input type="date" name="reading_date" value="'.date('Y-m-d').'" required></label><label>Ověřený stav měřidla ('.html($labels[$p['meter_type']]).') <input name="value" required inputmode="decimal" value="'.html($p['suggested_value']??'').'"></label><button>Potvrdit a uložit odečet</button></form>';
        echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_reject"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><button class="secondary">Odmítnout návrh</button></form>';
    } else echo '<p>Stav fotografie: '.html(photo_status_label($p['status'])).'</p>';
    echo '</section>';page_footer();exit;
}
if($page==='consumption') {
    $from=(string)($_GET['from']??date('Y-01-01'));
    $to=(string)($_GET['to']??date('Y-m-d'));
    $units=unit_scope($u);
    $availableUnits=[];
    foreach ($units as $unit) $availableUnits[(int)$unit['id']]=$unit;
    $selectedId=(int)($_GET['unit_id']??(array_key_first($availableUnits)??0));
    $energy=(string)($_GET['energy']??'all');
    page_header('Vývoj a výpočet spotřeby',$u);
    echo '<section class="card"><h2>Vybrat období a jednotku</h2><form method="get" class="inline"><input type="hidden" name="page" value="consumption"><label>Období od <input type="date" name="from" value="'.html($from).'" required></label><label>Období do <input type="date" name="to" value="'.html($to).'" required></label>';
    $options=[];
    foreach ($units as $unit) $options[$unit['id']]=$unit['building_name'].' / '.$unit['name'];
    if ($options) echo '<label>Jednotka '.form_select('unit_id',$options,$selectedId).'</label>';
    echo '<label>Graf energie '.form_select('energy',['all'=>'Všechny energie']+$labels,$energy).'</label><button>Zobrazit spotřebu</button></form>';
    echo '<p class="muted">Datum nemusí odpovídat dni odečtu. V takovém případě systém použije poměrný odhad podle sousedních odečtů. Pokud chybí podklady, výsledek nevypočítá.</p></section>';
    try {
        valid_day($from);valid_day($to);
        if($from>=$to) throw new RuntimeException('Datum konce musí být pozdější než počátek.');
        if($selectedId===0 || !isset($availableUnits[$selectedId])) throw new RuntimeException('Vyberte jednotku, ke které máte přístup.');
        if($energy!=='all' && !array_key_exists($energy,$labels)) throw new RuntimeException('Neplatný druh energie.');
        $report=Energy\Consumption::report(db(),$from,$to);
        $result=$report['overall'];
        $unit=$availableUnits[$selectedId];
        $unitName=$unit['building_name'].' / '.$unit['name'];
        echo '<section class="card"><div class="section-head"><h2>Celková spotřeba: '.html($unitName).'</h2><a class="button secondary" href="?page=export&amp;from='.html(rawurlencode($from)).'&amp;to='.html(rawurlencode($to)).'">Export CSV všech dostupných jednotek</a></div>';
        echo '<table><tr><th>Energie</th><th>Spotřeba za období</th><th>Výpočet</th></tr>';
        $any=false;
        foreach (($result['allocations'][$selectedId]??[]) as $type=>$value) {
            $any=true;
            $estimated=!empty($result['estimated'][$selectedId][$type]);
            echo '<tr><td>'.html($labels[$type]??$type).'</td><td><strong>'.html(fmt((float)$value)).'</strong> '.($type==='electricity'?'kWh':'m³').'</td><td>'.($estimated?'<span class="estimate-pill">Odhad</span>':'<span class="actual-pill">Podle odečtů</span>').'</td></tr>';
        }
        if (!$any) echo '<tr><td colspan="3">K tomuto období není možné vypočítat spotřebu. Zkontrolujte dostupné odečty.</td></tr>';
        echo '</table></section>';
        $shownEnergies=$energy==='all'?array_keys($result['allocations'][$selectedId]??[]):[$energy];
        if(!$shownEnergies) echo '<section class="card"><p class="muted">Zatím není možné vykreslit žádný graf spotřeby. Zkontrolujte odečty.</p></section>';
        foreach($shownEnergies as $shownType) {
            echo '<section class="card"><h2>Graf vývoje – '.html($labels[$shownType]??$shownType).'</h2>';
            render_consumption_chart($report['periods'],$selectedId,$shownType);
            echo '</section>';
        }
        echo '<section class="card"><h2>Vývoj po obdobích – '.html($unitName).'</h2><table><tr><th>Období od</th><th>Do</th><th>Energie</th><th>Spotřeba</th><th>Výpočet</th></tr>';
        $rows=0;
        foreach ($report['periods'] as $period) foreach(($period['result']['allocations'][$selectedId]??[]) as $type=>$value) {
            $rows++;
            $estimated=!empty($period['result']['estimated'][$selectedId][$type]);
            echo '<tr><td>'.html($period['from']).'</td><td>'.html($period['to']).'</td><td>'.html($labels[$type]??$type).'</td><td>'.html(fmt((float)$value)).' '.($type==='electricity'?'kWh':'m³').'</td><td>'.($estimated?'Odhad':'Podle odečtů').'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">Žádné vypočitatelné období.</td></tr>';
        echo '</table></section>';
        if(is_admin($u) && $result['warnings']) {
            echo '<section class="card warning"><h2>Upozornění při výpočtu</h2><ul>';
            foreach (array_unique($result['warnings']) as $warning) echo '<li>'.html($warning).'</li>';
            echo '</ul></section>';
        }
        if(!is_admin($u) && !is_manager($u)) echo '<p class="muted">Zobrazeny jsou pouze spotřeby vašich jednotek. Odečty cizích měřidel nejsou zpřístupněny.</p>';
        if(is_manager($u)) echo '<p class="muted">Zobrazeny jsou spotřeby spravovaných objektů. Měřidla a koeficienty nastavuje administrátor.</p>';
    } catch(Throwable $e) {echo '<p class="flash">'.html($e->getMessage()).'</p>';}
    page_footer();exit;
}
if($page==='update') {
    admin_only($u);page_header('Aktualizace GitHub Releases',$u);
    echo '<section class="card"><p>Lokální verze: <strong>'.html(trim((string)@file_get_contents(APP_ROOT.'/VERSION'))).'</strong></p>';
    try {
        $upd=new Energy\Updater(APP_ROOT);$release=$upd->latest();
        echo '<p>Poslední GitHub Release: <strong>'.html($release['tag']).'</strong></p><p>'.($upd->isNewer($release)?'Je dostupná nová verze.':'Aktuální verze je již nainstalována.').'</p><details><summary>Release notes</summary><pre class="notes">'.html($release['notes']).'</pre></details>';
        if($upd->isNewer($release)) echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="deploy"><p class="muted">Před aktualizací zazálohujte databázi MariaDB. Skript uloží zálohu měněných PHP souborů, ověří SHA-256 a spustí migrace.</p><button>Nasadit '.html($release['tag']).'</button></form>';
    } catch(Throwable $e) { echo '<p class="flash">'.html($e->getMessage()).'</p>'; }
    echo '</section>';page_footer();exit;
}
http_response_code(404);page_header('Stránka nenalezena',$u);page_footer();
