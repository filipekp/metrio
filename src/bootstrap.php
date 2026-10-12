<?php
declare(strict_types=1);
const APP_ROOT = __DIR__ . '/..';
date_default_timezone_set('Europe/Prague');

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'Energy\\') === 0) {
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
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
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
function html($x): string { return htmlspecialchars((string)$x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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
function is_manager(array $user): bool { return $user['role'] === 'manager'; }
/** Správce vidí celý přiřazený objekt, ale nemůže měnit účty ani konfiguraci měřidel. */
function manages_building(array $user, int $buildingId): bool {
    if (is_admin($user)) return true;
    if (!is_manager($user) || $buildingId <= 0) return false;
    return (bool)one('SELECT 1 FROM building_managers WHERE user_id=? AND building_id=?', [(int)$user['id'], $buildingId]);
}

function csrf(): string {
    start_session();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf'];
}
function check_csrf(): void {
    start_session();
    if (!hash_equals(csrf(), (string)($_POST['_csrf']??''))) { http_response_code(419); exit('Neplatný CSRF token'); }
}
function post_token(): string { return '<input type="hidden" name="_csrf" value="'.html(csrf()).'">'; }
function redirect(string $page, string $message=''): void {
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
    if (is_admin($user)) return true;
    if (is_manager($user)) {
        $row = one('SELECT building_id FROM units WHERE id=?', [$unit]);
        return $row !== null && manages_building($user, (int)$row['building_id']);
    }
    return (bool)one('SELECT 1 FROM unit_users WHERE unit_id=? AND user_id=?',[$unit,$user['id']]);
}
function can_read_meter(array $user, array $meter): bool {
    if (is_admin($user)) return true;
    if (is_manager($user)) return manages_building($user, (int)$meter['building_id']);
    // Běžný uživatel vidí přímo měřidla přiřazená jeho jednotce.
    // Hlavní měřidlo NEZAKAZUJEME jen proto, že má podružná měřidla.
    // Nepřiřazená a cizí měřidla stále nejsou přístupná.
    return !empty($meter['unit_id']) && can_view_unit($user,(int)$meter['unit_id']);
}
function meter_for_reading(int $id,array $user): array {
    $meter=one('SELECT * FROM meters WHERE id=?',[$id]);
    if (!$meter || !can_read_meter($user,$meter)) { http_response_code(403); throw new RuntimeException('K tomuto měřidlu nemáte oprávnění.'); }
    return $meter;
}
function role_label(string $role): string { return ['admin'=>'Administrátor','manager'=>'Správce objektu','user'=>'Uživatel'][$role]??$role; }
/** Malé interní SVG ikony bez externího fontu nebo závislosti na CDN. */
function ui_icon(string $name, string $extraClass=''): string {
    $icons = [
        'overview'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'building'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4M8 7h1m6 0h1M8 11h1m6 0h1"/>',
        'units'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M9 21v-8h6v8"/>',
        'meters'=>'<path d="M5 18a9 9 0 1 1 14 0"/><path d="m12 13 4-5M4 18h16M12 18h.01"/>',
        'chart'=>'<path d="M3 3v18h18M7 16l4-5 4 3 5-7"/>',
        'readings'=>'<path d="M7 3h10v3H7zM5 5H4v16h16V5h-1M8 12h8M8 16h5"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 7a3 3 0 0 1 0 6M17 16a5 5 0 0 1 4 4"/>',
        'refresh'=>'<path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.7 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.3-3"/>',
        'logout'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'bolt'=>'<path d="m13 2-9 12h7l-1 8 10-12h-7z"/>',
        'water'=>'<path d="M12 2C9 7 5 11 5 15a7 7 0 0 0 14 0c0-4-4-8-7-13z"/>',
        'flame'=>'<path d="M12 22a8 8 0 0 0 8-8c0-4-3-8-7-12 1 5-4 6-4 10-2-1-2-3-2-4-2 2-3 4-3 6a8 8 0 0 0 8 8z"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'shield'=>'<path d="M12 2 4 5v6c0 5 3 8 8 11 5-3 8-6 8-11V5zM9 12l2 2 4-4"/>',
        'camera'=>'<path d="M4 7h4l2-3h4l2 3h4a2 2 0 0 1 2 2v10H2V9a2 2 0 0 1 2-2z"/><circle cx="12" cy="13" r="3"/>',
    ];
    $content=$icons[$name] ?? $icons['overview'];
    return '<svg class="ui-icon'.($extraClass!==''?' '.html($extraClass):'').'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$content.'</svg>';
}
function page_header(string $title,?array $user=null): void {
    $app=html(config('APP_NAME','Metrio'));
    $version=html(trim((string)@file_get_contents(APP_ROOT.'/VERSION')));
    // Bootstrap se servíruje lokálně: aplikace funguje také při výpadku CDN a na WEDOSu bez sestavovacích nástrojů.
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#111827"><meta name="color-scheme" content="light"><title>'.html($title).' · '.$app.'</title><link rel="stylesheet" href="assets/vendor/bootstrap.min.css"><link rel="stylesheet" href="assets/app.css?v=2"></head><body>';
    $GLOBALS['metrio_authenticated_layout'] = $user !== null;
    if (!$user) {
        echo '<div class="auth-shell"><div class="auth-hero"><div class="brand-mark">'.ui_icon('bolt').'</div><h1>Metrio</h1><p>Chytrá evidence energií pro každý objekt.</p><div class="auth-orb auth-orb-one"></div><div class="auth-orb auth-orb-two"></div></div><main id="main-content" class="auth-content"><div class="auth-intro"><span class="eyebrow">Vítejte zpět</span><h2>'.html($title).'</h2><p class="muted">Přihlaste se ke svému přehledu měřidel a spotřeby.</p></div>'.flash();
        return;
    }
    $current=(string)($_GET['page']??'dashboard');
    $sections=[
        'Hlavní nabídka'=>[
            'dashboard'=>['Přehled','overview'],
            'consumption'=>['Spotřeby a grafy','chart'],
            'readings'=>['Odečty','readings'],
            'meters'=>['Měřidla','meters'],
            'units'=>['Byty a jednotky','units'],
        ],
    ];
    if (is_admin($user)) {
        $sections['Správa systému']=[
            'buildings'=>['Objekty','building'],
            'users'=>['Uživatelé','users'],
            'update'=>['Aktualizace','refresh'],
        ];
    }
    $initial=mb_safe_initial((string)$user['name']);
    echo '<div class="layout-shell" id="layout-shell"><div class="sidebar-backdrop" id="sidebar-backdrop"></div><aside class="app-sidebar" id="app-sidebar" aria-label="Hlavní nabídka"><a href="?page=dashboard" class="sidebar-brand"><span class="brand-mark">'.ui_icon('bolt').'</span><span class="brand-text"><strong>Metrio</strong><small>Evidence energií</small></span></a><div class="sidebar-content">';
    foreach ($sections as $heading=>$items) {
        echo '<div class="nav-section"><p class="nav-section-title">'.html($heading).'</p><nav class="side-nav" aria-label="'.html($heading).'">';
        foreach($items as $key=>$data) {
            $active=$current===$key;
            echo '<a href="?page='.html($key).'" class="side-link'.($active?' active':'').'"'.($active?' aria-current="page"':'').'>'.ui_icon($data[1]).'<span>'.html($data[0]).'</span>'.($active?'<span class="active-dot"></span>':'').'</a>';
        }
        echo '</nav></div>';
    }
    echo '</div><div class="sidebar-foot"><div class="sidebar-foot-kicker">SPRÁVA SPOTŘEBY</div><p>Mějte všechny energie pod kontrolou.</p><span class="sidebar-version">Verze '.$version.'</span></div></aside>';
    echo '<div class="app-workspace"><header class="app-topbar"><div class="topbar-left"><button type="button" class="menu-toggle" id="menu-toggle" aria-label="Otevřít nebo zavřít nabídku" aria-controls="app-sidebar" aria-expanded="false">'.ui_icon('menu').'</button><span class="topbar-location">Evidence energií</span><span class="topbar-separator">/</span><span class="topbar-page">'.html($title).'</span></div><div class="topbar-right"><span class="topbar-role">'.html(role_label($user['role'])).'</span><span class="user-avatar" aria-hidden="true">'.html($initial).'</span><div class="topbar-user"><strong>'.html($user['name']).'</strong><small>'.html(role_label($user['role'])).'</small></div><form method="post" action="?page=logout" class="logout-form">'.post_token().'<button type="submit" class="logout-button" title="Odhlásit se" aria-label="Odhlásit se">'.ui_icon('logout').'</button></form></div></header>';
    echo '<main id="main-content" class="workspace-content"><div class="page-heading"><div><span class="eyebrow">METRIO / '.html(mb_strtoupper_fallback($title)).'</span><h1>'.html($title).'</h1><p class="page-subtitle">'.html(page_description($current)).'</p></div><div class="page-heading-aside"><span class="header-date">'.ui_icon('calendar').' '.html(date('d. m. Y')).'</span></div></div>'.flash();
}
function page_description(string $page): string {
    $labels=[
        'dashboard'=>'Vše důležité o spotřebě a odečtech na jednom místě.',
        'consumption'=>'Porovnávejte spotřebu jednotlivých energií v čase.',
        'readings'=>'Přidávejte stavy měřidel ručně nebo pomocí fotografie.',
        'meters'=>'Přehled hlavních i podružných měřidel a jejich nastavení.',
        'units'=>'Spravované byty, jednotky a jejich přiřazení.',
        'buildings'=>'Evidence objektů a jejich adres.',
        'users'=>'Uživatelské účty, role a oprávnění.',
        'update'=>'Správa verzí a nasazení z GitHub Releases.',
        'photo_review'=>'Potvrzení hodnoty rozpoznané z fotografie.',
    ];
    return $labels[$page] ?? 'Přehled systému evidence energií.';
}
function mb_safe_initial(string $name): string {
    $name=trim($name);
    if ($name==='') return 'M';
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) return mb_strtoupper(mb_substr($name,0,1,'UTF-8'),'UTF-8');
    return strtoupper(substr($name,0,1));
}
function mb_strtoupper_fallback(string $text): string {
    return function_exists('mb_strtoupper')?mb_strtoupper($text,'UTF-8'):strtoupper($text);
}
function page_footer(): void {
    if (!empty($GLOBALS['metrio_authenticated_layout'])) {
        echo '</main><footer class="app-footer"><span>© '.date('Y').' Metrio · Evidence energií · <a href="https://www.filipek-czech.cz" target="_blank">Pavel Filípek</a></span><span>Postaveno pro přehled o každé spotřebě</span></footer></div></div>';
    } else {
        echo '</main></div>';
    }
    echo '<script src="assets/app.js?v=2" defer></script></body></html>';
}
function form_select(string $name,array $options,$selected=null,bool $optional=false): string {
    $out='<select name="'.html($name).'">'; if($optional) $out.='<option value="">— bez přiřazení —</option>';
    foreach($options as $val=>$label) $out.='<option value="'.html($val).'"'.((string)$selected===(string)$val?' selected':'').'>'.html($label).'</option>';
    return $out.'</select>';
}
function unit_options(array $units): array { $v=[];foreach($units as $x) $v[$x['id']]=$x['building_name'].' / '.$x['name'];return $v; }
function energy_labels(): array { return ['electricity'=>'Elektřina (kWh)','gas'=>'Plyn (m³)','cold_water'=>'Studená voda (m³)','hot_water'=>'Teplá voda (m³)','wastewater'=>'Odpadní voda (m³)']; }
function unit_kind_label(string $value): string { return ['apartment'=>'Byt','common'=>'Společné prostory'][$value]??'Neznámý typ'; }
function wastewater_mode_label(string $value): string { return ['none'=>'Bez automatického výpočtu','sum_water'=>'Studená + teplá voda','metered'=>'Vlastní měřidlo'][$value]??'Neznámý režim'; }
function reading_source_label(string $value): string { return ['manual'=>'Ručně','photo'=>'Z fotografie'][$value]??'Neznámý zdroj'; }
function photo_status_label(string $value): string { return ['pending'=>'Čeká na ověření','confirmed'=>'Potvrzeno','rejected'=>'Zamítnuto'][$value]??'Neznámý stav'; }
function fmt(float $n): string { return number_format($n, 3, ',', ' '); }

function csv_cell(string $value): string {
    return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
}
