<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(dirname(__DIR__))));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;

Auth::requireLogin();

$db      = Database::getInstance();
$id      = (int)($_GET['id'] ?? 0);
$project = $db->prepare('SELECT * FROM projects WHERE id = ?');
$project->execute([$id]);
$project = $project->fetch();

if (!$project) {
    http_response_code(404);
    die('Project not found.');
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'archive') {
        $db->prepare('UPDATE projects SET status = "archived" WHERE id = ?')->execute([$id]);
        header('Location: ' . BASE_URL . '/admin/projects/?archived=1');
        exit;
    }

    $name   = trim($_POST['name'] ?? '');
    $code   = trim($_POST['code'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $goal   = trim($_POST['business_goal'] ?? '');
    $tech   = trim($_POST['tech_stack'] ?? '');
    $rules  = trim($_POST['global_rules'] ?? '');
    $model  = trim($_POST['default_ai_model'] ?? '');
    $maxPar = max(1, (int)($_POST['max_parallel_tasks'] ?? 3));

    if (!$name) $error = 'Name is required.';
    elseif (!$code) $error = 'Code is required.';

    if (!$error) {
        try {
            $db->prepare(
                'UPDATE projects SET name=?, code=?, description=?, business_goal=?, tech_stack=?,
                 global_rules=?, default_ai_model=?, max_parallel_tasks=? WHERE id=?'
            )->execute([$name, $code, $desc, $goal, $tech, $rules, $model, $maxPar, $id]);
            $success = true;
            $project = array_merge($project, ['name' => $name, 'code' => $code, 'description' => $desc,
                'business_goal' => $goal, 'tech_stack' => $tech, 'global_rules' => $rules,
                'default_ai_model' => $model, 'max_parallel_tasks' => $maxPar]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Edit: ' . $project['name'];
$activeNav = 'projects';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($success): ?><div class="alert alert-success">Project updated.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:720px">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save">

  <div class="form-group">
    <label>Project Name *</label>
    <input type="text" name="name" required value="<?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Code *</label>
    <input type="text" name="code" required value="<?= htmlspecialchars($project['code'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Description</label>
    <textarea name="description"><?= htmlspecialchars($project['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Business Goal</label>
    <textarea name="business_goal"><?= htmlspecialchars($project['business_goal'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Tech Stack</label>
    <textarea name="tech_stack" style="min-height:80px"><?= htmlspecialchars($project['tech_stack'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Global Rules</label>
    <textarea name="global_rules" style="min-height:120px"><?= htmlspecialchars($project['global_rules'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Default AI Model</label>
    <input type="text" name="default_ai_model" value="<?= htmlspecialchars($project['default_ai_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Max Parallel Tasks</label>
    <input type="number" name="max_parallel_tasks" min="1" max="20" value="<?= (int)$project['max_parallel_tasks'] ?>">
  </div>

  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary">Save Changes</button>
    <a href="<?= BASE_URL ?>/admin/projects/view.php?id=<?= $id ?>" class="btn btn-secondary">View</a>
    <a href="<?= BASE_URL ?>/admin/projects/" class="btn btn-secondary">Back</a>
  </div>
</form>

<div class="card" style="max-width:720px;border-color:#7f1d1d">
  <div class="card-title text-danger">Danger Zone</div>
  <form method="post" onsubmit="return confirm('Archive this project?')">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="archive">
    <button type="submit" class="btn btn-danger">Archive Project</button>
  </form>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
