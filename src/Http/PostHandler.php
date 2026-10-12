<?php
declare(strict_types=1);

/** Formulářové akce jsou povoleny jen na odpovídajících stránkách. */
function handle_post_request(string $page): void {
    check_csrf();
    try {
        if ($page === 'login') {
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
        $u = require_login();
        $labels = energy_labels();
        if ($page === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: ?page=login');
            exit;
        }
        $action = (string)($_POST['action'] ?? '');
        $routes = [
            'building_create' => ['buildings', 'Buildings'],
            'unit_create' => ['units', 'Units'],
            'unit_mode' => ['units', 'Units'],
            'user_create' => ['users', 'Users'],
            'user_toggle' => ['users', 'Users'],
            'user_update' => ['users', 'Users'],
            'unit_user_remove' => ['users', 'Users'],
            'building_manager_add' => ['users', 'Users'],
            'building_manager_remove' => ['users', 'Users'],
            'unit_user' => ['users', 'Users'],
            'meter_create' => ['meters', 'Meters'],
            'meter_update' => ['meters', 'Meters'],
            'meter_replace' => ['meters', 'Meters'],
            'meter_active' => ['meters', 'Meters'],
            'coefficient_add' => ['meters', 'Meters'],
            'reading_add' => ['readings', 'Readings'],
            'photo_upload' => ['readings', 'Readings'],
            'photo_confirm' => ['photo_review', 'Readings'],
            'photo_reject' => ['photo_review', 'Readings'],
            'deploy' => ['update', 'Updates'],
        ];
        if (!isset($routes[$action]) || $routes[$action][0] !== $page) {
            throw new RuntimeException('Neznámá nebo nepovolená akce.');
        }
        $module = $routes[$action][1];
        require APP_ROOT . '/src/Modules/' . $module . '/actions.php';
        throw new RuntimeException('Akce nebyla dokončena.');
    } catch (Throwable $e) {
        if (!($e instanceof RuntimeException)) error_log((string)$e);
        $msg = ($e instanceof PDOException)
            ? 'Databázová operace se nezdařila (např. duplicitní údaj).'
            : $e->getMessage();
        $_SESSION['flash'] = 'Chyba: ' . $msg;
        if ($page === 'login') redirect('login');
        if ($page === 'photo_review') {
            header('Location: ?page=photo_review&id=' . (int)($_POST['photo_id'] ?? 0));
            exit;
        }
        redirect(in_array($page, ['users','units','meters','readings','buildings','update'], true) ? $page : 'dashboard');
    }
}
