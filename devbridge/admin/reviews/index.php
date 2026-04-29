<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db = Database::getInstance();

$reviews = $db->query(
    'SELECT dr.*, t.title AS task_title, t.github_pr_number, t.github_pr_url,
            p.name AS project_name
     FROM dev_task_reviews dr
     JOIN dev_tasks t ON t.id = dr.task_id
     JOIN projects p ON p.id = t.project_id
     ORDER BY dr.created_at DESC
     LIMIT 50'
)->fetchAll();

$pageTitle = 'Reviews';
$activeNav = 'reviews';
require APP_ROOT . '/views/layout.php';
?>

<?php if (empty($reviews)): ?>
  <div class="card"><p class="text-muted">No reviews yet.</p></div>
<?php else: ?>
  <?php foreach ($reviews as $r): ?>
    <?php
    $data = $r['review_json'] ? json_decode($r['review_json'], true) : null;
    $status = $data['status'] ?? ($r['error'] ? 'error' : 'unknown');
    ?>
    <div class="card">
      <div class="flex justify-between items-center mb-3">
        <div>
          <strong><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $r['task_id'] ?>">
            #<?= $r['task_id'] ?> – <?= htmlspecialchars($r['task_title'], ENT_QUOTES, 'UTF-8') ?>
          </a></strong>
          <span class="text-muted text-sm"> · <?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="flex gap-2 items-center">
          <span class="badge badge-<?= $status === 'approved' ? 'approved' : ($status === 'error' ? 'failed' : 'reviewing') ?>">
            <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
          </span>
          <span class="text-muted text-sm"><?= htmlspecialchars(substr($r['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>

      <?php if ($r['error']): ?>
        <div class="alert alert-danger">Error: <?= htmlspecialchars($r['error'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($data): ?>
        <div class="flex gap-3" style="font-size:0.88rem">
          <div><strong>Score:</strong> <?= (int)($data['score'] ?? 0) ?>/100</div>
          <div><strong>Merge:</strong> <code><?= htmlspecialchars($data['merge_decision'] ?? '—', ENT_QUOTES, 'UTF-8') ?></code></div>
          <?php if ($r['github_pr_url']): ?>
            <div><a href="<?= htmlspecialchars($r['github_pr_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">PR #<?= (int)$r['github_pr_number'] ?> ↗</a></div>
          <?php endif; ?>
        </div>
        <?php if ($data['summary'] ?? ''): ?>
          <p class="mt-2" style="font-size:0.88rem"><?= htmlspecialchars($data['summary'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if (!empty($data['blocking_issues'])): ?>
          <details>
            <summary style="cursor:pointer;color:var(--danger);font-size:0.85rem"><?= count($data['blocking_issues']) ?> blocking issue(s)</summary>
            <?php foreach ($data['blocking_issues'] as $issue): ?>
              <div style="background:var(--bg);border:1px solid #7f1d1d;border-radius:6px;padding:8px;margin:6px 0;font-size:0.82rem">
                <strong><?= htmlspecialchars($issue['severity'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong> ·
                <?= htmlspecialchars($issue['file'] ?? '', ENT_QUOTES, 'UTF-8') ?> ·
                <?= htmlspecialchars($issue['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endforeach; ?>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
