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
    $photo=one('SELECT p.*,m.name AS meter_name,m.type AS meter_type,m.unit_id,m.id AS meter_id FROM reading_photos p JOIN meters m ON m.id=p.meter_id WHERE p.id=?',[$id]);
    if(!$photo || (!is_admin($user) && ((int)$photo['user_id'] !== (int)$user['id'] || !can_read_meter($user,$photo)))) {
        http_response_code(403); throw new RuntimeException('Fotografie není dostupná.');
    }
    return $photo;
}
function unit_scope(array $user): array {
    if(is_admin($user)) return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id ORDER BY b.name,u.name');
    return all('SELECT u.*,b.name AS building_name FROM units u JOIN buildings b ON b.id=u.building_id JOIN unit_users au ON au.unit_id=u.id WHERE au.user_id=? ORDER BY b.name,u.name',[$user['id']]);
}
function meter_scope(array $user,bool $readable=false): array {
    $meters=all('SELECT m.*,b.name AS building_name,u.name AS unit_name,p.name AS parent_name FROM meters m JOIN buildings b ON b.id=m.building_id LEFT JOIN units u ON u.id=m.unit_id LEFT JOIN meters p ON p.id=m.parent_id ORDER BY b.name,m.type,m.parent_id,m.name');
    if(is_admin($user)) return $meters;
    return array_values(array_filter($meters, static fn($m)=> $m['unit_id'] && can_read_meter($user,$m)));
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
            $now=time();$attempts=array_filter($_SESSION['attempts']??[],static fn($t)=>$now-$t<600);
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
            if(!filter_var($email,FILTER_VALIDATE_EMAIL) || $name==='' || strlen($password)<12 || !in_array($role,['user','admin'],true)) throw new RuntimeException('Zadejte jméno, platný e-mail a heslo alespoň 12 znaků.');
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
            if($name==='' || !in_array($role,['admin','user'],true) || ($password!=='' && strlen($password)<12))
                throw new RuntimeException('Neplatné jméno, role nebo heslo kratší než 12 znaků.');
            if($id===(int)$u['id'] && $role!=='admin') throw new RuntimeException('Nelze odebrat vlastní administrátorskou roli.');
            if($target['role']==='admin' && $target['is_active'] && $role!=='admin') {
                $count=one("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1");
                if((int)$count['c']<=1) throw new RuntimeException('Posledního administrátora nelze změnit na uživatele.');
            }
            if($password==='') sql('UPDATE users SET name=?,role=? WHERE id=?',[$name,$role,$id]);
            else sql('UPDATE users SET name=?,role=?,password_hash=? WHERE id=?',[$name,$role,password_hash($password,PASSWORD_DEFAULT),$id]);
            audit((int)$u['id'],'user_update',(string)$id);redirect('users','Uživatel upraven.');
        }
        if($action==='unit_user_remove') {
            admin_only($u);$id=(int)($_POST['unit_id']??0);$uid=(int)($_POST['user_id']??0);
            sql('DELETE FROM unit_users WHERE unit_id=? AND user_id=?',[$id,$uid]);
            audit((int)$u['id'],'unit_user_remove',$id.':'.$uid);redirect('users','Přístup odebrán.');
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
    echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['Období od','Období do','Objekt','Jednotka','Energie','Spotřeba','Jednotka měření'],';');
    foreach($units as $unit) foreach(($result['allocations'][(int)$unit['id']]??[]) as $type=>$v)
        fputcsv($out,[$from,$to,csv_cell((string)$unit['building_name']),csv_cell((string)$unit['name']),csv_cell((string)($labels[$type]??$type)),str_replace('.',',',(string)$v),in_array($type,['electricity'])?'kWh':'m³'],';');
    fclose($out);exit;
}

if($page==='dashboard') {
    page_header('Přehled',$u);
    $units=unit_scope($u);$meters=meter_scope($u);$n=count($units);
    echo '<div class="stats"><section class="card"><small>Moje jednotky</small><strong>'.$n.'</strong></section><section class="card"><small>Viditelná měřidla</small><strong>'.count($meters).'</strong></section><section class="card"><small>Uživatelská role</small><strong>'.html($u['role']).'</strong></section></div>';
    echo '<section class="card"><h2>Rychlé akce</h2><p><a class="button" href="?page=readings">Zapsat odečet / vyfotit měřidlo</a> <a class="button secondary" href="?page=consumption">Vypočítat spotřebu</a></p><p class="muted">Výsledky jsou založené na odečtech k přesným hranicím období a platných koeficientech.</p></section>';
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
        echo '<tr><td>'.html($unit['building_name']).'</td><td>'.html($unit['name']).'</td><td>'.html($unit['kind']).'</td><td>';
        if(is_admin($u)) echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_mode"><input type="hidden" name="unit_id" value="'.(int)$unit['id'].'">'.form_select('wastewater_mode',['none'=>'Bez výpočtu','sum_water'=>'SV + TV','metered'=>'Vlastní měřidlo'],$unit['wastewater_mode']).'<button class="small">Uložit</button></form>';
        else echo html($unit['wastewater_mode']);
        echo '</td></tr>';
    }
    echo '</table></section>';page_footer();exit;
}
if($page==='users') {
    admin_only($u);page_header('Uživatelé',$u);$users=all('SELECT * FROM users ORDER BY name');$units=unit_scope($u);
    echo '<section class="card"><h2>Nový uživatel</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_create"><label>Jméno <input name="name" required></label><label>E-mail <input type="email" name="email" required></label><label>Heslo (12+ znaků) <input type="password" name="password" minlength="12" required></label><label>Role '.form_select('role',['user'=>'Uživatel','admin'=>'Administrátor']).'</label><button>Vytvořit</button></form></section>';
    echo '<section class="card"><h2>Přiřadit přístup k jednotce</h2><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="unit_user"><label>Uživatel ';$opts=[];
    foreach($users as $x) $opts[$x['id']]=$x['name'].' ('.$x['email'].')';echo form_select('user_id',$opts).'</label><label>Jednotka '.form_select('unit_id',unit_options($units)).'</label><button>Přiřadit</button></form></section>';
    echo '<section class="card"><h2>Seznam uživatelů</h2><table><tr><th>Jméno</th><th>E-mail</th><th>Role</th><th>Jednotky</th><th>Stav</th></tr>';
    foreach($users as $x) {
        $owned=all('SELECT u.name FROM units u JOIN unit_users a ON a.unit_id=u.id WHERE a.user_id=?',[$x['id']]);
        echo '<tr><td>'.html($x['name']).'</td><td>'.html($x['email']).'</td><td>'.html($x['role']).'</td><td>'.html(implode(', ',array_column($owned,'name'))).'</td><td><form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_toggle"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><button class="small secondary">'.($x['is_active']?'Aktivní – deaktivovat':'Neaktivní – aktivovat').'</button></form></td></tr>';
    }
    echo '</table></section><section class="card"><h2>Upravit uživatele</h2><p class="muted">Heslo nechte prázdné, pokud jej nechcete měnit.</p><div class="user-edit-grid">';
    foreach($users as $x) {
        echo '<form method="post" class="inline">'.post_token().'<input type="hidden" name="action" value="user_update"><input type="hidden" name="user_id" value="'.(int)$x['id'].'"><label>Jméno <input name="name" value="'.html($x['name']).'" required></label><label>Role '.form_select('role',['user'=>'Uživatel','admin'=>'Administrátor'],$x['role']).'</label><label>Nové heslo <input name="password" type="password" minlength="12" autocomplete="new-password"></label><button>Upravit '.html($x['email']).'</button></form><hr>';
    }
    echo '</div></section><section class="card"><h2>Odebrat přístup k jednotce</h2><table><tr><th>Uživatel</th><th>Jednotka</th><th>Akce</th></tr>';
    foreach(all('SELECT a.unit_id,a.user_id,u.name AS uname,t.name AS unitname FROM unit_users a JOIN users u ON u.id=a.user_id JOIN units t ON t.id=a.unit_id ORDER BY u.name,t.name') as $access) {
        echo '<tr><td>'.html($access['uname']).'</td><td>'.html($access['unitname']).'</td><td><form method="post">'.post_token().'<input type="hidden" name="action" value="unit_user_remove"><input type="hidden" name="user_id" value="'.(int)$access['user_id'].'"><input type="hidden" name="unit_id" value="'.(int)$access['unit_id'].'"><button class="small secondary">Odebrat</button></form></td></tr>';
    }
    echo '</table></section>';page_footer();exit;
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
    $ids=array_map(static fn($m)=>(int)$m['id'],$meters);
    if($ids) {
        $param=implode(',',array_fill(0,count($ids),'?'));
        foreach(all('SELECT r.*,m.name FROM meter_readings r JOIN meters m ON m.id=r.meter_id WHERE r.meter_id IN ('.$param.') ORDER BY r.reading_date DESC,r.id DESC LIMIT 100',$ids) as $r)
            echo '<tr><td>'.html($r['reading_date']).'</td><td>'.html($r['name']).'</td><td>'.html($r['value']).'</td><td>'.html($r['source']).($r['photo_id']?' · <a href="?page=photo_review&id='.(int)$r['photo_id'].'">foto</a>':'').'</td></tr>';
    }
    echo '</table></section>';page_footer();exit;
}
if($page==='photo_review') {
    $p=photo_for((int)($_GET['id']??0),$u);page_header('Kontrola odečtu z fotografie',$u);
    echo '<section class="card narrow"><h2>'.html($p['meter_name']).'</h2><img class="meter-photo" src="?page=photo_file&amp;id='.(int)$p['id'].'" alt="Fotografie měřidla"><p class="muted">Návrh AI: '.html($p['suggestion_note']).'. Rozpoznaný údaj není automaticky potvrzen.</p>';
    if($p['status']==='pending') {
        echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_confirm"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><label>Datum <input type="date" name="reading_date" value="'.date('Y-m-d').'" required></label><label>Ověřený stav měřidla ('.html($labels[$p['meter_type']]).') <input name="value" required inputmode="decimal" value="'.html($p['suggested_value']??'').'"></label><button>Potvrdit a uložit odečet</button></form>';
        echo '<form method="post">'.post_token().'<input type="hidden" name="action" value="photo_reject"><input type="hidden" name="photo_id" value="'.(int)$p['id'].'"><button class="secondary">Odmítnout návrh</button></form>';
    } else echo '<p>Stav fotografie: '.html($p['status']).'</p>';
    echo '</section>';page_footer();exit;
}
if($page==='consumption') {
    $from=(string)($_GET['from']??date('Y-m-01',strtotime('first day of last month')));
    $to=(string)($_GET['to']??date('Y-m-01'));
    page_header('Výpočet spotřeb',$u);
    echo '<section class="card"><form method="get" class="inline"><input type="hidden" name="page" value="consumption"><label>Odečet od <input type="date" name="from" value="'.html($from).'"></label><label>Odečet do <input type="date" name="to" value="'.html($to).'"></label><button>Vypočítat</button></form></section>';
    try {
        valid_day($from);valid_day($to);if($from>=$to) throw new RuntimeException('Datum konce musí být pozdější než počátek.');
        $result=Energy\Consumption::fromDatabase(db(),$from,$to);
        echo '<section class="card"><div class="section-head"><h2>Spotřeby jednotlivých jednotek</h2><a class="button secondary" href="?page=export&amp;from='.html($from).'&amp;to='.html($to).'">Export CSV</a></div><table><tr><th>Objekt / jednotka</th><th>Energie</th><th>Spotřeba</th></tr>';
        foreach(unit_scope($u) as $unit) {
            foreach(($result['allocations'][(int)$unit['id']]??[]) as $type=>$value)
                echo '<tr><td>'.html($unit['building_name']).' / '.html($unit['name']).'</td><td>'.html($labels[$type]).'</td><td><strong>'.html(fmt((float)$value)).'</strong> '.($type==='electricity'?'kWh':'m³').'</td></tr>';
        }
        echo '</table></section>';
        if(is_admin($u) && $result['warnings']) {
            echo '<section class="card warning"><h2>Upozornění při výpočtu</h2><ul>';
            foreach($result['warnings'] as $warning) echo '<li>'.html($warning).'</li>';
            echo '</ul></section>';
        }
        if(!is_admin($u)) echo '<p class="muted">Zobrazeny jsou pouze vaše spotřeby. Výchozí/neúplné odečty řeší administrátor.</p>';
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
