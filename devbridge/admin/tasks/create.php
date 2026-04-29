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

    if (!$projectId) $error = 'Project is required.';
    elseif (!$repoId) $error = 'Repository is required.';
    elseif (!$title)  $error = 'Title is required.';

    if (!$error) {
        try {
            $db->prepare(
                'INSERT INTO dev_tasks
                 (project_id, repository_id, title, original_operator_request, priority, run_mode, risk_level, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft")'
            )->execute([$projectId, $repoId, $title, $request, $priority, $runMode, $riskLevel]);
            $newId = $db->lastInsertId();
            // If request provided, go straight to chat
            if ($request) {
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

$pageTitle = 'New Dev Task';
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:720px">
  <?= Csrf::field() ?>

  <div class="form-group">
    <label>Project *</label>
    <select name="project_id" required id="sel-project">
      <option value="">— select —</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= $p['id'] ?>" <?= ($p['id'] == $preProjectId) ? 'selected' : '' ?>><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Repository *</label>
    <select name="repository_id" required id="sel-repo">
      <option value="">— select —</option>
      <?php foreach ($repos as $r): ?>
        <option value="<?= $r['id'] ?>" data-project="<?= $r['project_id'] ?>" <?= ($r['id'] == $preRepoId) ? 'selected' : '' ?>>
          <?= htmlspecialchars($r['github_owner'] . '/' . $r['github_repo'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Task Title *</label>
    <input type="text" name="title" required value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
           placeholder="e.g. Add CSRF protection to admin forms">
  </div>
  <div class="form-group">
    <label>Initial Request / Description</label>
    <textarea name="original_operator_request" style="min-height:120px"
              placeholder="Describe what you want to build or fix..."><?= htmlspecialchars($_POST['original_operator_request'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    <small class="text-muted">You will discuss this with GPT on the next step.</small>
  </div>

  <div class="flex gap-3">
    <div class="form-group" style="flex:1">
      <label>Priority</label>
      <select name="priority">
        <?php foreach (['low','normal','high','urgent'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['priority'] ?? 'normal')) ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1">
      <label>Risk Level</label>
      <select name="risk_level">
        <?php foreach (['low','medium','high','critical'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['risk_level'] ?? 'medium')) ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:1">
      <label>Run Mode</label>
      <select name="run_mode">
        <?php foreach (['manual','run_now','queue','after_dependencies'] as $v): ?>
          <option value="<?= $v ?>" <?= ($v === ($_POST['run_mode'] ?? 'manual')) ? 'selected' : '' ?>><?= str_replace('_', ' ', $v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary">Create &amp; Open Chat</button>
    <a href="<?= BASE_URL ?>/admin/tasks/" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<script>
// Filter repos by project
const selProject = document.getElementById('sel-project');
const selRepo    = document.getElementById('sel-repo');
const allOpts    = Array.from(selRepo.querySelectorAll('option[data-project]'));

function filterRepos() {
  const pid = selProject.value;
  allOpts.forEach(o => {
    o.style.display = (!pid || o.dataset.project === pid) ? '' : 'none';
  });
}
selProject.addEventListener('change', filterRepos);
filterRepos();
</script>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
