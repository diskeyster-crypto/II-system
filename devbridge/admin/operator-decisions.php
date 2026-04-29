<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Services\GitHubService;
use DevBridge\Services\GPTCodeReviewer;
use DevBridge\AI\OpenRouterClient;

Auth::requireLogin();

$db = Database::getInstance();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';
    $taskId = (int)($_POST['task_id'] ?? 0);

    $taskStmt = $db->prepare(
        'SELECT t.*, r.github_owner, r.github_repo FROM dev_tasks t JOIN repositories r ON r.id = t.repository_id WHERE t.id = ?'
    );
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();

    if (!$task) {
        $error = 'Task not found.';
    } else {
        if ($action === 'answer_gpt') {
            $answer = trim($_POST['answer'] ?? '');
            if (!$answer) {
                $error = 'Answer is required.';
            } else {
                $db->prepare('INSERT INTO dev_task_messages (task_id, role, content) VALUES (?, "operator", ?)')->execute([$taskId, $answer]);
                $db->prepare('INSERT INTO operator_decisions (task_id, admin_id, action, notes) VALUES (?, ?, "answered_gpt", ?)')->execute([$taskId, Auth::adminId(), $answer]);
                $db->prepare('UPDATE dev_tasks SET status = "reviewing" WHERE id = ?')->execute([$taskId]);
                Logger::log('operator_decision', "Operator answered GPT question for task $taskId", $taskId, $task['project_id']);
                $success = 'Answer submitted. Task returned to reviewing.';
            }
        } elseif ($action === 'send_pr_comment') {
            $comment = trim($_POST['comment'] ?? '');
            if (!$comment) { $error = 'Comment text is required.'; }
            elseif (!$task['github_pr_number']) { $error = 'No PR number for this task.'; }
            else {
                try {
                    $gh = new GitHubService();
                    $gh->createPullRequestComment($task['github_owner'], $task['github_repo'], (int)$task['github_pr_number'], $comment);
                    $db->prepare('INSERT INTO operator_decisions (task_id, admin_id, action, notes) VALUES (?, ?, "pr_comment", ?)')->execute([$taskId, Auth::adminId(), $comment]);
                    Logger::log('operator_decision', "Operator sent PR comment for task $taskId", $taskId, $task['project_id']);
                    $success = 'Comment posted to PR.';
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        } elseif ($action === 'gpt_review') {
            try {
                $ai       = new OpenRouterClient();
                $reviewer = new GPTCodeReviewer($ai);
                $result   = $reviewer->review($taskId);
                $db->prepare('INSERT INTO operator_decisions (task_id, admin_id, action, notes) VALUES (?, ?, "requested_review", ?)')->execute([$taskId, Auth::adminId(), 'Operator requested another GPT review.']);
                $success = 'GPT review started. Status: ' . ($result['status'] ?? 'unknown');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        } elseif (in_array($action, ['cancel', 'failed', 'ready_for_manual_merge', 'merged'])) {
            $statusMap = [
                'cancel'                => 'cancelled',
                'failed'                => 'failed',
                'ready_for_manual_merge'=> 'ready_for_manual_merge',
                'merged'                => 'merged',
            ];
            $newStatus = $statusMap[$action];
            $db->prepare('UPDATE dev_tasks SET status = ? WHERE id = ?')->execute([$newStatus, $taskId]);
            $db->prepare('INSERT INTO operator_decisions (task_id, admin_id, action, notes) VALUES (?, ?, ?, ?)')->execute([$taskId, Auth::adminId(), $action, 'Operator manually set status']);
            Logger::log('operator_decision', "Operator set task $taskId to $newStatus", $taskId, $task['project_id']);
            $success = 'Task status updated to ' . str_replace('_', ' ', $newStatus) . '.';
        }
    }
}

// Load waiting tasks and pending roadmap proposals
$waitingTasks = $db->query(
    'SELECT t.*,
            p.name AS project_name,
            r.github_owner, r.github_repo
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.status = "waiting_for_operator"
     ORDER BY t.updated_at ASC'
)->fetchAll();

$pendingRoadmaps = $db->query(
    'SELECT rcp.*, p.name AS project_name FROM roadmap_change_proposals rcp
     JOIN projects p ON p.id = rcp.project_id
     WHERE rcp.status = "pending"
     ORDER BY rcp.created_at ASC'
)->fetchAll();

// Load latest GPT question for each waiting task
$gptQuestions = [];
foreach ($waitingTasks as $t) {
    $qStmt = $db->prepare(
        'SELECT od.notes FROM operator_decisions od
         WHERE od.task_id = ? AND od.action = "gpt_question"
         ORDER BY od.created_at DESC LIMIT 1'
    );
    $qStmt->execute([$t['id']]);
    $row = $qStmt->fetch();
    $gptQuestions[$t['id']] = $row['notes'] ?? null;
}

$pageTitle = 'Operator Decisions';
$activeNav = 'operator-decisions';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<?php if (empty($waitingTasks) && empty($pendingRoadmaps)): ?>
  <div class="card"><p class="text-muted">✅ Nothing waiting for your decision.</p></div>
<?php endif; ?>

<!-- Tasks waiting for operator -->
<?php foreach ($waitingTasks as $t): ?>
<div class="card" style="border-left:4px solid var(--warning)">
  <div class="flex justify-between items-center mb-3">
    <div>
      <strong><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?> – <?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></a></strong>
      <span class="text-muted text-sm"> · <?= htmlspecialchars($t['project_name'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($t['github_repo'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php if ($t['github_pr_url']): ?>
      <a href="<?= htmlspecialchars($t['github_pr_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">PR #<?= (int)$t['github_pr_number'] ?> ↗</a>
    <?php endif; ?>
  </div>

  <?php if ($gptQuestions[$t['id']] ?? null): ?>
    <div class="alert alert-warning mb-3">
      <strong>⚠ GPT Question:</strong> <?= htmlspecialchars($gptQuestions[$t['id']], ENT_QUOTES, 'UTF-8') ?>
    </div>

    <form method="post" class="mb-3">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <input type="hidden" name="action" value="answer_gpt">
      <div class="form-group">
        <label>Your Answer to GPT</label>
        <textarea name="answer" style="min-height:80px" placeholder="Answer the question..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Submit Answer</button>
    </form>
  <?php endif; ?>

  <!-- Custom PR Comment -->
  <?php if ($t['github_pr_number']): ?>
  <form method="post" class="mb-3">
    <?= Csrf::field() ?>
    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
    <input type="hidden" name="action" value="send_pr_comment">
    <div class="form-group">
      <label>Send Custom Comment to PR</label>
      <textarea name="comment" style="min-height:60px" placeholder="@copilot please fix..."></textarea>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Post PR Comment</button>
  </form>
  <?php endif; ?>

  <!-- Quick Actions -->
  <div class="flex gap-2 flex-wrap">
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <button type="submit" name="action" value="gpt_review" class="btn btn-primary btn-sm">🔍 Request GPT Review</button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <button type="submit" name="action" value="ready_for_manual_merge" class="btn btn-success btn-sm"
              onclick="return confirm('Mark as ready for manual merge?')">✅ Ready for Merge</button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <button type="submit" name="action" value="merged" class="btn btn-secondary btn-sm"
              onclick="return confirm('Mark as manually merged?')">Mark Merged</button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <button type="submit" name="action" value="cancel" class="btn btn-danger btn-sm"
              onclick="return confirm('Cancel this task?')">Cancel Task</button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
      <button type="submit" name="action" value="failed" class="btn btn-danger btn-sm"
              onclick="return confirm('Mark as failed?')">Mark Failed</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<!-- Pending roadmap proposals -->
<?php if (!empty($pendingRoadmaps)): ?>
<div class="card" style="border-left:4px solid var(--accent)">
  <div class="card-title">Pending Roadmap Changes</div>
  <?php foreach ($pendingRoadmaps as $rcp): ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--border)">
      <strong><?= htmlspecialchars($rcp['project_name'], ENT_QUOTES, 'UTF-8') ?></strong> · <?= htmlspecialchars($rcp['branch_code'] ?? 'core', ENT_QUOTES, 'UTF-8') ?>
      <?php if ($rcp['reason']): ?><p class="text-sm"><?= htmlspecialchars($rcp['reason'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      <a href="<?= BASE_URL ?>/admin/roadmaps/" class="btn btn-secondary btn-sm mt-2">View in Roadmaps →</a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
