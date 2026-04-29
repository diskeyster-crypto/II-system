<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Settings;
use DevBridge\Core\Encryption;

Auth::requireLogin();

$db      = Database::getInstance();
$success = false;
$error   = '';

$encryptedKeys = ['openrouter_api_key', 'github_token'];
$allKeys = [
    'openrouter_api_key', 'openrouter_model', 'ai_temperature', 'max_prompt_chars',
    'github_token', 'github_default_owner', 'webhook_base_url', 'no_auto_merge',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    try {
        foreach ($allKeys as $key) {
            if (!isset($_POST[$key])) continue;
            $val = trim($_POST[$key]);
            // Skip if it's a masked value (user didn't change it)
            if (in_array($key, $encryptedKeys, true) && str_contains($val, '****')) {
                continue;
            }
            if (in_array($key, $encryptedKeys, true) && $val !== '') {
                Settings::setEncrypted($key, $val);
            } else {
                Settings::set($key, $val);
            }
        }
        Settings::flush();
        $success = true;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// Load current values for display
$current = [];
foreach ($allKeys as $key) {
    if (in_array($key, $encryptedKeys, true)) {
        $plain = Settings::getDecrypted($key);
        $current[$key] = $plain ? Encryption::mask($plain) : '';
    } else {
        $current[$key] = Settings::get($key);
    }
}

$pageTitle = 'Settings';
$activeNav = 'settings';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success">Settings saved successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" class="card" style="max-width:660px">
  <?= Csrf::field() ?>

  <h2 class="card-title">AI Provider (OpenRouter)</h2>

  <div class="form-group">
    <label>OpenRouter API Key</label>
    <input type="text" name="openrouter_api_key"
           value="<?= htmlspecialchars($current['openrouter_api_key'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="sk-or-... (leave unchanged to keep existing)">
    <small class="text-muted">Stored encrypted. Shown masked.</small>
  </div>
  <div class="form-group">
    <label>OpenRouter Model</label>
    <input type="text" name="openrouter_model"
           value="<?= htmlspecialchars($current['openrouter_model'] ?: 'openai/gpt-4o', ENT_QUOTES, 'UTF-8') ?>"
           placeholder="openai/gpt-4o">
  </div>
  <div class="form-group">
    <label>AI Temperature (0.0 – 1.0)</label>
    <input type="number" name="ai_temperature" step="0.05" min="0" max="1"
           value="<?= htmlspecialchars($current['ai_temperature'] ?: '0.2', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Max Prompt Characters</label>
    <input type="number" name="max_prompt_chars" min="4000" max="200000"
           value="<?= htmlspecialchars($current['max_prompt_chars'] ?: '32000', ENT_QUOTES, 'UTF-8') ?>">
  </div>

  <hr class="divider">
  <h2 class="card-title">GitHub</h2>

  <div class="form-group">
    <label>GitHub Token</label>
    <input type="text" name="github_token"
           value="<?= htmlspecialchars($current['github_token'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="ghp_... (leave unchanged to keep existing)">
    <small class="text-muted">Stored encrypted. Shown masked.</small>
  </div>
  <div class="form-group">
    <label>Default GitHub Owner (user or org)</label>
    <input type="text" name="github_default_owner"
           value="<?= htmlspecialchars($current['github_default_owner'], ENT_QUOTES, 'UTF-8') ?>">
  </div>

  <hr class="divider">
  <h2 class="card-title">Webhooks</h2>

  <div class="form-group">
    <label>Webhook Base URL</label>
    <input type="url" name="webhook_base_url"
           value="<?= htmlspecialchars($current['webhook_base_url'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="https://yourdomain.com/devbridge">
    <small class="text-muted">Full URL up to devbridge root. Webhook endpoint will be appended.</small>
  </div>

  <hr class="divider">
  <h2 class="card-title">Safety</h2>

  <div class="form-group">
    <label>
      <input type="checkbox" name="no_auto_merge" value="1" <?= $current['no_auto_merge'] ? 'checked' : '' ?>>
      &nbsp;Global No-Auto-Merge (always required – do not disable)
    </label>
  </div>

  <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
