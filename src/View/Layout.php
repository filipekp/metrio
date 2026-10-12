<?php
declare(strict_types=1);

/**
 * Jediné vstupní body společného vykreslování. Starší instalační skript používá
 * page_header()/page_footer(), takže rozhraní zůstává zpětně kompatibilní.
 * Jednotlivé stránky v templates/pages/ vykreslují jen obsah <main>.
 */
function page_header(string $title, ?array $user = null): void {
    $GLOBALS['metrio_authenticated_layout'] = $user !== null;
    require APP_ROOT . '/templates/layouts/document-start.php';

    if ($user === null) {
        require APP_ROOT . '/templates/layouts/auth-open.php';
        return;
    }

    $current = (string)($_GET['page'] ?? 'dashboard');
    $version = trim((string)@file_get_contents(APP_ROOT . '/VERSION'));
    $initial = mb_safe_initial((string)$user['name']);
    $sections = metrio_navigation($user);
    require APP_ROOT . '/templates/layouts/app-open.php';
}

function page_footer(): void {
    if (!empty($GLOBALS['metrio_authenticated_layout'])) {
        require APP_ROOT . '/templates/layouts/app-close.php';
    } else {
        require APP_ROOT . '/templates/layouts/auth-close.php';
    }
    require APP_ROOT . '/templates/layouts/document-end.php';
}

/** Navigaci podle oprávnění definujeme právě na jednom místě. */
function metrio_navigation(array $user): array {
    $sections = [
        'Hlavní nabídka' => [
            'dashboard' => ['Přehled', 'overview'],
            'consumption' => ['Spotřeby a grafy', 'chart'],
            'readings' => ['Odečty', 'readings'],
            'meters' => ['Měřidla', 'meters'],
            'units' => ['Byty a jednotky', 'units'],
        ],
    ];
    if (is_admin($user)) {
        $sections['Správa systému'] = [
            'buildings' => ['Objekty', 'building'],
            'users' => ['Uživatelé', 'users'],
            'update' => ['Aktualizace', 'refresh'],
        ];
    }
    return $sections;
}
