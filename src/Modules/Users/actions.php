<?php
declare(strict_types=1);

// Zpracování akcí modulu Users. Oprávnění jsou kontrolována uvnitř akcí.
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
