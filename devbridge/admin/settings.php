<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Settings;
use DevBridge\Core\Encryption;
use DevBridge\AI\OpenRouterClient;
use DevBridge\Core\Logger;

Auth::requireLogin();

$db      = Database::getInstance();
$success = false;
$error   = '';
$testMsg = '';

$encryptedKeys = ['openrouter_api_key', 'github_token'];
$allKeys = [
    'openrouter_api_key', 'openrouter_model',
    'planner_model', 'reviewer_model', 'json_repair_model',
    'ai_temperature', 'planner_temperature', 'reviewer_temperature',
    'max_prompt_chars',
    'github_token', 'github_default_owner', 'webhook_base_url', 'no_auto_merge',
    'locale',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test_openrouter') {
        try {
            $ai     = new OpenRouterClient();
            $reply  = $ai->chat([
                ['role' => 'user', 'content' => 'Respond with exactly: OK'],
            ]);
            $testMsg = '<span style="color:#4ade80">✔ ' . e(t('settings.test_ok')) . ' (' . e(trim(substr($reply, 0, 100))) . ')</span>';
            Logger::log('ai_request', 'OpenRouter test successful');
        } catch (\Throwable $e) {
            $testMsg = '<span style="color:#f87171">✘ ' . e(t('settings.test_fail')) . ': ' . e($e->getMessage()) . '</span>';
            Logger::log('error', 'OpenRouter test failed: ' . $e->getMessage());
        }
    } else {
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

$pageTitle = t('settings.title');
$activeNav = 'settings';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success"><?= e(t('settings.saved')) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($testMsg): ?>
  <div class="alert alert-warning"><?= $testMsg ?></div>
<?php endif; ?>

<form method="post" class="card" style="max-width:700px">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save">

  <h2 class="card-title"><?= e(t('settings.general')) ?></h2>

  <div class="form-group">
    <label><?= e(t('settings.interface_lang')) ?></label>
    <select name="locale">
      <option value="ru" <?= ($current['locale'] ?? 'ru') === 'ru' ? 'selected' : '' ?>><?= e(t('settings.lang_ru')) ?></option>
      <option value="en" <?= ($current['locale'] ?? 'ru') === 'en' ? 'selected' : '' ?>><?= e(t('settings.lang_en')) ?></option>
    </select>
  </div>

  <hr class="divider">
  <h2 class="card-title"><?= e(t('settings.ai_provider')) ?></h2>

  <div class="form-group">
    <label><?= e(t('settings.openrouter_key')) ?></label>
    <input type="text" name="openrouter_api_key"
           value="<?= htmlspecialchars($current['openrouter_api_key'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="sk-or-...">
    <small class="text-muted"><?= e(t('settings.encrypted_note')) ?></small>
  </div>

  <div class="form-group">
    <label><?= e(t('settings.openrouter_model')) ?></label>
    <input type="text" name="openrouter_model"
           value="<?= htmlspecialchars($current['openrouter_model'] ?: 'openai/gpt-4o', ENT_QUOTES, 'UTF-8') ?>"
           placeholder="openai/gpt-4o">
    <small class="text-muted"><?= e(t('settings.model_hint')) ?></small>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.planner_model')) ?></label>
      <input type="text" name="planner_model"
             value="<?= htmlspecialchars($current['planner_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="openai/gpt-4o">
      <small class="text-muted"><?= e(t('settings.keep_empty')) ?></small>
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.planner_temperature')) ?></label>
      <input type="number" name="planner_temperature" step="0.05" min="0" max="1"
             value="<?= htmlspecialchars($current['planner_temperature'] ?: '0.4', ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.reviewer_model')) ?></label>
      <input type="text" name="reviewer_model"
             value="<?= htmlspecialchars($current['reviewer_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="openai/gpt-4o">
      <small class="text-muted"><?= e(t('settings.keep_empty')) ?></small>
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.reviewer_temperature')) ?></label>
      <input type="number" name="reviewer_temperature" step="0.05" min="0" max="1"
             value="<?= htmlspecialchars($current['reviewer_temperature'] ?: '0.2', ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>

  <div class="form-group">
    <label><?= e(t('settings.json_repair_model')) ?></label>
    <input type="text" name="json_repair_model"
           value="<?= htmlspecialchars($current['json_repair_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
           placeholder="openai/gpt-4o-mini">
    <small class="text-muted"><?= e(t('settings.keep_empty')) ?></small>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.temperature')) ?></label>
      <input type="number" name="ai_temperature" step="0.05" min="0" max="1"
             value="<?= htmlspecialchars($current['ai_temperature'] ?: '0.2', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.max_prompt')) ?></label>
      <input type="number" name="max_prompt_chars" min="4000" max="200000"
             value="<?= htmlspecialchars($current['max_prompt_chars'] ?: '32000', ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>

  <hr class="divider">
  <h2 class="card-title"><?= e(t('settings.github')) ?></h2>

  <div class="form-group">
    <label><?= e(t('settings.github_token')) ?></label>
    <input type="text" name="github_token"
           value="<?= htmlspecialchars($current['github_token'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="ghp_...">
    <small class="text-muted"><?= e(t('settings.encrypted_note')) ?></small>
  </div>
  <div class="form-group">
    <label><?= e(t('settings.github_owner')) ?></label>
    <input type="text" name="github_default_owner"
           value="<?= htmlspecialchars($current['github_default_owner'], ENT_QUOTES, 'UTF-8') ?>">
  </div>

  <hr class="divider">
  <h2 class="card-title"><?= e(t('settings.webhooks')) ?></h2>

  <div class="form-group">
    <label><?= e(t('settings.webhook_url')) ?></label>
    <input type="url" name="webhook_base_url"
           value="<?= htmlspecialchars($current['webhook_base_url'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="https://yourdomain.com/devbridge">
    <small class="text-muted"><?= e(t('settings.webhook_hint')) ?></small>
  </div>

  <hr class="divider">
  <h2 class="card-title"><?= e(t('settings.safety')) ?></h2>

  <div class="form-group">
    <label>
      <input type="checkbox" name="no_auto_merge" value="1" <?= $current['no_auto_merge'] ? 'checked' : '' ?>>
      &nbsp;<?= e(t('settings.no_auto_merge')) ?>
    </label>
  </div>

  <button type="submit" class="btn btn-primary"><?= e(t('settings.btn_save')) ?></button>
</form>

<form method="post" style="max-width:700px;margin-top:12px">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="test_openrouter">
  <button type="submit" class="btn btn-secondary"><?= e(t('settings.btn_test_openrouter')) ?></button>
</form>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>

