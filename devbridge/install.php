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
        $appUrl     = rtrim(trim($_POST['app_url'] ?? ''), '/');
        $adminUser  = trim($_POST['admin_user']  ?? 'admin');
        $adminPass  = $_POST['admin_pass']       ?? '';
        $adminEmail = trim($_POST['admin_email'] ?? '');

        // Basic validation
        if (!$dbUser)   $errors[] = 'Database user is required.';
        if (!$adminUser) $errors[] = 'Admin username is required.';
        if (strlen($adminPass) < 8) $errors[] = 'Admin password must be at least 8 characters.';
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $adminUser)) {
            $errors[] = 'Admin username may only contain letters, digits, underscores, and hyphens.';
        }
        if (!preg_match('#^https?://[^/?#\s]+(/[^?#\s]*)?$#i', $appUrl)) {
            $errors[] = 'Application Base URL must be a valid http or https URL without query string or hash.';
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
                        throw new \RuntimeException("Required table was not created: $tbl");
                    }
                }

                // Create admin user (only after verifying admins table exists)
                $hash = password_hash($adminPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, email) VALUES (?, ?, ?)');
                $stmt->execute([$adminUser, $hash, $adminEmail]);

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
                $errors[] = 'Database error: ' . $e->getMessage();
            } catch (Throwable $e) {
                $errors[] = 'Installation error: ' . $e->getMessage();
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DevBridge Installer</title>
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
  <p class="subtitle">First-time installation wizard</p>

  <?php if ($success): ?>
    <div class="alert alert-success">
      ✅ Installation complete! Your DevBridge is ready.
    </div>
    <a href="login.php" style="display:block;text-align:center;padding:12px;background:#7c3aed;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Go to Login →</a>
  <?php else: ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <strong>Errors:</strong><br>
      <?php foreach ($errors as $e): ?>
        • <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-title">System Requirements</div>
  <?php
  $checks = [
      'PHP 8.1+'   => PHP_VERSION_ID >= 80100,
      'pdo'        => extension_loaded('pdo'),
      'pdo_mysql'  => extension_loaded('pdo_mysql'),
      'curl'       => extension_loaded('curl'),
      'json'       => extension_loaded('json'),
      'mbstring'   => extension_loaded('mbstring'),
      'openssl'    => extension_loaded('openssl'),
      'Storage writable' => is_writable(__DIR__ . '/storage') || mkdir(__DIR__ . '/storage', 0750, true),
  ];
  foreach ($checks as $label => $pass): ?>
    <div class="check-item">
      <?php if ($pass): ?><span class="ok">✔</span><?php else: ?><span class="fail">✘</span><?php endif; ?>
      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endforeach; ?>

  <form method="post">
    <div class="section-title">Application</div>
    <div class="group">
      <label>Application Base URL <small style="color:#64748b">(no trailing slash)</small></label>
      <input type="url" name="app_url"
             value="<?= htmlspecialchars($_POST['app_url'] ?? detectDefaultAppUrl(), ENT_QUOTES, 'UTF-8') ?>"
             placeholder="http://example.com">
      <small style="color:#64748b">Domain root, subdomain, or subfolder. e.g. https://dev.example.com or https://example.com/devbridge</small>
    </div>

    <div class="section-title">Database</div>
    <div class="group">
      <label>Host</label>
      <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label>Port</label>
      <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label>Database Name</label>
      <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'devbridge', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label>DB User</label>
      <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label>DB Password</label>
      <input type="password" name="db_pass">
    </div>

    <div class="section-title">First Admin Account</div>
    <div class="group">
      <label>Username</label>
      <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="group">
      <label>Password (min 8 chars)</label>
      <input type="password" name="admin_pass">
    </div>
    <div class="group">
      <label>Email (optional)</label>
      <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <button type="submit" class="btn">Install DevBridge</button>
  </form>

  <?php endif; ?>
</div>
</body>
</html>
