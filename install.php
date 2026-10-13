<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$root = __DIR__;
$lockFile = $root . '/uploads/install.lock';
$configFile = $root . '/config.local.php';
$sqlFile = $root . '/database/eduai.sql';
$bookSeedFile = $root . '/database/evs4_book_seed.sql';
$installed = is_file($lockFile);
$success = false;
$message = '';

function installer_value(string $key, string $fallback = ''): string
{
    return htmlspecialchars((string)($_POST[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $adminEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    try {
        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('MySQL host, database name and database user are required.');
        }
        if ($dbPort < 1 || $dbPort > 65535) {
            throw new RuntimeException('MySQL port is invalid.');
        }
        if ($adminName === '' || mb_strlen($adminName) > 100) {
            throw new RuntimeException('Enter an administrator name of 100 characters or fewer.');
        }
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid administrator email address.');
        }
        if (strlen($adminPassword) < 10 || strlen($adminPassword) > 128) {
            throw new RuntimeException('Administrator password must contain 10–128 characters.');
        }
        if (!is_file($sqlFile)) {
            throw new RuntimeException('database/eduai.sql is missing from the upload.');
        }
        if (!is_dir($root . '/uploads') || !is_writable($root . '/uploads')) {
            throw new RuntimeException('The uploads folder is not writable. Set its permission to 755 or 775.');
        }
        if (!is_writable($root)) {
            throw new RuntimeException('public_html is not writable, so config.local.php cannot be created.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        foreach ([$sqlFile, $bookSeedFile] as $importFile) {
            if (!is_file($importFile)) {
                continue;
            }
            $sql = file_get_contents($importFile);
            if ($sql === false) {
                throw new RuntimeException('A required SQL file could not be read.');
            }
            $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        }

        $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, role)
             VALUES (?, ?, ?, "admin")
             ON DUPLICATE KEY UPDATE
             name = VALUES(name), password = VALUES(password), role = "admin"'
        );
        $stmt->execute([$adminName, $adminEmail, $passwordHash]);

        $config = [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'app_secret' => bin2hex(random_bytes(48)),
        ];
        $configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configFile, $configPhp, LOCK_EX) === false) {
            throw new RuntimeException('config.local.php could not be written.');
        }
        @chmod($configFile, 0600);

        $lockContents = 'EduAI installed at ' . gmdate('c') . "\n";
        if (file_put_contents($lockFile, $lockContents, LOCK_EX) === false) {
            @unlink($configFile);
            throw new RuntimeException('The installer lock could not be created.');
        }

        $success = true;
        $installed = true;
        $message = 'Installation complete. Your database, secure configuration and administrator login are ready.';
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>EduAI Setup</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:36px 18px;background:linear-gradient(135deg,#fffaf2,#f1f4ff);color:#293048;font-family:Segoe UI,Arial,sans-serif}
    .shell{width:min(860px,100%);margin:auto}.brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}.logo{width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:#5a49ad;color:#fff;font-weight:900}.brand h1{margin:0;font-size:25px}.brand p{margin:3px 0 0;color:#747c90;font-size:12px}
    .card{overflow:hidden;border:1px solid #dfe2eb;border-radius:20px;background:#fff;box-shadow:0 18px 50px rgba(46,48,90,.11)}header{padding:28px 32px;background:linear-gradient(135deg,#f7f5ff,#eff8ff);border-bottom:1px solid #e2e4ed}header span{color:#6553b1;font-size:10px;font-weight:900;letter-spacing:1.3px}header h2{margin:7px 0;color:#252b40;font-size:29px}header p{margin:0;color:#687186;font-size:13px;line-height:1.6}
    form{padding:30px 32px}.section{margin-bottom:28px}.section h3{margin:0 0 4px;font-size:16px}.section>p{margin:0 0 16px;color:#7b8292;font-size:12px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.full{grid-column:1/-1}label{display:grid;gap:7px;color:#4b5265;font-size:11px;font-weight:800}input{width:100%;padding:12px 13px;border:1px solid #d8dce6;border-radius:10px;background:#fafbfe;color:#30374b;font:inherit}input:focus{outline:3px solid #e4dfff;border-color:#765ee0}
    button{width:100%;padding:14px;border:0;border-radius:11px;color:#fff;background:#624fd0;font-size:13px;font-weight:900;cursor:pointer}.notice{margin:0 32px 26px;padding:14px 16px;border-radius:11px;font-size:12px;line-height:1.6}.error{color:#922e35;background:#fff0f0}.ok{color:#236140;background:#eaf8ef}.locked{padding:34px}.locked a{display:inline-block;margin-top:12px;padding:12px 18px;border-radius:10px;color:#fff;background:#5948aa;text-decoration:none;font-weight:800}
    @media(max-width:650px){body{padding:18px 12px}.grid{grid-template-columns:1fr}.full{grid-column:auto}header,form,.locked{padding:24px 20px}.notice{margin:0 20px 22px}}
  </style>
</head>
<body>
<main class="shell">
  <div class="brand"><div class="logo">AI</div><div><h1>EduAI Setup</h1><p>PHP 8.2 + MySQL installation assistant</p></div></div>
  <div class="card">
    <header><span>ONE-TIME SECURE INSTALLER</span><h2>Connect database and create your login</h2><p>No file editing is required. Use the MySQL details created in cPanel and choose your own administrator email and password.</p></header>
    <?php if ($installed): ?>
      <div class="locked">
        <h2><?= $success ? 'Installation complete' : 'EduAI is already installed' ?></h2>
        <p><?= htmlspecialchars($message ?: 'The installer is locked for security.', ENT_QUOTES, 'UTF-8') ?></p>
        <a href="./">Open EduAI</a>
      </div>
    <?php else: ?>
      <form method="post" autocomplete="off">
        <section class="section">
          <h3>MySQL database</h3><p>Copy these values from cPanel → MySQL Databases.</p>
          <div class="grid">
            <label>Database host<input name="db_host" required value="<?= installer_value('db_host','localhost') ?>"></label>
            <label>Port<input name="db_port" type="number" required value="<?= installer_value('db_port','3306') ?>"></label>
            <label>Database name<input name="db_name" required value="<?= installer_value('db_name') ?>"></label>
            <label>Database username<input name="db_user" required value="<?= installer_value('db_user') ?>"></label>
            <label class="full">Database password<input name="db_pass" type="password" value=""></label>
          </div>
        </section>
        <section class="section">
          <h3>Your administrator login</h3><p>You will use these details on the EduAI login screen with the Admin role.</p>
          <div class="grid">
            <label>Full name<input name="admin_name" required maxlength="100" value="<?= installer_value('admin_name') ?>"></label>
            <label>Email<input name="admin_email" type="email" required value="<?= installer_value('admin_email') ?>"></label>
            <label class="full">Password<input name="admin_password" type="password" minlength="10" maxlength="128" required></label>
          </div>
        </section>
        <?php if ($message): ?><p class="notice error"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <button type="submit">Install EduAI Securely</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
