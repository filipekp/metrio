<?php
declare(strict_types=1);

require_once APP_ROOT . '/src/Services/ReadingService.php';
require_once APP_ROOT . '/src/Repositories/AccessScopes.php';
require_once APP_ROOT . '/src/View/ConsumptionChart.php';
require_once APP_ROOT . '/src/Http/PostHandler.php';

/** Whitelist rout pro GET; nelze načíst libovolný soubor přes ?page=. */
function metrio_routes(): array {
    return [
        'login'        => ['Auth',        'Přihlášení', false],
        'dashboard'    => ['Dashboard',   'Přehled', false],
        'buildings'    => ['Buildings',   'Objekty', true],
        'units'        => ['Units',       'Byty a jednotky', false],
        'users'        => ['Users',       'Uživatelé', true],
        'meters'       => ['Meters',      'Měřidla', false],
        'readings'     => ['Readings',    'Odečty', false],
        'photo_review' => ['Readings',    'Kontrola odečtu z fotografie', false],
        'consumption'  => ['Consumption', 'Vývoj a výpočet spotřeby', false],
        'reading_overview' => ['Consumption', 'Přehled odečtů po dnech', false],
        'update'       => ['Updates',     'Aktualizace GitHub Releases', true],
    ];
}

function metrio_render(string $page, array $data): void {
    // Pouze název z pevného seznamu metrio_routes(), nikoliv uživatelská cesta.
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/templates/pages/' . $page . '.php';
}

function metrio_dispatch(): void {
    start_session();
    $page=(string)($_GET['page'] ?? 'dashboard');
    $labels=energy_labels();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_post_request($page);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    if ($page === 'reading_limits') {
        header('Content-Type: application/json; charset=utf-8');
        $u=require_login();
        try {
            $mid=(int)($_GET['meter_id']??0);
            $day=valid_day((string)($_GET['date']??''));
            $meter = meter_for_reading($mid, $u);
            $device=Energy\MeterRegistry::deviceOn(db(),$mid,$day);
            if(!$device) throw new RuntimeException('K tomuto datu není zařízení instalováno.');
            $limits=Energy\MeterRegistry::bounds(db(),$device,$day);
            $limits['electricity'] = $meter['type'] === 'electricity';
            $limits['low'] = $limits['electricity'] ? Energy\MeterRegistry::bounds(db(), $device, $day, true) : null;
            $limits['installation_day']=$device['opening_value']!==null && $day===$device['installed_on'];
            $limits['duplicate']=(bool)one('SELECT id FROM meter_readings WHERE device_id=? AND reading_date=?',[(int)$device['id'],$day]);
            echo json_encode(['ok'=>true,'limits'=>$limits],JSON_UNESCAPED_UNICODE);
        } catch(Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
        }
        return;
    }
    if ($page === 'photo_file') {
        $u=require_login();
        require APP_ROOT . '/src/Modules/Readings/photo_file.php';
        return;
    }
    if ($page === 'login') {
        $existing=current_user();
        if ($existing && (int)$existing['is_active']) redirect('dashboard');
    }
    $u=$page==='login'?null:require_login();
    if ($page === 'export') {
        require APP_ROOT . '/src/Modules/Consumption/export.php';
        return;
    }
    $routes=metrio_routes();
    if (!isset($routes[$page])) {
        http_response_code(404);
        page_header('Stránka nenalezena',$u);
        page_footer();
        return;
    }
    $route=$routes[$page];
    if ($route[2]) admin_only($u);
    $data=['u'=>$u,'labels'=>$labels];
    if ($page !== 'login') {
        $controller=APP_ROOT.'/src/Modules/'.$route[0].'/'.$page.'.php';
        $data=array_merge($data, require $controller);
    }
    page_header($route[1],$u);
    metrio_render($page,$data);
    page_footer();
}
