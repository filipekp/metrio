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
templates/pages/*.php             HTML výstup každé stránky, bez změny URL
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
