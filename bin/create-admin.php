#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404);exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$email=strtolower(trim($argv[1]??''));
if(!filter_var($email,FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"Použití: php bin/create-admin.php admin@example.cz\n");exit(64); }
if(!defined('STDIN')) exit(1);
fwrite(STDOUT,"Jméno administrátora: ");$name=trim((string)fgets(STDIN));
fwrite(STDOUT,"Heslo (zadávání může být viditelné): ");
if(PHP_OS_FAMILY!=='Windows' && function_exists('shell_exec')) @shell_exec('stty -echo < /dev/tty 2>/dev/null');
$password=rtrim((string)fgets(STDIN),"\r\n");
if(PHP_OS_FAMILY!=='Windows' && function_exists('shell_exec')) @shell_exec('stty echo < /dev/tty 2>/dev/null');
echo PHP_EOL;
if($name==='' || strlen($password)<12) { fwrite(STDERR,"Zadejte jméno a heslo dlouhé alespoň 12 znaků.\n");exit(1); }
try {
    $x=sql('SELECT id FROM users WHERE email=?',[$email]);
    if($x->fetch()) { fwrite(STDERR,"Účet již existuje.\n");exit(1); }
    sql('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),'admin']);
    echo "Administrátor vytvořen.\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1); }
