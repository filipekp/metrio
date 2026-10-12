# Architektura aplikace Metrio

Projekt je rozdělen na **veřejný vstup**, **HTTP směrování**, **moduly**, **přístup k datům**, **služby** a **šablony**. Funkční endpointy `?page=...` a názvy POST `action` zůstávají zachovány.

```text
public/index.php                  jediný veřejný vstupní bod
src/bootstrap.php                 sdílené pomocné funkce, databáze, autentizace a layout
src/Http/Router.php               povolené GET routy, autorizace, načtení controlleru a šablony
src/Http/PostHandler.php          POST dispatcher, CSRF a jednotné zpracování chyb
src/Repositories/AccessScopes.php načítání jednotek a měřidel dle práv uživatele
src/Services/ReadingService.php   ověřování a ukládání odečtů, přístup k fotkám
src/View/ConsumptionChart.php     vykreslení grafu spotřeby
src/View/Layout.php              společné vykreslení, konfigurace navigace a kompatibilita s page_header()
templates/layouts/               začátek/konec HTML dokumentu, dashboard i login
templates/partials/              znovupoužívaná hlavička, menu, nadpis stránky a patička
src/Modules/Dashboard/            přehledová data
src/Modules/Buildings/            správa objektů
src/Modules/Units/                správa jednotek
src/Modules/Users/                uživatelé a přiřazení práv
src/Modules/Meters/               měřidla a koeficienty
src/Modules/Readings/             odečty, fotografie a AI návrhy
src/Modules/Consumption/          spotřeby a CSV export
src/Modules/Updates/              GitHub release updater
src/Modules/*/actions.php         POST akce modulu (kde jsou potřeba)
src/Modules/*/<route>.php         GET controller: načtení dat pro stránku
templates/pages/*.php             POUZE obsah jednotlivé stránky, bez hlavičky/menu/patičky
```

## Přidání nové stránky

1. Přidejte položku do `metrio_routes()` v `src/Http/Router.php` (název, modul, titulek, administrátorské oprávnění).
2. Vytvořte `src/Modules/MujModul/moje_stranka.php`: PHP soubor musí `return ['foo' => ...];`.
3. Vytvořte `templates/pages/moje_stranka.php`; proměnné z controlleru jsou k dispozici přímo (`$foo`, `$u`, `$labels`).
4. Pokud má stránka formulářové akce, přidejte whitelist `action` v `src/Http/PostHandler.php` a `src/Modules/MujModul/actions.php`.
5. Přidejte kontrolu oprávnění v controlleru i POST akci (na samotné skrytí tlačítka nikdy nespoléhejte).

**Kompatibilita:** PHP 7.3+, MariaDB, bez Composeru, bez změny databázových migrací. Veřejný webroot může zůstat stejný. Bootstrap/CSS zůstávají v `public/assets`. Šablony umístěte mimo veřejnou složku.

**Updater:** GitHub Actions balí i `templates/`; `src/Updater.php` vyžaduje šablony a při aktualizaci je kopíruje spolu se `src/`.

## Nasazení

Patch aplikujte na aktuální `master` projektu v PhpStormu. Ověření lokálně:

```bash
php -l public/index.php
find src templates public tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/consumption.php
php tests/consumption_periods.php
php tests/permissions.php
php tests/php73_compatibility.php
php tests/migrations.php
php tests/module_structure.php
php tests/templates.php
```

Nové migrace nejsou potřeba. Po nasazení otestujte přihlášení, omezení práv, uložení odečtu, fotografie, spotřeby a aktualizaci z GitHubu.

## Společné šablony a navigace

`src/View/Layout.php` poskytuje `page_header()` a `page_footer()`, které volá
`src/Http/Router.php` pro všechny běžné stránky a `public/install.php` pro instalátor.
`templates/layouts/document-start.php` a `document-end.php` obsahují společný HTML
dokument a assety. Přihlášení a instalátor používají samostatné `auth-open.php`
a `auth-close.php`, aby nezobrazovaly dashboardové menu.

Přihlášený uživatel používá `app-open.php` / `app-close.php` a části
`templates/partials/sidebar.php`, `topbar.php`, `page-heading.php`, `footer.php`.
Položky menu a pravidla zobrazení rolím upravujte na jednom místě ve
`metrio_navigation()` v `src/View/Layout.php`. Oprávnění na serveru zůstávají
v controllerech a POST handleru; skrytí menu samo o sobě přístup nechrání.

Nové podstránky **nesmějí** generovat `<html>`, `<body>`, sidebar ani layoutové
`<main>`. V `templates/pages/` je pouze obsah stránky a router zajistí zbytek.
`php tests/layout.php` kontroluje, že se společné části vykreslí jednou a podle rolí.
