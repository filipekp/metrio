# Formátování zdrojového kódu

Projekt používá jednotný styl: **PHP PSR-12**, **HTML s odsazením čtyři mezery** (pro PHP šablony platí PSR-12 uvnitř PHP), **CSS a JavaScript dvě mezery**, UTF-8, LF.

Při refaktoringu HTML šablon dávejte přednost čitelnému markup mimo PHP před jedním dlouhým řetězcem `echo`. Hodnoty z databáze vždy escapujte přes `html()`. Neměňte pouze kvůli formátování již aplikované soubory `migrations/*.sql` — systém kontroluje jejich SHA-256.

## PHP (vývojový počítač)

```bash
# Samostatný nástroj PHP-CS-Fixer 3.x kompatibilní s používanou verzí PHP.
php-cs-fixer fix --config=.php-cs-fixer.dist.php

# Nezměněný zdrojový kód pouze zkontroluje.
php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php

# Syntaxe a regresní testy.
find src bin public templates tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/electricity_tariffs.php
php tests/migration_005_tariffs.php
```

## CSS / JavaScript / HTML

```bash
npx prettier --write "public/assets/*.{js,css}" "**/*.html"
npx prettier --check "public/assets/*.{js,css}" "**/*.html"
```

Při zavádění formatteru nepoužívejte `--write` na `public/assets/vendor`, generované ani databázové soubory. Formatter běží na existujících zdrojích lokálně; do verzovacího systému patří jeho výsledky. Formátovací změny oddělujte od funkčních změn v commitech.
