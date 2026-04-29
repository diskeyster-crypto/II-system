<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Settings;
use DevBridge\Core\Encryption;
use DevBridge\AI\GeminiClient;
use DevBridge\Core\Logger;

Auth::requireLogin();

$db      = Database::getInstance();
$success = false;
$error   = '';
$testMsg = '';

$encryptedKeys = ['gemini_api_key', 'github_token'];
$allKeys = [
    // Gemini AI
    'gemini_api_key',
    'ai_profile',
    'gemini_economy_model', 'gemini_balanced_model', 'gemini_strong_model', 'gemini_json_repair_model',
    // Role-specific model overrides (advanced / manual profile)
    'gemini_planner_model', 'gemini_critic_model', 'gemini_prompt_builder_model', 'gemini_reviewer_model',
    // Temperatures
    'planner_temperature', 'critic_temperature', 'prompt_builder_temperature', 'reviewer_temperature',
    // Limits
    'max_output_tokens', 'max_prompt_chars',
    // GitHub
    'github_token', 'github_default_owner', 'webhook_base_url', 'no_auto_merge',
    // General
    'locale',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test_gemini') {
        try {
            $ai   = new GeminiClient();
            $result = $ai->chatJson([
                ['role' => 'system', 'content' => 'You are a health check endpoint. Return JSON only.'],
                ['role' => 'user',   'content' => 'Return {"ok":true,"provider":"gemini"}'],
            ]);
            if (!empty($result['ok'])) {
                $testMsg = '<span style="color:#4ade80">✔ ' . e(t('settings.test_ok')) . '</span>';
                Logger::log('ai_request', 'Gemini test successful');
            } else {
                $testMsg = '<span style="color:#f87171">✘ ' . e(t('settings.test_fail')) . ': unexpected response</span>';
            }
        } catch (\Throwable $e) {
            $testMsg = '<span style="color:#f87171">✘ ' . e(t('settings.test_fail')) . ': ' . e($e->getMessage()) . '</span>';
            Logger::log('error', 'Gemini test failed: ' . $e->getMessage());
        }
    } else {
        try {
            foreach ($allKeys as $key) {
                if (!isset($_POST[$key])) continue;
                $val = trim($_POST[$key]);
                // Skip masked values (user didn't change encrypted field)
                if (in_array($key, $encryptedKeys, true) && str_contains($val, '****')) {
                    continue;
                }
                if (in_array($key, $encryptedKeys, true) && $val !== '') {
                    Settings::setEncrypted($key, $val);
                } else {
                    Settings::set($key, $val);
                }
            }
            // Ensure ai_provider is always set to gemini
            Settings::set('ai_provider', 'gemini');
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

  <!-- Gemini API key -->
  <div class="form-group">
    <label><?= e(t('settings.gemini_key')) ?></label>
    <input type="text" name="gemini_api_key"
           value="<?= htmlspecialchars($current['gemini_api_key'], ENT_QUOTES, 'UTF-8') ?>"
           placeholder="AIza...">
    <small class="text-muted"><?= e(t('settings.encrypted_note')) ?></small>
  </div>

  <!-- AI profile -->
  <div class="form-group">
    <label><?= e(t('settings.ai_profile')) ?></label>
    <select name="ai_profile" id="ai_profile">
      <?php foreach (['economy','balanced','strong','manual'] as $p): ?>
        <option value="<?= $p ?>" <?= ($current['ai_profile'] ?? 'balanced') === $p ? 'selected' : '' ?>>
          <?= e(t('settings.ai_profile_' . $p)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Profile preset models (always visible) -->
  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.gemini_economy_model')) ?></label>
      <input type="text" name="gemini_economy_model"
             value="<?= htmlspecialchars($current['gemini_economy_model'] ?: 'gemini-2.5-flash-lite', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="gemini-2.5-flash-lite">
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.gemini_balanced_model')) ?></label>
      <input type="text" name="gemini_balanced_model"
             value="<?= htmlspecialchars($current['gemini_balanced_model'] ?: 'gemini-2.5-flash', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="gemini-2.5-flash">
    </div>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.gemini_strong_model')) ?></label>
      <input type="text" name="gemini_strong_model"
             value="<?= htmlspecialchars($current['gemini_strong_model'] ?: 'gemini-2.5-pro', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="gemini-2.5-pro">
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('settings.gemini_json_repair_model')) ?></label>
      <input type="text" name="gemini_json_repair_model"
             value="<?= htmlspecialchars($current['gemini_json_repair_model'] ?: 'gemini-2.5-flash-lite', ENT_QUOTES, 'UTF-8') ?>"
             placeholder="gemini-2.5-flash-lite">
    </div>
  </div>

  <!-- Advanced / manual role overrides (collapsed by default) -->
  <details id="advanced-models" style="margin-top:8px" <?= ($current['ai_profile'] ?? 'balanced') === 'manual' ? 'open' : '' ?>>
    <summary style="cursor:pointer;color:var(--text-muted);font-size:0.9rem"><?= e(t('settings.advanced_models')) ?></summary>
    <div style="padding-top:12px">
      <div class="flex gap-3">
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.planner_model')) ?></label>
          <input type="text" name="gemini_planner_model"
                 value="<?= htmlspecialchars($current['gemini_planner_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="<?= e(t('settings.keep_empty')) ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.planner_temperature')) ?></label>
          <input type="number" name="planner_temperature" step="0.05" min="0" max="1"
                 value="<?= htmlspecialchars($current['planner_temperature'] ?: '0.4', ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>
      <div class="flex gap-3">
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.critic_model')) ?></label>
          <input type="text" name="gemini_critic_model"
                 value="<?= htmlspecialchars($current['gemini_critic_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="<?= e(t('settings.keep_empty')) ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.critic_temperature')) ?></label>
          <input type="number" name="critic_temperature" step="0.05" min="0" max="1"
                 value="<?= htmlspecialchars($current['critic_temperature'] ?: '0.2', ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>
      <div class="flex gap-3">
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.reviewer_model')) ?></label>
          <input type="text" name="gemini_reviewer_model"
                 value="<?= htmlspecialchars($current['gemini_reviewer_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="<?= e(t('settings.keep_empty')) ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.reviewer_temperature')) ?></label>
          <input type="number" name="reviewer_temperature" step="0.05" min="0" max="1"
                 value="<?= htmlspecialchars($current['reviewer_temperature'] ?: '0.1', ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>
      <div class="flex gap-3">
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.max_output_tokens')) ?></label>
          <input type="number" name="max_output_tokens" min="1024" max="65536"
                 value="<?= htmlspecialchars($current['max_output_tokens'] ?: '8192', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group" style="flex:1">
          <label><?= e(t('settings.max_prompt')) ?></label>
          <input type="number" name="max_prompt_chars" min="4000" max="200000"
                 value="<?= htmlspecialchars($current['max_prompt_chars'] ?: '32000', ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>
    </div>
  </details>

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
  <input type="hidden" name="action" value="test_gemini">
  <button type="submit" class="btn btn-secondary">🤖 <?= e(t('settings.btn_test_gemini')) ?></button>
</form>

<script>
// Auto-open advanced models when manual profile selected
document.getElementById('ai_profile').addEventListener('change', function () {
  const details = document.getElementById('advanced-models');
  if (this.value === 'manual') details.open = true;
});
</script>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
