<?php
declare(strict_types=1);

/**
 * DevBridge Installer
 * Run once, then is locked automatically.
 */

define('LOCK_FILE', __DIR__ . '/storage/installed.lock');
define('CONFIG_FILE', __DIR__ . '/config/config.php');

// Already installed?
if (file_exists(LOCK_FILE)) {
    header('Location: login.php');
    exit;
}

$errors   = [];
$success  = false;

// -----------------------------------------------------------------------
// Installer translation helper (Russian by default, no config/autoloader needed)
// -----------------------------------------------------------------------
function instT(string $key, array $params = []): string
{
    static $strings = null;
    if ($strings === null) {
        $file = __DIR__ . '/resources/lang/ru/install.php';
        $strings = file_exists($file) ? (require $file) : [];
    }
    $value = $strings[$key] ?? $key;
    foreach ($params as $k => $v) {
        $value = str_replace(':' . $k, (string)$v, $value);
    }
    return $value;
}

// -----------------------------------------------------------------------
// Requirement checks
// -----------------------------------------------------------------------
function checkRequirements(): array
{
    $issues = [];
    if (PHP_VERSION_ID < 80100) {
        $issues[] = 'PHP 8.1+ required. Current: ' . PHP_VERSION;
    }
    foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl'] as $ext) {
        if (!extension_loaded($ext)) {
            $issues[] = "PHP extension '$ext' is not loaded.";
        }
    }
    return $issues;
}

// -----------------------------------------------------------------------
// Auto-detect default app_url
// -----------------------------------------------------------------------
function detectDefaultAppUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/install.php');
    $dir    = dirname($script);
    $base   = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $base;
}

// -----------------------------------------------------------------------
// Normalize and validate app_url using parse_url (tolerates common typos)
// -----------------------------------------------------------------------
function normalizeAppUrl(string $url): array
{
    // Decode HTML entities (e.g. copy-pasted from a browser address bar via HTML page)
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $url = trim($url);

    // Strip UTF-8 BOM
    $url = preg_replace('/^\xEF\xBB\xBF/', '', $url);
    // Strip control characters
    $url = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$url);
    // Normalize unicode variants of backslash, semicolon, colon, slash
    $url = str_replace(["\\", "\u{FF1B}", "\u{FF1A}"], ['/', ';', ':'], (string)$url);
    $url = str_replace(["\u{FF0F}", "\u{2044}", "\u{2215}"], '/', $url);

    // Fix common typos: http;// https;// http:/ https:/
    $url = preg_replace('#^\s*(https?)\s*[;:]\s*/\s*/#iu', '$1://', $url);
    $url = preg_replace('#^\s*(https?)\s*/\s*/#iu',         '$1://', $url);
    $url = preg_replace('#^\s*(https?)\s*:\s*/(?!/)#iu',    '$1://', $url);

    // If no scheme, prepend current request scheme
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url    = $scheme . '://' . ltrim($url, '/');
    }

    $url = rtrim($url, '/');

    if ($url === '') {
        return [
            'ok'    => false,
            'url'   => '',
            'error' => instT('err_url_required'),
        ];
    }

    $parts = parse_url($url);

    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return [
            'ok'    => false,
            'url'   => '',
            'error' => instT('err_url_domain'),
        ];
    }

    $scheme = strtolower((string)$parts['scheme']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return [
            'ok'    => false,
            'url'   => '',
            'error' => instT('err_url_scheme'),
        ];
    }

    if (isset($parts['query']) && $parts['query'] !== '') {
        return [
            'ok'    => false,
            'url'   => '',
            'error' => instT('err_url_query'),
        ];
    }

    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        return [
            'ok'    => false,
            'url'   => '',
            'error' => instT('err_url_fragment'),
        ];
    }

    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = isset($parts['path']) ? '/' . trim((string)$parts['path'], '/') : '';
    if ($path === '/') {
        $path = '';
    }

    $normalized = $scheme . '://' . $host . $port . $path;

    return [
        'ok'    => true,
        'url'   => $normalized,
        'error' => null,
    ];
}

$reqErrors = checkRequirements();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($reqErrors)) {
        $errors = $reqErrors;
    } else {
        $dbHost     = trim($_POST['db_host']     ?? '127.0.0.1');
        $dbPort     = trim($_POST['db_port']     ?? '3306');
        $dbName     = trim($_POST['db_name']     ?? 'devbridge');
        $dbUser     = trim($_POST['db_user']     ?? '');
        $dbPass     = $_POST['db_pass']          ?? '';
        $appUrlRaw  = (string)($_POST['app_url'] ?? '');
        $appUrlInfo = normalizeAppUrl($appUrlRaw);
        $appUrl     = $appUrlInfo['ok'] ? $appUrlInfo['url'] : rtrim(trim($appUrlRaw), '/');
        $adminUser  = trim($_POST['admin_user']  ?? 'admin');
        $adminPass  = $_POST['admin_pass']       ?? '';
        $adminEmail = trim($_POST['admin_email'] ?? '');

        // Basic validation
        if (!$dbUser)   $errors[] = instT('err_db_user');
        if (!$adminUser) $errors[] = instT('err_admin_user');
        if (strlen($adminPass) < 8) $errors[] = instT('err_admin_pass');
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $adminUser)) {
            $errors[] = instT('err_admin_user_chars');
        }
        if (!$appUrlInfo['ok']) {
            $errors[] = $appUrlInfo['error'];
        }

        if (empty($errors)) {
            try {
                $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // Create database
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$dbName`");

                // Run schema — strip comments first so chunks never start with -- or #
                $sql = file_get_contents(__DIR__ . '/config/schema.sql');
                $sql = preg_replace('/^\s*--.*$/m', '', (string)$sql);
                $sql = preg_replace('/^\s*#.*$/m',  '', (string)$sql);
                $sql = trim((string)$sql);

                foreach (explode(';', $sql) as $statement) {
                    $statement = trim($statement);
                    if ($statement === '') {
                        continue;
                    }
                    $pdo->exec($statement);
                }

                // Verify required tables were created
                $requiredTables = [
                    'admins', 'settings', 'projects', 'repositories',
                    'project_rules', 'roadmap_branches', 'roadmap_versions',
                    'roadmap_change_proposals', 'dev_tasks', 'dev_task_messages',
                    'dev_task_reviews', 'operator_decisions', 'github_events', 'logs',
                ];
                $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($requiredTables as $tbl) {
                    if (!in_array($tbl, $existingTables, true)) {
                        throw new \RuntimeException(instT('err_table_missing', ['table' => $tbl]));
                    }
                }

                // Create admin user (only after verifying admins table exists)
                $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, email) VALUES (?, ?, ?)');
                $stmt->execute([$adminUser, $hash, $adminEmail]);

                // Seed default settings (Gemini provider, safe no-auto-merge)
                $defaultSettings = [
                    'ai_provider'           => 'gemini',
                    'ai_profile'            => 'balanced',
                    'gemini_economy_model'  => 'gemini-2.5-flash-lite',
                    'gemini_balanced_model' => 'gemini-2.5-flash',
                    'gemini_strong_model'   => 'gemini-2.5-pro',
                    'gemini_json_repair_model' => 'gemini-2.5-flash-lite',
                    'planner_temperature'   => '0.4',
                    'critic_temperature'    => '0.2',
                    'reviewer_temperature'  => '0.1',
                    'max_output_tokens'     => '8192',
                    'max_prompt_chars'      => '32000',
                    'no_auto_merge'         => '1',
                ];
                $seedStmt = $pdo->prepare(
                    'INSERT IGNORE INTO settings (key_name, value) VALUES (?, ?)'
                );
                foreach ($defaultSettings as $k => $v) {
                    $seedStmt->execute([$k, $v]);
                }

                // Generate app secret
                $appSecret = bin2hex(random_bytes(32));

                // Write config using var_export so special characters in passwords are safe
                $configData = [
                    'app_url'    => $appUrl,
                    'db_host'    => $dbHost,
                    'db_port'    => $dbPort,
                    'db_name'    => $dbName,
                    'db_user'    => $dbUser,
                    'db_pass'    => $dbPass,
                    'app_secret' => $appSecret,
                    'installed'  => true,
                ];
                $configContent  = "<?php\nreturn " . var_export($configData, true) . ";\n";
                file_put_contents(CONFIG_FILE, $configContent);

                // Create storage subdirs
                foreach (['logs', 'cache', 'tmp', 'backups', 'snapshots'] as $dir) {
                    $path = __DIR__ . '/storage/' . $dir;
                    if (!is_dir($path)) {
                        mkdir($path, 0750, true);
                    }
                }

                // Write .htaccess for storage (deny all browser access)
                file_put_contents(
                    __DIR__ . '/storage/.htaccess',
                    "Order Deny,Allow\nDeny from all\n"
                );

                // Lock installer
                file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));

                $success = true;
            } catch (PDOException $e) {
                $errors[] = instT('err_db', ['message' => $e->getMessage()]);
            } catch (Throwable $e) {
                $errors[] = instT('err_db', ['message' => $e->getMessage()]);
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DevBridge — <?= htmlspecialchars(instT('title'), ENT_QUOTES, 'UTF-8') ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #1e293b; border-radius: 12px; padding: 40px; width: 100%; max-width: 560px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
  h1 { margin: 0 0 8px; font-size: 1.8rem; color: #7c3aed; }
  .subtitle { color: #94a3b8; margin: 0 0 30px; font-size: 0.95rem; }
  .group { margin-bottom: 18px; }
  label { display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 6px; }
  input { width: 100%; padding: 10px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; font-size: 0.95rem; }
  input:focus { outline: none; border-color: #7c3aed; }
  .btn { width: 100%; padding: 12px; background: #7c3aed; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 10px; }
  .btn:hover { background: #6d28d9; }
  .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
  .alert-danger { background: #450a0a; border: 1px solid #7f1d1d; color: #fca5a5; }
  .alert-success { background: #052e16; border: 1px solid #14532d; color: #86efac; }
  .section-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #64748b; margin: 24px 0 12px; }
  .check-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 0.9rem; }
  .check-item .ok { color: #4ade80; }
  .check-item .fail { color: #f87171; }
</style>
</head>
<body>
<div class="card">
  <h1>⚙ DevBridge</h1>
  <p class="subtitle"><?= htmlspecialchars(instT('title'), ENT_QUOTES, 'UTF-8') ?></p>

  <?php if ($success): ?>
    <div class="alert alert-success">
      ✅ <?= htmlspecialchars(instT('success', ['url' => $appUrl ?? '']), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <a href="login.php" style="display:block;text-align:center;padding:12px;background:#7c3aed;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Войти →</a>
  <?php else: ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <strong><?= htmlspecialchars(instT('errors_title'), ENT_QUOTES, 'UTF-8') ?>:</strong><br>
      <?php foreach ($errors as $e): ?>
        • <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-title"><?= htmlspecialchars(instT('requirements'), ENT_QUOTES, 'UTF-8') ?></div>
  <?php
  $checks = [
      'PHP 8.1+'                                => PHP_VERSION_ID >= 80100,
      'pdo'                                     => extension_loaded('pdo'),
      'pdo_mysql'                               => extension_loaded('pdo_mysql'),
      'curl'                                    => extension_loaded('curl'),
      'json'                                    => extension_loaded('json'),
      'mbstring'                                => extension_loaded('mbstring'),
      'openssl'                                 => extension_loaded('openssl'),
      htmlspecialchars(instT('storage_writable'), ENT_QUOTES, 'UTF-8') => is_writable(__DIR__ . '/storage') || mkdir(__DIR__ . '/storage', 0750, true),
  ];
  foreach ($checks as $label => $pass): ?>
    <div class="check-item">
      <?php if ($pass): ?><span class="ok">✔</span><?php else: ?><span class="fail">✘</span><?php endif; ?>
      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endforeach; ?>

  <form method="post">
    <div class="section-title"><?= htmlspecialchars(instT('app_section'), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="group">
      <label><?= htmlspecialchars(instT('app_url_label'), ENT_QUOTES, 'UTF-8') ?> <small style="color:#64748b">(<?= htmlspecialchars(instT('no_trailing_slash'), ENT_QUOTES, 'UTF-8') ?>)</small></label>
      <input type="text" name="app_url"
             value="<?= htmlspecialchars(isset($appUrlInfo) && $appUrlInfo['ok'] ? $appUrlInfo['url'] : ($_POST['app_url'] ?? detectDefaultAppUrl()), ENT_QUOTES, 'UTF-8') ?>"
             placeholder="http://example.com">
      <small style="color:#64748b"><?= htmlspecialchars(instT('app_url_hint'), ENT_QUOTES, 'UTF-8') ?></small>
    </div>

    <div class="section-title"><?= htmlspecialchars(instT('db_section'), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="group">
      <label><?= htmlspecialchars(instT('db_host'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('db_port'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('db_name'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'devbridge', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('db_user'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('db_pass'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="password" name="db_pass">
    </div>

    <div class="section-title"><?= htmlspecialchars(instT('admin_section'), ENT_QUOTES, 'UTF-8') ?></div>
    <div class="group">
      <label><?= htmlspecialchars(instT('admin_user'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('admin_pass'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="password" name="admin_pass">
    </div>
    <div class="group">
      <label><?= htmlspecialchars(instT('admin_email'), ENT_QUOTES, 'UTF-8') ?></label>
      <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <button type="submit" class="btn"><?= htmlspecialchars(instT('btn_install'), ENT_QUOTES, 'UTF-8') ?></button>
  </form>

  <?php endif; ?>
</div>
</body>
</html>
