<?php
declare(strict_types=1);

/*
 * config.local.php is created automatically by install.php.
 * Manual configuration remains supported by creating that file with the
 * same array keys used below.
 */
$localConfigFile = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigFile) ? require $localConfigFile : [];

define('DB_HOST', (string)($localConfig['db_host'] ?? 'localhost'));
define('DB_PORT', (int)($localConfig['db_port'] ?? 3306));
define('DB_NAME', (string)($localConfig['db_name'] ?? ''));
define('DB_USER', (string)($localConfig['db_user'] ?? ''));
define('DB_PASS', (string)($localConfig['db_pass'] ?? ''));
define('APP_SECRET', (string)($localConfig['app_secret'] ?? 'NOT_INSTALLED'));
const APP_ENV = 'production';
const TOKEN_LIFETIME_SECONDS = 86400;
const MAX_BOOK_UPLOAD_BYTES = 104857600;
const MAX_ANSWER_KEY_UPLOAD_BYTES = 15728640;

date_default_timezone_set('Asia/Kolkata');
