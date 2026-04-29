<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;

Auth::requireLogin();

$db       = Database::getInstance();
$error    = '';

$preProjectId = (int)($_GET['project_id'] ?? 0);
$preRepoId    = (int)($_GET['repository_id'] ?? 0);

$projects = $db->query('SELECT id, name FROM projects WHERE status = "active" ORDER BY name')->fetchAll();
$repos    = $db->query('SELECT id, project_id, github_owner, github_repo FROM repositories WHERE status = "active" ORDER BY github_repo')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $projectId = (int)($_POST['project_id'] ?? 0);
    $repoId    = (int)($_POST['repository_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $request   = trim($_POST['original_operator_request'] ?? '');
    $priority  = $_POST['priority'] ?? 'normal';
    $runMode   = $_POST['run_mode'] ?? 'manual';
    $riskLevel = $_POST['risk_level'] ?? 'medium';
    $taskMode  = $_POST['task_mode'] === 'manual_spec' ? 'manual_spec' : 'ai_chat';

    if (!$projectId) $error = t('tasks.error_project_required');
    elseif (!$repoId) $error = t('tasks.error_repo_required');
    elseif (!$title)  $error = t('tasks.error_title_required');

    if (!$error) {
        try {
            $db->prepare(
                'INSERT INTO dev_tasks
                 (project_id, repository_id, title, original_operator_request, priority, run_mode, risk_level, task_mode, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft")'
            )->execute([$projectId, $repoId, $title, $request, $priority, $runMode, $riskLevel, $taskMode]);
            $newId = $db->lastInsertId();

            if ($taskMode === 'manual_spec') {
                header('Location: ' . BASE_URL . '/admin/tasks/manual-spec.php?id=' . $newId);
            } elseif ($request) {
                header('Location: ' . BASE_URL . '/admin/tasks/chat.php?id=' . $newId);
            } else {
                header('Location: ' . BASE_URL . '/admin/tasks/view.php?id=' . $newId);
            }
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = t('tasks.create');
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:720px">
  <?= Csrf::field() ?>

  <div class="form-group">
    <label><?= e(t('tasks.project')) ?> *</label>
    <select name="project_id" required id="sel-project">
      <option value="">— <?= e(t('common.select')) ?> —</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= $p['id'] ?>" <?= ($p['id'] == $preProjectId) ? 'selected' : '' ?>><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label><?= e(t('tasks.repository')) ?> *</label>
    <select name="repository_id" required id="sel-repo">
      <option value="">— <?= e(t('common.select')) ?> —</option>
      <?php foreach ($repos as $r): ?>
        <option value="<?= $r['id'] ?>" data-project="<?= $r['project_id'] ?>" <?= ($r['id'] == $preRepoId) ? 'selected' : '' ?>>
          <?= htmlspecialchars($r['github_owner'] . '/' . $r['github_repo'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label><?= e(t('tasks.title_label')) ?> *</label>
    <input type="text" name="title" required value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
           placeholder="<?= e(t('tasks.title_placeholder')) ?>">
  </div>

  <!-- Planning Mode -->
  <div class="form-group">
    <label><?= e(t('tasks.planning_mode')) ?></label>
    <div class="flex gap-3 mt-1" id="mode-cards">
      <label class="mode-card" style="flex:1;border:2px solid var(--border);border-radius:8px;padding:14px;cursor:pointer;transition:border-color .2s">
        <input type="radio" name="task_mode" value="ai_chat"
               <?= (($_POST['task_mode'] ?? 'ai_chat') !== 'manual_spec') ? 'checked' : '' ?> style="margin-right:6px">
        <strong>🤖 <?= e(t('tasks.mode_ai_chat')) ?></strong>
        <p class="text-muted text-sm mt-1" style="margin:0"><?= e(t('tasks.mode_ai_chat_desc')) ?></p>
      </label>
      <label class="mode-card" style="flex:1;border:2px solid var(--border);border-radius:8px;padding:14px;cursor:pointer;transition:border-color .2s">
        <input type="radio" name="task_mode" value="manual_spec"
               <?= (($_POST['task_mode'] ?? '') === 'manual_spec') ? 'checked' : '' ?> style="margin-right:6px">
        <strong>📋 <?= e(t('tasks.mode_manual_spec')) ?></strong>
        <p class="text-muted text-sm mt-1" style="margin:0"><?= e(t('tasks.mode_manual_spec_desc')) ?></p>
      </label>
    </div>
  </div>

  <div class="form-group" id="initial-request-group">
    <label><?= e(t('tasks.original_request')) ?></label>
    <textarea name="original_operator_request" style="min-height:100px"
              placeholder="<?= e(t('tasks.original_request_placeholder')) ?>"><?= htmlspecialchars($_POST['original_operator_request'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    <small class="text-muted"><?= e(t('tasks.original_request_hint')) ?></small>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label><?= e(t('tasks.priority')) ?></label>
      <select name="priority">
        <?php foreach (['low','normal','high','urgent'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['priority'] ?? 'normal')) ? 'selected' : '' ?>><?= e(t('statuses.priority_' . $v)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('tasks.risk_level')) ?></label>
      <select name="risk_level">
        <?php foreach (['low','medium','high','critical'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['risk_level'] ?? 'medium')) ? 'selected' : '' ?>><?= e(t('statuses.risk_' . $v)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1">
      <label><?= e(t('tasks.run_mode')) ?></label>
      <select name="run_mode">
        <?php foreach (['manual','run_now','queue','after_dependencies'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['run_mode'] ?? 'manual')) ? 'selected' : '' ?>><?= e(t('statuses.run_mode_' . $v)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary" id="btn-submit">
      <?= e(t('tasks.btn_create_and_continue')) ?>
    </button>
    <a href="<?= BASE_URL ?>/admin/tasks/" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
  </div>
</form>

<script>
const selProject = document.getElementById('sel-project');
const selRepo    = document.getElementById('sel-repo');
const allOpts    = Array.from(selRepo.querySelectorAll('option[data-project]'));
const modeCards  = document.querySelectorAll('.mode-card');
const radios     = document.querySelectorAll('input[name="task_mode"]');
const btnSubmit  = document.getElementById('btn-submit');

function filterRepos() {
  const pid = selProject.value;
  allOpts.forEach(o => {
    o.style.display = (!pid || o.dataset.project === pid) ? '' : 'none';
  });
}

function updateModeHighlight() {
  radios.forEach(r => {
    r.closest('.mode-card').style.borderColor = r.checked ? 'var(--accent)' : 'var(--border)';
  });
  const manual = document.querySelector('input[name="task_mode"][value="manual_spec"]').checked;
  btnSubmit.textContent = manual
    ? <?= json_encode(t('tasks.btn_create_and_continue')) ?>
    : <?= json_encode(t('tasks.btn_create_and_continue')) ?>;
}

selProject.addEventListener('change', filterRepos);
radios.forEach(r => r.addEventListener('change', updateModeHighlight));
filterRepos();
updateModeHighlight();
</script>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>

