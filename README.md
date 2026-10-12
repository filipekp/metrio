# Evidence energií – PHP + MariaDB

První funkční verze systému pro evidenci elektřiny, plynu, studené vody, teplé vody a odpadní vody. Správa více objektů a bytových jednotek, role `admin` / `manager` / `user`, hierarchie hlavních a podružných měřidel, historické koeficienty, odečty, výpočty, fotografie s volitelným AI návrhem a správa GitHub Releases.

## Požadavky

- PHP **7.3+**, `pdo_mysql`, `fileinfo`, `curl` a `zip` (rozšíření cURL a ZipArchive nutná pro AI / aktualizace).
- MariaDB 10.6+ (testovací Docker Compose používá MariaDB 11.4).
- Apache/Nginx se směrováním na `public/`; na WEDOS Apache použijte kořenový `.htaccess` a postup níže. Privátní soubory nesmí být veřejně přístupné.
- HTTPS pro produkci a používání kamery v mobilním prohlížeči.
- Pro AI čtení fotografie volitelně Google Gemini API klíč (dostupnost a případné poplatky se řídí tarifem Google; fotografie se odesílá externímu poskytovateli).

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
- **Odečty**: u obou měřidel pořiďte více datovaných odečtů. Přesná shoda s hranicí požadovaného období není nutná. Následně otevřete **Spotřeby**.
- **Výsledky**: Byt 2 = (nový stav – starý stav) × koeficient. Byt 1 = korigovaná spotřeba hlavního – korigovaná spotřeba podružného. U další úrovně podružného měřidla se počítá vždy rozdíl přímého rodiče a přímých potomků. Výsledky mezi odečty a krátce za posledním odečtem jsou vždy označeny jako **odhad**.

Příklad: hlavní 1000 kWh, podružný 400 kWh a koeficient 1,02 => Byt 2 = 408 kWh, Byt 1 = 592 kWh.

**Poznámka ke koeficientům:** při změně uprostřed měsíce je nutný mezilehlý odečet a výpočet po úsecích. Současná verze na takové období upozorní, nepřiděluje automaticky odhady. Pokud jste měřidlo vyměnili a nové má nižší číselník, založte nové měřidlo.

**Poznámka k odpadní vodě:** režim SV+TV používejte výhradně tehdy, když SV a TV představují oddělené objemy vody. Pokud studená voda již zahrnuje vodu před ohřevem, vedlo by sčítání k dvojímu započtení.

## Spotřeby mimo přesné termíny odečtů a grafy

Stránka **Spotřeby** nyní umožňuje vybrat jednotku, libovolné období „od – do“ a samostatný graf pro každou evidovanou energii. Spotřeba se zobrazuje také jako souhrn a tabulka dílčích období; export CSV rozlišuje skutečné hranice odečtů a odhady. Běžný uživatel vidí jen své jednotky.

Pokud požadované datum neleží přímo na odečtu, program spočte **lineární interpolaci** mezi dvěma nejbližšími odečty (rovnoměrná spotřeba v čase). Pokud požadované datum překračuje první nebo poslední známý odečet, dovolí **extrapolaci maximálně o 45 dnů** z nejbližších dvou odečtů. Pro větší odchylku, pouze jeden odečet nebo zápornou vypočtenou hodnotu neposkytne výsledek. Všechna čísla, která nejsou založena na přesných hraničních odečtech, jsou označena jako **Odhad** včetně grafů a CSV. Interpolace mezi velmi vzdálenými odečty je též nejistá; administrátor uvidí varování nad 92 dnů. Odhad není vhodné považovat za definitivní podklad pro finanční vyúčtování bez kontroly.

Při změně koeficientu uvnitř období se dotčené měřidlo **nevypočítá**. Je potřeba období rozdělit a mít mezilehlý odečet. Pro výměnu číselníku založte nové měřidlo. Částky ani nezměnitelné uzávěrky nejsou součástí této verze.

**Hodnoty v MariaDB zůstávají v původních technických kódech** (`manual`, `apartment` atd.) kvůli kompatibilitě. V rozhraní jsou přeloženy do češtiny. Tento patch nevyžaduje žádnou databázovou migraci.

## Kamera / AI návrh odečtu

Na mobilu otevřete **Odečty → Vyfotit měřidlo**. HTML atribut `capture="environment"` požádá kompatibilní prohlížeč o zadní fotoaparát; některé prohlížeče mohou namísto kamery nabídnout výběr souboru.

Server přijímá jen JPG/PNG/WEBP do 5 MB, kontroluje MIME a strukturu obrázku, ukládá jej pod náhodným názvem v `storage/photos/` mimo veřejný adresář a předává jej pro AI zpracování pouze tehdy, pokud je v `.env` nastaveno `GEMINI_API_KEY`. AI zkusí určit **kumulativní stav číselníku** prostřednictvím Google Gemini API (`generateContent`), navrhne hodnotu a upozornění. Uživatel návrh ručně ověří a potvrdí; **bez potvrzení se žádný odečet nezaloží**. Pokud služba selže nebo není nastavena, lze hodnotu normálně opsat. Fotografie jsou chráněny přihlášením a oprávněním a automatické veřejné URL k nim neexistují.

Důležité: fotografie může obsahovat identifikátory a adresy; pokud zapnete AI, obsah je odeslán službě Google Gemini. Zajistěte informování uživatelů a pravidla uchovávání/mazání fotografií odpovídající vašemu provozu. Před produkcí ověřte parametry zpracování dat dle účtu poskytovatele.

### Nastavení Google Gemini

1. Získejte Gemini API klíč v [Google AI Studio](https://aistudio.google.com/apikey).
2. Na WEDOSu do neveřejného souboru `.env` nastavte:

   ```ini
   GEMINI_API_KEY=VAS_API_KLIC
   GEMINI_VISION_MODEL=gemini-3.5-flash-lite
   ```

3. Položky `OPENAI_API_KEY` a `OPENAI_VISION_MODEL` můžete z `.env` odstranit: po této změně už je aplikace nepoužívá.
4. V aplikaci otevřete **Odečty → Vyfotit měřidlo**. Server pošle fotografii přes HTTPS službě Google Gemini. Návrh zkontrolujte a až poté potvrďte. Při nedostupnosti služby lze odečet zadat ručně.

Klíč nikdy nevkládejte do JavaScriptu ani do Git repozitáře. Model můžete změnit přes `GEMINI_VISION_MODEL` (musí podporovat obrazový vstup i JSON výstup). Výchozí model je `gemini-3.5-flash-lite`; nabídka modelů, limity a ceny se mohou měnit. Tato úprava **nevyžaduje databázovou migraci** a historické fotografie ani odečty nemění.

## GitHub vydání a aktualizace

Projekt má `.github/workflows/release.yml`: při každém pushnutí do větve `master` automaticky určí další opravnou verzi `vMAJOR.MINOR.PATCH`, provede lint a testy přímo na **PHP 7.3** a vytvoří GitHub Release s přílohami:

- `evidence-energii-v1.0.0.zip`
- `evidence-energii-v1.0.0.zip.sha256`

Stačí `git push origin master`. Workflow automaticky zvýší číslo PATCH oproti nejvyššímu stabilnímu tagu a základu uvedenému ve `VERSION` (např. z `v0.1.3-dev` vytvoří nejprve `v0.1.4`). Lze také ručně pushnout tag `v1.0.0` příkazem `git tag v1.0.0 && git push origin v1.0.0`; workflow podporuje i ruční spuštění přes Actions → Build and publish Metrio release → Run workflow.

**GitHub nastavení:** v repozitáři zkontrolujte Actions → General → Workflow permissions (token musí mít právo zapisovat `contents`, aby vytvořil Release). Workflow musí být committnuté do výchozí větve `master`. Výsledek a případné chyby najdete v záložce Actions. Automatický release nevznikne, pokud selže syntaxe nebo testy. Akce používá `GITHUB_TOKEN` a nevytváří další workflow smyčku.

Na serveru nastavte `GITHUB_REPOSITORY=uzivatel/repo`. Skript `php bin/deploy.php --check` zjistí nejnovější stabilní Release. `php bin/deploy.php --install --yes` jej stáhne a nasadí; totéž lze přes administrátorskou stránku **Aktualizace**.

Nasazení je povoleno jen z pevné domény GitHub a z očekávaného repozitáře. Vyžaduje release ZIP, SHA-256 kontrolní soubor a kontroluje případný `digest` z API. ZIP se bezpečně rozbalí a validuje. Poté se zálohují měněné soubory do `storage/deploy-backups`, kopírují nové soubory (jen whitelist adresářů), spustí migrace a aktualizuje se `VERSION`. **`.env`, `storage`, databáze a `.git` se nepřepisují**. Při selhání se kopírované soubory vracejí ze zálohy. Databázové DDL nelze automaticky rollbackovat — vždy před upgradem dělejte i zálohu MariaDB. Aktualizační skript nemaže soubory odstraněné v nových verzích, pro změny vyžadující mazání je nutný řízený postup.

Pro instalace z nepublikovaného/private repozitáře je nutné doplnit bezpečné GitHub API ověření; tato verze podporuje veřejné repozitáře a veřejné Release přílohy. Nasazení musí mít oprávnění zapisovat do aplikačních souborů (to je třeba ošetřit na hostingu). Release workflow nepřenáší `storage` ani `.env`.

## Bezpečnost a limity MVP

- Přihlášení používá `password_hash`, session cookies HttpOnly/SameSite, formuláře mají CSRF a SQL používá PDO prepared statements.
- Uživateli se zobrazují měřidla přiřazená jeho jednotce (i hlavní měřidlo s podružnými). Současně vidí pouze své vypočtené spotřeby a nikoli odečty sousedních jednotek. Správce objektu vidí všechna měřidla a odečty objektů, které mu administrátor přidělil.
- Administrátor spravuje všechny objekty, uživatele, měřidla i koeficienty. Může přidávat a odebírat uživatelům přístup k jednotkám i správcům přístup k objektům.
- Je nutné provozní HTTPS, nastavit zálohování souborů i databáze, limity požadavků u reverzní proxy a hlídání diskového prostoru. Serverové upload limity `upload_max_filesize` / `post_max_size` musí umožnit fotografie do 5 MB.
- Databázové výpočty jsou v aktuální verzi **on demand**, bez účetních uzávěrek a nezměnitelných fakturačních snapshotů. Nejsou implementovány peněžní tarify, schvalování odečtů, automatické mazání fotografií ani automatické dělení koeficientů při změně v průběhu období.
- Větší provoz si zaslouží obsluhu chyb ze serverových logů, automatizované zálohy, integrační testy proti skutečné MariaDB a případně background frontu na delší OCR požadavky.

## Testování

```bash
php tests/consumption.php
php tests/consumption_periods.php
php tests/permissions.php
find src bin public tests -type f -name '*.php' -exec php -l {} \;
```

Připravená testovací sada ověřuje poměrné odhady, odhady s omezením do 45 dnů, kalendářní dny, jednoduché odečtení, koeficient, víceúrovňová měřidla, záporný zbytek a chybějící odečet. Integrační testy MariaDB vyžadují běžící DB a nejsou zahrnuty do této samostatné distribuce.


## WEDOS webhosting (PHP 7.3, bez Dockeru a SSH) – od verze 0.1.4

Tato verze je napsaná pro **PHP 7.3+** a používá PDO MySQL, session, fileinfo.
Pro AI a aktualizace přes GitHub potřebujete cURL; pro aktualizace také ZipArchive a povolení zápisu do adresáře projektu.
Webhosting WEDOS má typicky veřejné adresáře `www` a `www/domains/nazev-domeny.cz`; nemůžete automaticky předpokládat možnost změnit document root na `/public`.

**Bezpečné nasazení přes FTP:**

1. V administraci WEDOS nastavte PHP 7.3 nebo novější (doporučuje se podporovaná 8.2+).
2. Do **kořene konkrétního webu** např. `www/domains/metrio.example.cz/` nahrajte celý projekt (`.htaccess`, `public/`, `src/`, `migrations/`, `bin/`, `VERSION`, `storage/`). **Nenahrazujte globální** `www/.htaccess` WEDOSu. Složky `storage/photos` a `storage/deploy-backups` musí být zapisovatelné pro PHP.
3. Vytvořte `.env` podle `.env.example`; nastavte databázový host/port/jméno/uživatele přesně podle WEDOS administrace (nikoli Docker hostname `mariadb`), HTTPS URL a unikátní `INSTALL_TOKEN` tvořený **64 hexadecimálními znaky** (např. výstup `openssl rand -hex 32`). Nevkládejte jej do GitHubu.
4. Otevřete `https://metrio.example.cz/install.php`, vyplňte instalační klíč a klikněte na **Spustit migrace**. Poté na stejné stránce vytvořte prvního administrátora.
5. **Neprodleně smažte `INSTALL_TOKEN` ze souboru `.env`.** Tím se `install.php` vypne (HTTP 404). Přihlaste se do aplikace.
6. Před provozem ověřte, že **`/src/bootstrap.php`, `/.env`, `/storage/photos/...`, `/migrations/001_initial.sql` odpovídají HTTP 403/404**, nikoli obsahem souboru. Pokud Apache nepoužívá `.htaccess`/mod_rewrite, nasazení takto není bezpečné – aplikaci nevystavujte do internetu.

Kořenový `.htaccess` bezpečně směruje `/` a `/index.php` do `public/index.php`, `/assets` do `public/assets` a `/install.php` do `public/install.php`. Privátní soubory se nesmí načítat přímo přes URL.

**Aktualizace:** po nastavení `GITHUB_REPOSITORY` může administrátor použít stránku **Aktualizace**; nasazení musí obsahovat GitHub Release asset `evidence-energii-vX.Y.Z.zip` a soubor `.sha256`, jaké vytváří `.github/workflows/release.yml`. Instalátor s tokenem není potřebný pro běžné GitHub aktualizace, protože updater spouští migrace automaticky. Když se aktualizace nevejde do časového limitu PHP nebo nepůjde přepisovat FTP soubory, nasaďte release balíček ručně přes FTP a použijte dočasný nový `INSTALL_TOKEN` pro jednorázové provedení migrací. Po nasazení jej opět odeberte.

**Bezpečnostní poznámka:** PHP 7.3 je výrobcem mimo bezpečnostní podporu. Pokud je to možné, vyberte na WEDOSu podporovanou verzi PHP; patch je kompatibilní také s PHP 8.x. AI odečty vyžadují internetový přístup na `generativelanguage.googleapis.com` a funkční cURL/SSL.


### Již provedené migrace po aktualizaci (od opravy v0.1.5)

Pokud soubor migrace (např. `002_object_managers.sql`) v novém releasu
nesouhlasí se součtem uloženým v `schema_migrations`, systém ji **znovu
nespustí a aktualizaci nezastaví**. Při kontrole `php bin/migrate.php --status`
zůstane rozdíl dohledatelný; při `php bin/migrate.php` bude označen jako
`PRESKOCENO (jiz provedena)`. Nové migrace se provedou obvyklým způsobem.
**Skutečné změny schématu se musí vždy zapisovat do nového SQL souboru
(např. `003_...sql`), nikoliv dodatečnou úpravou migrace 002.**

Před aktualizací si zazálohujte databázi – provedené DDL příkazy se v MariaDB
nemusí dát automaticky vrátit zpět.

## Moderní dashboard (Bootstrap 5)

Rozhraní používá lokálně uložený Bootstrap 5.3.6 (`public/assets/vendor/bootstrap.min.css`, licence MIT v sousedním souboru) a vlastní styly v `public/assets/app.css`. Nezávisí na CDN ani na npm a funguje na sdíleném hostingu WEDOS s PHP 7.3. Mobilní postranní navigaci zajišťuje nenáročný `public/assets/app.js` bez dalších knihoven.

- Navigace a oprávnění zůstávají serverově řízené podle rolí administrátor / správce objektu / uživatel.
- Dashboard používá pouze skutečné měřiče a dostupná data přístupných jednotek; při chybějících odečtech se zobrazí informace místo vymyšlených hodnot.
- Souhrn aktuálního měsíce a graf za posledních šest měsíců mohou zahrnovat odhadovanou spotřebu; stav je označený. Graf na přehledu ukazuje první dostupnou jednotku a energii, detail všech jednotek je na stránce Spotřeby a grafy.
- Všechny dosavadní akce, formuláře a databázové migrace jsou zachované; pro redesign není potřeba migrace databáze.

Kontrola po instalaci: `php tests/php73_compatibility.php` (v GitHub Actions se vykoná pod PHP 7.3).


## Uspořádání projektu

Nová modulární struktura projektu je popsaná v [ARCHITECTURE.md](ARCHITECTURE.md). HTTP routy, controllery a POST akce jsou v `src/Http` a `src/Modules`, jednotlivé HTML šablony v `templates/pages`. Podrobnosti v dokumentaci.

### Společná hlavička, menu a patička

Rozložení všech přihlášených obrazovek je ve `templates/layouts/` a `templates/partials/`.
Pro další stránku stačí přidat modul a `templates/pages/novastranka.php` s vlastním obsahem.
Navigace a její viditelnost podle rolí se nastavují v `src/View/Layout.php`.
Přihlášení a webový instalátor používají samostatný layout bez postranního menu.
