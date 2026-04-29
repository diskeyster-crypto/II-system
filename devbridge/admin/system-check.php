<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Settings;
use DevBridge\AI\GeminiClient;
use DevBridge\Services\GitHubService;
use DevBridge\Core\Logger;

Auth::requireLogin();

// -----------------------------------------------------------------------
// Handle integration test actions
// -----------------------------------------------------------------------
$testResult = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'test_gemini') {
        try {
            $ai     = new GeminiClient();
            $result = $ai->chatJson([
                ['role' => 'system', 'content' => 'You are a health check endpoint. Return JSON only.'],
                ['role' => 'user',   'content' => 'Return {"ok":true,"provider":"gemini"}'],
            ]);
            if (!empty($result['ok'])) {
                $testResult['gemini'] = ['ok' => true, 'msg' => t('system.gemini_ok')];
            } else {
                $testResult['gemini'] = ['ok' => false, 'msg' => t('system.gemini_fail') . ': unexpected response'];
            }
            Logger::log('ai_request', 'System check: Gemini test OK');
        } catch (\Throwable $e) {
            $testResult['gemini'] = ['ok' => false, 'msg' => t('system.gemini_fail') . ': ' . $e->getMessage()];
            Logger::log('error', 'System check: Gemini test failed: ' . $e->getMessage());
        }
    } elseif ($action === 'test_github') {
        try {
            $gh   = new GitHubService();
            $user = $gh->testConnection();
            $testResult['github'] = ['ok' => true, 'msg' => t('system.github_ok') . ' (@' . ($user['login'] ?? '?') . ')'];
            Logger::log('github_request', 'System check: GitHub test OK');
        } catch (\Throwable $e) {
            $testResult['github'] = ['ok' => false, 'msg' => t('system.github_fail') . ': ' . $e->getMessage()];
            Logger::log('error', 'System check: GitHub test failed: ' . $e->getMessage());
        }
    }
}

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
$githubConfigured  = false;
$geminiConfigured  = false;
if ($dbConnected) {
    try {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
        $stmt->execute(['github_token']);
        $row = $stmt->fetch();
        $githubConfigured = !empty($row['value']);

        $stmt->execute(['gemini_api_key']);
        $row = $stmt->fetch();
        $geminiConfigured = !empty($row['value']);
    } catch (\Throwable) {
        // ignore
    }
}

$pageTitle = t('system.title');
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
  <div class="card-title"><?= e(t('system.php_ext')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow(t('system.php_version'), $phpOk, t('system.php_current') . ': ' . $phpVersion); ?>
    <?php foreach ($extStatus as $ext => $ok): checkRow($ext, $ok); endforeach; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.url_constants')) ?></div>
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
  <div class="card-title"><?= e(t('system.install_files')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow(t('system.config_exists'), $configExists); ?>
    <?php checkRow(t('system.lock_exists'), $lockExists); ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.database')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow(t('system.connection'), $dbConnected, $dbError ?: ''); ?>
    <?php if ($dbConnected): ?>
      <?php foreach ($tableStatus as $tbl => $ok): checkRow(t('system.table_prefix') . $tbl, $ok); endforeach; ?>
    <?php endif; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.storage')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <?php foreach ($storageStatus as $label => $ok): checkRow('storage/' . $label, $ok); endforeach; ?>
  </table>
</div>

<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.secrets')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <?php checkRow(t('system.github_token'), $githubConfigured, $githubConfigured ? t('system.configured') : t('system.not_configured')); ?>
    <?php checkRow(t('system.gemini_key'), $geminiConfigured, $geminiConfigured ? t('system.configured') : t('system.not_configured')); ?>
  </table>
</div>

<!-- AI info -->
<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.ai_models')) ?></div>
  <table style="width:100%;border-collapse:collapse">
    <tr><td style="color:var(--text-muted);font-size:0.85rem;width:160px"><?= e(t('system.ai_provider_row')) ?></td><td>Gemini</td></tr>
    <tr><td style="color:var(--text-muted);font-size:0.85rem"><?= e(t('system.ai_profile_row')) ?></td><td><?= htmlspecialchars(Settings::get('ai_profile') ?: 'balanced', ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td style="color:var(--text-muted);font-size:0.85rem">Economy</td><td><code><?= htmlspecialchars(Settings::get('gemini_economy_model') ?: 'gemini-2.5-flash-lite', ENT_QUOTES, 'UTF-8') ?></code></td></tr>
    <tr><td style="color:var(--text-muted);font-size:0.85rem">Balanced</td><td><code><?= htmlspecialchars(Settings::get('gemini_balanced_model') ?: 'gemini-2.5-flash', ENT_QUOTES, 'UTF-8') ?></code></td></tr>
    <tr><td style="color:var(--text-muted);font-size:0.85rem">Strong</td><td><code><?= htmlspecialchars(Settings::get('gemini_strong_model') ?: 'gemini-2.5-pro', ENT_QUOTES, 'UTF-8') ?></code></td></tr>
    <tr><td style="color:var(--text-muted);font-size:0.85rem">JSON repair</td><td><code><?= htmlspecialchars(Settings::get('gemini_json_repair_model') ?: 'gemini-2.5-flash-lite', ENT_QUOTES, 'UTF-8') ?></code></td></tr>
  </table>
</div>

<!-- Integration tests -->
<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.integrations')) ?></div>

  <?php if (!empty($testResult)): ?>
    <?php foreach ($testResult as $key => $res): ?>
      <div class="alert <?= $res['ok'] ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom:8px">
        <?= $res['ok'] ? '✔' : '✘' ?> <?= htmlspecialchars($res['msg'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="flex gap-2 mt-2 flex-wrap">
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" name="action" value="test_gemini" class="btn btn-secondary btn-sm">
        🤖 <?= e(t('system.test_gemini')) ?>
      </button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <button type="submit" name="action" value="test_github" class="btn btn-secondary btn-sm">
        🐙 <?= e(t('system.test_github')) ?>
      </button>
    </form>
  </div>
</div>

<!-- Webhook URL -->
<?php
$webhookBase = Settings::get('webhook_base_url');
if ($webhookBase):
    $webhookUrl = rtrim($webhookBase, '/') . '/webhook/github.php';
?>
<div class="card" style="max-width:760px;margin-top:16px">
  <div class="card-title"><?= e(t('system.webhook_endpoint')) ?></div>
  <p class="text-muted text-sm" style="margin-bottom:6px"><?= e(t('system.webhook_url_label')) ?>:</p>
  <code style="display:block;background:var(--bg);padding:10px;border-radius:6px;word-break:break-all">
    <?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?>
  </code>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
