<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Instalace bez SSH (WEDOS): přístup jen s dlouhým jednorázovým INSTALL_TOKEN z .env.
$token = config('INSTALL_TOKEN');
if (!preg_match('/^[a-f0-9]{64}$/iD', $token)) {
    http_response_code(404);
    exit('Instalátor není povolen.');
}
if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Aplikace vyžaduje PHP 7.4 nebo novější.');
}
start_session();
$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    check_csrf();
    try {
        if (!hash_equals($token, (string) ($_POST['install_token'] ?? ''))) {
            throw new RuntimeException('Neplatný instalační klíč.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'migrate') {
            foreach (['storage', 'storage/photos', 'storage/deploy-backups'] as $directory) {
                $path = APP_ROOT . '/' . $directory;
                if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
                    throw new RuntimeException('Nelze vytvořit zapisovatelný adresář ' . $directory);
                }
            }
            $migrations = (new Energy\Migrations(db(), APP_ROOT . '/migrations'))->run();
            $notice = $migrations ? 'Provedené migrace: ' . implode(', ', $migrations) : 'Databáze už je aktuální.';
        } elseif ($action === 'create_admin') {
            // Nesmí vzniknout druhý admin přes veřejný instalátor.
            if (one("SELECT id FROM users WHERE role='admin' LIMIT 1")) {
                throw new RuntimeException('Administrátor již existuje. Další účty zakládejte v aplikaci.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                throw new RuntimeException('Zadejte jméno, platný e-mail a heslo alespoň 12 znaků.');
            }
            sql('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
            $notice = 'Administrátor vytvořen. Odstraňte INSTALL_TOKEN z .env a přihlaste se.';
        } else {
            throw new RuntimeException('Neznámá instalační operace.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        http_response_code(400);
    }
}

page_header('Instalace Metrio');
echo '<section class="card"><h2>WEDOS / PHP ' . html(PHP_VERSION) . '</h2>';
echo '<p class="muted">Instalační klíč není nikdy zobrazován. Po založení administrátora smažte INSTALL_TOKEN ze souboru .env, čímž instalátor zablokujete.</p>';
if ($notice !== '') echo '<div class="flash">' . html($notice) . '</div>';
if ($error !== '') echo '<div class="flash">' . html($error) . '</div>';
echo '<p>Rozšíření: PDO MySQL ' . (extension_loaded('pdo_mysql') ? 'OK' : 'CHYBÍ')
    . ', fileinfo ' . (extension_loaded('fileinfo') ? 'OK' : 'CHYBÍ')
    . ', cURL ' . (extension_loaded('curl') ? 'OK' : 'CHYBÍ (AI a update)')
    . ', ZIP ' . (class_exists('ZipArchive') ? 'OK' : 'CHYBÍ (update)') . '.</p>';
echo '<form method="post">' . post_token()
    . '<label>Instalační klíč <input type="password" name="install_token" required autocomplete="off"></label>'
    . '<input type="hidden" name="action" value="migrate"><button>Spustit migrace</button></form>';
echo '</section><section class="card"><h2>Vytvořit prvního administrátora</h2>';
echo '<p class="muted">Tento formulář funguje pouze v případě, že ještě žádný administrátor neexistuje. Nejprve proveďte migrace.</p>';
echo '<form method="post">' . post_token()
    . '<label>Instalační klíč <input type="password" name="install_token" required autocomplete="off"></label>'
    . '<label>Jméno <input name="name" required maxlength="150"></label>'
    . '<label>E-mail <input type="email" name="email" required maxlength="190"></label>'
    . '<label>Heslo (min. 12 znaků) <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>'
    . '<input type="hidden" name="action" value="create_admin"><button>Vytvořit administrátora</button></form>';
echo '</section>';
page_footer();
