# Evidence energií – PHP + MariaDB

První funkční verze systému pro evidenci elektřiny, plynu, studené vody, teplé vody a odpadní vody. Správa více objektů a bytových jednotek, role `admin`/`user`, hierarchie hlavních a podružných měřidel, historické koeficienty, odečty, výpočty, fotografie s volitelným AI návrhem a správa GitHub Releases.

## Požadavky

- PHP **8.2+**, `pdo_mysql`, `fileinfo`, `curl` a `zip` (rozšíření cURL a ZipArchive nutná pro AI / aktualizace).
- MariaDB 10.6+ (testovací Docker Compose používá MariaDB 11.4).
- Apache/Nginx s **DocumentRoot nastaveným na adresář `public/`**. Nikdy nevystavujte kořen projektu ani adresáře `src`, `storage`, `migrations` a `.env`.
- HTTPS pro produkci a používání kamery v mobilním prohlížeči.
- Pro AI čtení fotografie volitelně OpenAI API klíč (služba je placená dle jejího tarifu a fotografie se odesílá externímu poskytovateli).

## Instalace na běžném hostingu

1. Nahrajte zdrojový ZIP na hosting a rozbalte jej. Vytvořte prázdnou databázi MariaDB a uživatele s oprávněními k tabulkám.
2. `cp .env.example .env`, nastavte `DB_*`, `APP_URL` a `GITHUB_REPOSITORY=uzivatel/nazev-repozitare`.
3. Zajistěte zapisovatelnost `storage/photos`, `storage/deploy-backups` a složky `storage` pro PHP, např. `chown -R www-data:www-data storage` (dle hostingu).
4. Spusťte `php bin/migrate.php`, potom `php bin/create-admin.php admin@priklad.cz`.
5. Nastavte webový DocumentRoot do `public/`, otevřete web přes HTTPS a přihlaste se.

Pro kontrolu verzí databáze: `php bin/migrate.php --status`. Každá nová migrace je soubor `migrations/002_nazev.sql` atd. Jednou aplikované SQL soubory se **nikdy neupravují**: ukládá se jejich SHA-256. DDL příkazy MariaDB mohou provést implicitní commit; před migrací vytvořte databázovou zálohu.

## Rychlá lokální instalace přes Docker

```bash
cp .env.example .env
# v .env změňte DB_HOST=db, DB_USER=energie, DB_NAME=energie, DB_PASSWORD=local_dev_password
docker compose up -d --build
docker compose exec app php bin/migrate.php
docker compose exec app php bin/create-admin.php admin@example.cz
```

Před nahráváním fotografií případně opravte oprávnění složky `storage` pro uživatele webserveru (`www-data`) uvnitř kontejneru. Pak otevřete <http://localhost:8080> (základní ověření v prohlížeči). Kamera na telefonu mimo `localhost` potřebuje HTTPS. Přístup do databáze v Dockeru: služba `db`, nikoli `localhost`.

## Správa

- **Objekty**: založte dům/budovu.
- **Jednotky**: založte Byt 1, Byt 2, společné prostory. Určete režim odpadní vody: vlastní měřidlo, součet studené + teplé vody, nebo žádný automatický výpočet.
- **Uživatelé**: založte účty a přiřaďte jednotlivým uživatelům práva ke konkrétním jednotkám.
- **Měřidla**: přidejte hlavní elektroměr jako měřidlo se zbytkovou spotřebou přiřazenou Bytu 1; podružný elektroměr s rodičem nastaveným na hlavní a přiřazený Bytu 2. Koeficient pro podružný elektroměr zadejte např. `1.02`.
- **Odečty**: u obou měřidel pořiďte počáteční a koncový odečet ke shodným datům. Následně otevřete **Spotřeby**.
- **Výsledky**: Byt 2 = (nový stav – starý stav) × koeficient. Byt 1 = korigovaná spotřeba hlavního – korigovaná spotřeba podružného. U další úrovně podružného měřidla se počítá vždy rozdíl přímého rodiče a přímých potomků. Přesné výsledky závisejí na odečtech k zadaným hranicím.

Příklad: hlavní 1000 kWh, podružný 400 kWh a koeficient 1,02 => Byt 2 = 408 kWh, Byt 1 = 592 kWh.

**Poznámka ke koeficientům:** při změně uprostřed měsíce je nutný mezilehlý odečet a výpočet po úsecích. Současná verze na takové období upozorní, nepřiděluje automaticky odhady. Pokud jste měřidlo vyměnili a nové má nižší číselník, založte nové měřidlo.

**Poznámka k odpadní vodě:** režim SV+TV používejte výhradně tehdy, když SV a TV představují oddělené objemy vody. Pokud studená voda již zahrnuje vodu před ohřevem, vedlo by sčítání k dvojímu započtení.

## Kamera / AI návrh odečtu

Na mobilu otevřete **Odečty → Vyfotit měřidlo**. HTML atribut `capture="environment"` požádá kompatibilní prohlížeč o zadní fotoaparát; některé prohlížeče mohou namísto kamery nabídnout výběr souboru.

Server přijímá jen JPG/PNG/WEBP do 5 MB, kontroluje MIME a strukturu obrázku, ukládá jej pod náhodným názvem v `storage/photos/` mimo veřejný adresář a předává jej pro AI zpracování pouze tehdy, pokud je v `.env` nastaveno `OPENAI_API_KEY`. AI zkusí určit **kumulativní stav číselníku** prostřednictvím OpenAI Responses API, navrhne hodnotu a upozornění. Uživatel návrh ručně ověří a potvrdí; **bez potvrzení se žádný odečet nezaloží**. Pokud služba selže nebo není nastavena, lze hodnotu normálně opsat. Fotografie jsou chráněny přihlášením a oprávněním a automatické veřejné URL k nim neexistují.

Důležité: fotografie může obsahovat identifikátory a adresy; pokud zapnete AI, obsah je odeslán službě OpenAI. Zajistěte informování uživatelů a pravidla uchovávání/mazání fotografií odpovídající vašemu provozu. Před produkcí ověřte parametry zpracování dat dle účtu poskytovatele.

## GitHub vydání a aktualizace

Projekt má `.github/workflows/release.yml`: při push tagu `vMAJOR.MINOR.PATCH` provede PHP lint, testy a vytvoří GitHub Release s přílohami:

- `evidence-energii-v1.0.0.zip`
- `evidence-energii-v1.0.0.zip.sha256`

Příkaz `git tag v1.0.0 && git push origin v1.0.0` vytvoří release v repozitáři s povolenými GitHub Actions.

Na serveru nastavte `GITHUB_REPOSITORY=uzivatel/repo`. Skript `php bin/deploy.php --check` zjistí nejnovější stabilní Release. `php bin/deploy.php --install --yes` jej stáhne a nasadí; totéž lze přes administrátorskou stránku **Aktualizace**.

Nasazení je povoleno jen z pevné domény GitHub a z očekávaného repozitáře. Vyžaduje release ZIP, SHA-256 kontrolní soubor a kontroluje případný `digest` z API. ZIP se bezpečně rozbalí a validuje. Poté se zálohují měněné soubory do `storage/deploy-backups`, kopírují nové soubory (jen whitelist adresářů), spustí migrace a aktualizuje se `VERSION`. **`.env`, `storage`, databáze a `.git` se nepřepisují**. Při selhání se kopírované soubory vracejí ze zálohy. Databázové DDL nelze automaticky rollbackovat — vždy před upgradem dělejte i zálohu MariaDB. Aktualizační skript nemaže soubory odstraněné v nových verzích, pro změny vyžadující mazání je nutný řízený postup.

Pro instalace z nepublikovaného/private repozitáře je nutné doplnit bezpečné GitHub API ověření; tato verze podporuje veřejné repozitáře a veřejné Release přílohy. Nasazení musí mít oprávnění zapisovat do aplikačních souborů (to je třeba ošetřit na hostingu). Release workflow nepřenáší `storage` ani `.env`.

## Bezpečnost a limity MVP

- Přihlášení používá `password_hash`, session cookies HttpOnly/SameSite, formuláře mají CSRF a SQL používá PDO prepared statements.
- Uživateli se zobrazují jen měřidla, která přímo přiřazujeme jeho jednotce a nemají podružné měřidlo. Přesto se v přehledu spotřeby zobrazí vypočítaná spotřeba jeho jednotky. Uživatel nemá přístup k odečtům nadřazených měřidel ani měřidlům sousedů.
- Administrátor spravuje všechny objekty, uživatele, měřidla i koeficienty.
- Je nutné provozní HTTPS, nastavit zálohování souborů i databáze, limity požadavků u reverzní proxy a hlídání diskového prostoru. Serverové upload limity `upload_max_filesize` / `post_max_size` musí umožnit fotografie do 5 MB.
- Databázové výpočty jsou v aktuální verzi **on demand**, bez účetních uzávěrek a nezměnitelných fakturačních snapshotů. Nejsou implementovány peněžní tarify, schvalování odečtů, automatické mazání fotografií ani automatické dělení koeficientů při změně v průběhu období.
- Větší provoz si zaslouží obsluhu chyb ze serverových logů, automatizované zálohy, integrační testy proti skutečné MariaDB a případně background frontu na delší OCR požadavky.

## Testování

```bash
php tests/consumption.php
find src bin public tests -type f -name '*.php' -exec php -l {} \;
```

Připravená testovací sada ověřuje jednoduché odečtení, koeficient, víceúrovňová měřidla, záporný zbytek a chybějící odečet. Integrační testy MariaDB vyžadují běžící DB a nejsou zahrnuty do této samostatné distribuce.
