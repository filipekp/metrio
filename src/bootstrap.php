<?php
declare(strict_types=1);
const APP_ROOT = __DIR__ . '/..';
date_default_timezone_set('Europe/Prague');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Energy\\')) {
        $name = substr($class, strlen('Energy\\'));
        $file = __DIR__ . '/' . str_replace('\\', '/', $name) . '.php';
        if (is_file($file)) require $file;
    }
});

function config(string $name, string $default = ''): string {
    static $values = null;
    if ($values === null) {
        $values = [];
        $filename = APP_ROOT . '/.env';
        if (is_file($filename)) {
            foreach (file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim(trim($value), '"\'');
            }
        }
    }
    $env = getenv($name);
    return $env !== false ? (string) $env : ($values[$name] ?? $default);
}

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            'mysql:host=' . config('DB_HOST', '127.0.0.1') . ';port=' . config('DB_PORT', '3306') . ';dbname=' . config('DB_NAME') . ';charset=utf8mb4',
            config('DB_USER'), config('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    return $pdo;
}

function sql(string $query, array $params = []): PDOStatement {
    $statement = db()->prepare($query);
    $statement->execute($params);
    return $statement;
}
function all(string $query, array $params = []): array { return sql($query,$params)->fetchAll(); }
function one(string $query, array $params = []): ?array { $x=sql($query,$params)->fetch(); return $x ?: null; }
function html(mixed $x): string { return htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function start_session(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    if (config('APP_ENV','production') === 'production' && empty($_SERVER['HTTPS'])) {
        // HTTPS must be configured at the reverse proxy; trust forwarded headers only in the proxy configuration.
    }
    session_name('energy_session');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>'/']);
    session_start();
}
function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    return one('SELECT id,name,email,role,is_active FROM users WHERE id=?', [(int)$_SESSION['uid']]);
}
function require_login(): array {
    $user=current_user();
    if (!$user || !(int)$user['is_active']) { header('Location: ?page=login'); exit; }
    return $user;
}
function admin_only(array $user): void { if ($user['role'] !== 'admin') { http_response_code(403); exit('Přístup odepřen'); } }
function is_admin(array $user): bool { return $user['role'] === 'admin'; }
function csrf(): string { start_session(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); }
function check_csrf(): void {
    start_session();
    if (!hash_equals(csrf(), (string)($_POST['_csrf']??''))) { http_response_code(419); exit('Neplatný CSRF token'); }
}
function post_token(): string { return '<input type="hidden" name="_csrf" value="'.html(csrf()).'">'; }
function redirect(string $page, string $message=''): never {
    if ($message !== '') { start_session(); $_SESSION['flash']=$message; }
    header('Location: ?page=' . rawurlencode($page)); exit;
}
function flash(): string {
    start_session(); $msg=$_SESSION['flash']??''; unset($_SESSION['flash']);
    return $msg ? '<div class="flash">'.html($msg).'</div>' : '';
}
function audit(int $actor, string $action, string $detail): void {
    sql('INSERT INTO audit_log (actor_id,action,detail) VALUES (?,?,?)', [$actor,$action,$detail]);
}
function valid_number(string $input, bool $positive = false): string {
    $str = str_replace(',', '.', trim($input));
    if (!preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D',$str)) throw new RuntimeException('Neplatná hodnota odečtu / koeficientu.');
    if ($positive && (float)$str <= 0) throw new RuntimeException('Koeficient musí být větší než nula.');
    return $str;
}
function valid_factor(string $input): string {
    $str = str_replace(',', '.', trim($input));
    if(!preg_match('/^\d{1,6}(?:\.\d{1,6})?$/D',$str) || (float)$str<=0)
        throw new RuntimeException('Koeficient musí být kladné číslo nejvýše se šesti desetinnými místy.');
    return $str;
}
function valid_day(string $value): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) throw new RuntimeException('Neplatné datum.');
    return $value;
}
function can_view_unit(array $user, int $unit): bool {
    return is_admin($user) || (bool)one('SELECT 1 FROM unit_users WHERE unit_id=? AND user_id=?',[$unit,$user['id']]);
}
function can_read_meter(array $user, array $meter): bool {
    if (is_admin($user)) return true;
    if (!$meter['unit_id'] || !can_view_unit($user,(int)$meter['unit_id'])) return false;
    // Uživatel nesmí dostat nadřazené odečty, ze kterých by zjistil spotřebu cizí jednotky.
    return !one('SELECT 1 FROM meters WHERE parent_id=? LIMIT 1', [$meter['id']]);
}
function meter_for_reading(int $id,array $user): array {
    $meter=one('SELECT * FROM meters WHERE id=?',[$id]);
    if (!$meter || !can_read_meter($user,$meter)) { http_response_code(403); throw new RuntimeException('K tomuto měřidlu nemáte oprávnění.'); }
    return $meter;
}
function page_header(string $title,?array $user=null): void {
    $app=html(config('APP_NAME','Evidence energií'));
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>'.html($title).' · '.$app.'</title><link rel="stylesheet" href="assets/app.css"></head><body>';
    if ($user) {
        echo '<header><div class="top"><strong>⚡ '.$app.'</strong><div class="muted">'.html($user['name']).' · '.html($user['role']).'</div></div><nav>';
        foreach (['dashboard'=>'Přehled','units'=>'Jednotky','meters'=>'Měřidla','consumption'=>'Spotřeby','readings'=>'Odečty'] as $page=>$label)
            echo '<a href="?page='.$page.'">'.$label.'</a>';
        if (is_admin($user)) { echo '<a href="?page=buildings">Objekty</a><a href="?page=users">Uživatelé</a><a href="?page=update">Aktualizace</a>'; }
        echo '<form method="post" action="?page=logout">'.post_token().'<button class="link">Odhlásit</button></form></nav></header>';
    }
    echo '<main><h1>'.html($title).'</h1>'.flash();
}
function page_footer(): void { echo '</main><footer>Evidence energií · '.html(trim((string)@file_get_contents(APP_ROOT.'/VERSION'))).'</footer></body></html>'; }
function form_select(string $name,array $options,mixed $selected=null,bool $optional=false): string {
    $out='<select name="'.html($name).'">'; if($optional) $out.='<option value="">— bez přiřazení —</option>';
    foreach($options as $val=>$label) $out.='<option value="'.html($val).'"'.((string)$selected===(string)$val?' selected':'').'>'.html($label).'</option>';
    return $out.'</select>';
}
function unit_options(array $units): array { $v=[];foreach($units as $x) $v[$x['id']]=$x['building_name'].' / '.$x['name'];return $v; }
function energy_labels(): array { return ['electricity'=>'Elektřina (kWh)','gas'=>'Plyn (m³)','cold_water'=>'Studená voda (m³)','hot_water'=>'Teplá voda (m³)','wastewater'=>'Odpadní voda (m³)']; }
function fmt(float $n): string { return number_format($n, 3, ',', ' '); }

function csv_cell(string $value): string {
    return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
}
