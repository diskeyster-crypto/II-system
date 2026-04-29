<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(dirname(__DIR__))));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;

Auth::requireLogin();

$db      = Database::getInstance();
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $name    = trim($_POST['name'] ?? '');
    $code    = trim($_POST['code'] ?? '');
    $desc    = trim($_POST['description'] ?? '');
    $goal    = trim($_POST['business_goal'] ?? '');
    $tech    = trim($_POST['tech_stack'] ?? '');
    $rules   = trim($_POST['global_rules'] ?? '');
    $model   = trim($_POST['default_ai_model'] ?? '');
    $maxPar  = max(1, (int)($_POST['max_parallel_tasks'] ?? 3));

    if (!$name) $error = 'Name is required.';
    elseif (!$code) $error = 'Code is required.';
    elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $code)) $error = 'Code may only contain letters, digits, underscores, and hyphens.';

    if (!$error) {
        try {
            $stmt = $db->prepare(
                'INSERT INTO projects (name, code, description, business_goal, tech_stack, global_rules, default_ai_model, max_parallel_tasks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $code, $desc, $goal, $tech, $rules, $model, $maxPar]);
            $newId = $db->lastInsertId();
            header('Location: ' . BASE_URL . '/admin/projects/view.php?id=' . $newId . '&created=1');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'New Project';
$activeNav = 'projects';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:720px">
  <?= Csrf::field() ?>

  <div class="form-group">
    <label>Project Name *</label>
    <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Code (slug) *</label>
    <input type="text" name="code" required value="<?= htmlspecialchars($_POST['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="my-project">
    <small class="text-muted">Used in branch names and roadmap references.</small>
  </div>
  <div class="form-group">
    <label>Description</label>
    <textarea name="description"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Business Goal</label>
    <textarea name="business_goal"><?= htmlspecialchars($_POST['business_goal'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <div class="form-group">
    <label>Tech Stack</label>
    <textarea name="tech_stack" style="min-height:80px"><?= htmlspecialchars($_POST['tech_stack'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    <small class="text-muted">Included in AI prompts and GitHub issues.</small>
  </div>
  <div class="form-group">
    <label>Global Rules (included in all prompts)</label>
    <textarea name="global_rules" style="min-height:120px"><?= htmlspecialchars($_POST['global_rules'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    <small class="text-muted">E.g. "Use PDO. No shell_exec. PHP 8.1+."</small>
  </div>
  <div class="form-group">
    <label>Default AI Model (optional override)</label>
    <input type="text" name="default_ai_model" value="<?= htmlspecialchars($_POST['default_ai_model'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="openai/gpt-4o">
  </div>
  <div class="form-group">
    <label>Max Parallel Tasks</label>
    <input type="number" name="max_parallel_tasks" min="1" max="20" value="<?= (int)($_POST['max_parallel_tasks'] ?? 3) ?>">
  </div>

  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary">Create Project</button>
    <a href="<?= BASE_URL ?>/admin/projects/" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
