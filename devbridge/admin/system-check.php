<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

// -----------------------------------------------------------------------
// Gather diagnostics
// -----------------------------------------------------------------------

// PHP version
$phpOk      = PHP_VERSION_ID >= 80100;
$phpVersion = PHP_VERSION;

// Required extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl'];
$extStatus = [];
foreach ($requiredExtensions as $ext) {
    $extStatus[$ext] = extension_loaded($ext);
}

// Config file
$configExists = file_exists(CONFIG_PATH . '/config.php');

// Installed lock
$lockExists = file_exists(STORAGE_PATH . '/installed.lock');

// Database connection and required tables
$requiredTables = [
    'admins', 'settings', 'projects', 'repositories',
    'project_rules', 'roadmap_branches', 'roadmap_versions',
    'roadmap_change_proposals', 'dev_tasks', 'dev_task_messages',
    'dev_task_reviews', 'operator_decisions', 'github_events', 'logs',
];
$dbConnected   = false;
$dbError       = '';
$tableStatus   = [];

try {
    $db          = Database::getInstance();
    $dbConnected = true;
    $existing    = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredTables as $tbl) {
        $tableStatus[$tbl] = in_array($tbl, $existing, true);
    }
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
}

// Storage directories
$storageDirs = ['', 'logs', 'cache', 'tmp', 'backups'];
$storageStatus = [];
foreach ($storageDirs as $sub) {
    $path = STORAGE_PATH . ($sub !== '' ? '/' . $sub : '');
    $storageStatus[$sub !== '' ? $sub : '(root)'] = is_writable($path);
}

// Secrets configured (show only yes/no, never the value)
$githubConfigured      = false;
$openrouterConfigured  = false;
if ($dbConnected) {
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
        $stmt->execute(['github_token']);
        $row = $stmt->fetch();
        $githubConfigured = !empty($row['value']);

        $stmt->execute(['openrouter_api_key']);
        $row = $stmt->fetch();
        $openrouterConfigured = !empty($row['value']);
    } catch (\Throwable) {
        // ignore
    }
}

$pageTitle = 'System Check';
$activeNav = 'system-check';
require APP_ROOT . '/views/layout.php';

function checkRow(string $label, bool $ok, string $detail = ''): void
{
    $icon  = $ok ? '<span style="color:#4ade80">✔</span>' : '<span style="color:#f87171">✘</span>';
    $extra = $detail !== '' ? ' <span style="color:#94a3b8;font-size:0.85rem">– ' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</span>' : '';
    echo '<tr><td>' . $icon . '</td><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $extra . '</td></tr>' . "\n";
}
?>

<div class="card" style="max-width:760px">
  <div class="card-title">PHP &amp; Extensions</div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow('PHP 8.1+', $phpOk, 'current: ' . $phpVersion); ?>
    <?php foreach ($extStatus as $ext => $ok): checkRow($ext, $ok); endforeach; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title">URL &amp; Path Constants</div>
  <table style="width:100%;border-collapse:collapse">
    <tr><td style="color:#94a3b8;font-size:0.85rem;width:160px">BASE_URL</td><td><?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:#94a3b8;font-size:0.85rem">BASE_PATH</td><td><?= htmlspecialchars(BASE_PATH, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:#94a3b8;font-size:0.85rem">APP_PATH</td><td><?= htmlspecialchars(APP_PATH, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:#94a3b8;font-size:0.85rem">STORAGE_PATH</td><td><?= htmlspecialchars(STORAGE_PATH, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:#94a3b8;font-size:0.85rem">CONFIG_PATH</td><td><?= htmlspecialchars(CONFIG_PATH, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:#94a3b8;font-size:0.85rem">PUBLIC_PATH</td><td><?= htmlspecialchars(PUBLIC_PATH, ENT_QUOTES, 'UTF-8') ?></td></tr>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title">Installation Files</div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow('config/config.php exists', $configExists); ?>
    <?php checkRow('storage/installed.lock exists', $lockExists); ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title">Database</div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow('Connection', $dbConnected, $dbError ?: ''); ?>
    <?php if ($dbConnected): ?>
      <?php foreach ($tableStatus as $tbl => $ok): checkRow('Table: ' . $tbl, $ok); endforeach; ?>
    <?php endif; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title">Storage Writable</div>
  <table style="width:100%;border-collapse:collapse">
    <?php foreach ($storageStatus as $label => $ok): checkRow('storage/' . $label, $ok); endforeach; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title">Secrets Configured</div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow('GitHub Token', $githubConfigured, $githubConfigured ? 'configured (masked)' : 'not set'); ?>
    <?php checkRow('OpenRouter API Key', $openrouterConfigured, $openrouterConfigured ? 'configured (masked)' : 'not set'); ?>
  </table>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
