<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Services\IssueCreator;
use DevBridge\Services\GitHubService;
use DevBridge\Services\ConflictDetector;
use DevBridge\Services\PRDiffFetcher;
use DevBridge\Services\GPTCodeReviewer;
use DevBridge\AI\OpenRouterClient;

Auth::requireLogin();

$db     = Database::getInstance();
$taskId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT t.*,
            p.name AS project_name, p.global_rules, p.tech_stack,
            r.github_owner, r.github_repo, r.default_branch
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.id = ?'
);
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); die('Task not found.'); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_issue') {
        $override = isset($_POST['override_conflict']);
        try {
            $gh       = new GitHubService();
            $detector = new ConflictDetector();
            $creator  = new IssueCreator($gh, $detector);
            $issue    = $creator->create($taskId, $override);
            $success  = t('github.issue_created') . " #{$issue['number']}: {$issue['html_url']}";
            // Reload task
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'link_pr') {
        $prInput = trim($_POST['pr_input'] ?? '');
        $prNumber = 0;
        $prUrl    = '';
        // Accept either a full URL or a plain number
        if (preg_match('#/pull/(\d+)#', $prInput, $m)) {
            $prNumber = (int)$m[1];
            $prUrl    = $prInput;
        } elseif (ctype_digit(ltrim($prInput, '#'))) {
            $prNumber = (int)ltrim($prInput, '#');
            $prUrl    = 'https://github.com/' . $task['github_owner'] . '/' . $task['github_repo'] . '/pull/' . $prNumber;
        }
        if ($prNumber > 0) {
            $db->prepare(
                'UPDATE dev_tasks SET github_pr_number = ?, github_pr_url = ?, status = "pr_created", updated_at = NOW() WHERE id = ?'
            )->execute([$prNumber, $prUrl, $taskId]);
            Logger::log('github_request', "PR #$prNumber linked to task $taskId", $taskId, $task['project_id']);
            $success = t('tasks.pr_linked');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
        } else {
            $error = t('tasks.pr_invalid');
        }
    } elseif ($action === 'fetch_pr_diff') {
        try {
            $gh      = new GitHubService();
            $fetcher = new PRDiffFetcher($gh);
            $fetcher->fetchAndStore($taskId);
            $success = t('tasks.pr_diff_fetched');
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'gpt_review') {
        try {
            $ai       = OpenRouterClient::forReviewer();
            $reviewer = new GPTCodeReviewer($ai);
            $result   = $reviewer->review($taskId);
            $status   = $result['status'] ?? 'unknown';

            // Update task status based on review
            $newStatus = match($status) {
                'approved'                => 'approved_by_gpt',
                'request_changes'         => 'changes_requested',
                'rejected'                => 'changes_requested',
                'needs_operator_decision' => 'waiting_for_operator',
                default                   => 'reviewing',
            };
            $db->prepare('UPDATE dev_tasks SET status = ?, review_cycles = review_cycles + 1 WHERE id = ?')
               ->execute([$newStatus, $taskId]);
            $task['status'] = $newStatus;

            if (in_array($status, ['request_changes', 'rejected'])) {
                $checkCycles = (int)$task['review_cycles'] + 1;
                if ($checkCycles >= 3) {
                    $db->prepare('UPDATE dev_tasks SET status = "waiting_for_operator" WHERE id = ?')->execute([$taskId]);
                    $task['status'] = 'waiting_for_operator';
                    $success = t('tasks.gpt_review_max_cycles');
                } else {
                    postPRComment($task, $result, $db);
                    $success = t('tasks.gpt_review_complete', ['status' => $status]);
                }
            } elseif ($status === 'approved') {
                $db->prepare('UPDATE dev_tasks SET status = "ready_for_manual_merge" WHERE id = ?')->execute([$taskId]);
                $task['status'] = 'ready_for_manual_merge';
                $success = t('tasks.gpt_approved');
            } elseif ($status === 'needs_operator_decision') {
                $question = $result['operator_question'] ?? 'GPT needs operator decision.';
                $db->prepare('INSERT INTO operator_decisions (task_id, admin_id, action, notes) VALUES (?, ?, "gpt_question", ?)')
                   ->execute([$taskId, Auth::adminId(), $question]);
                $success = t('tasks.gpt_needs_decision');
            } else {
                $success = 'GPT review: ' . $status;
            }

            // Reload task
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
        } catch (\Throwable $e) {
            $error = 'GPT Review failed: ' . $e->getMessage();
        }
    } elseif ($action === 'set_status') {
        $newStatus = $_POST['new_status'] ?? '';
        $allowed   = ['merged', 'failed', 'cancelled', 'ready_for_manual_merge', 'waiting_for_operator', 'assigned_to_agent'];
        if (in_array($newStatus, $allowed)) {
            $db->prepare('UPDATE dev_tasks SET status = ? WHERE id = ?')->execute([$newStatus, $taskId]);
            $task['status'] = $newStatus;
            Logger::log('operator_decision', "Operator set task $taskId status to $newStatus", $taskId, $task['project_id']);
            $success = t('tasks.status_updated');
        }
    }
}

// Load latest review
$latestReview = $db->prepare('SELECT * FROM dev_task_reviews WHERE task_id = ? ORDER BY created_at DESC LIMIT 1');
$latestReview->execute([$taskId]);
$latestReview = $latestReview->fetch();

$reviewData = $latestReview ? json_decode($latestReview['review_json'] ?? 'null', true) : null;

function postPRComment(array $task, array $result, \PDO $db): void
{
    if (!$task['github_pr_number'] || !$task['github_owner']) return;
    $issues = $result['blocking_issues'] ?? [];
    if (empty($issues)) return;

    $lines = ["@copilot please fix the following issues:\n"];
    foreach ($issues as $i => $issue) {
        $line = ($i + 1) . '. ';
        if ($issue['file'] ?? '') $line .= '`' . $issue['file'] . '`';
        if ($issue['line'] ?? '') $line .= ' (line ' . $issue['line'] . ')';
        $line .= ': ' . $issue['message'];
        if ($issue['suggested_fix'] ?? '') $line .= "\n   Fix: " . $issue['suggested_fix'];
        $lines[] = $line;
    }

    $forbidden = json_decode($task['forbidden_files_json'] ?? '[]', true) ?: [];
    if ($forbidden) {
        $lines[] = "\nDo NOT modify these files: " . implode(', ', array_map(fn($f) => '`' . $f . '`', $forbidden));
    }
    $lines[] = "\nDo not modify unrelated files.";
    $lines[] = "Keep compatibility with {$task['tech_stack']}.";

    $body = implode("\n", $lines);

    try {
        $gh = new GitHubService();
        $gh->createPullRequestComment($task['github_owner'], $task['github_repo'], (int)$task['github_pr_number'], $body);
        Logger::log('github_request', "Posted PR comment for task {$task['id']}", $task['id'], $task['project_id']);
    } catch (\Throwable $e) {
        Logger::log('error', 'Failed to post PR comment: ' . $e->getMessage(), $task['id']);
    }
}

$pageTitle = 'Task #' . $taskId . ': ' . $task['title'];
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

<!-- Action bar -->
<div class="flex gap-2 mb-4 items-center flex-wrap">
  <a href="<?= BASE_URL ?>/admin/tasks/chat.php?id=<?= $taskId ?>" class="btn btn-secondary btn-sm"><?= e(t('tasks.open_chat')) ?></a>
  <a href="<?= BASE_URL ?>/admin/tasks/" class="btn btn-secondary btn-sm"><?= e(t('tasks.all_tasks')) ?></a>

  <?php if ($task['status'] === 'ready_to_run'): ?>
    <span id="approve" style="scroll-margin-top:20px">
      <form method="post" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create_issue">
        <button type="submit" class="btn btn-success btn-sm"
                onclick="return confirm('<?= e(t('tasks.create_issue_confirm')) ?>')">
          ✅ <?= e(t('tasks.btn_create_issue')) ?>
        </button>
      </form>
    </span>
  <?php endif; ?>

  <?php if ($task['github_pr_number']): ?>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="fetch_pr_diff">
      <button type="submit" class="btn btn-secondary btn-sm">🔄 <?= e(t('tasks.btn_fetch_diff')) ?></button>
    </form>
    <form method="post" style="display:inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="gpt_review">
      <button type="submit" class="btn btn-primary btn-sm"
              onclick="return confirm('<?= e(t('tasks.gpt_review_confirm')) ?>')">🔍 <?= e(t('tasks.btn_gpt_review')) ?></button>
    </form>
  <?php endif; ?>

  <!-- Manual status controls -->
  <form method="post" style="display:inline" class="flex gap-1">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="set_status">
    <select name="new_status" style="width:auto">
      <option value="">— <?= e(t('tasks.set_status')) ?> —</option>
      <?php foreach (['assigned_to_agent','merged','failed','cancelled','ready_for_manual_merge','waiting_for_operator'] as $s): ?>
        <option value="<?= $s ?>"><?= t('statuses.' . $s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Set</button>
  </form>
</div>

<div class="flex gap-3" style="align-items:flex-start">
  <!-- Left: Task details -->
  <div style="flex:1.2">
    <div class="card">
      <div class="card-title"><?= e(t('tasks.details')) ?></div>
      <table>
        <tr><td class="text-muted" style="width:130px"><?= e(t('common.status')) ?></td><td><span class="badge badge-draft"><?= e(t('statuses.' . $task['status'])) ?></span></td></tr>
        <tr><td class="text-muted"><?= e(t('tasks.priority')) ?></td><td><span class="badge badge-<?= e($task['priority']) ?>"><?= e(t('statuses.priority_' . $task['priority'])) ?></span></td></tr>
        <tr><td class="text-muted"><?= e(t('tasks.risk_level')) ?></td><td><span class="badge badge-<?= e($task['risk_level']) ?>"><?= e(t('statuses.risk_' . $task['risk_level'])) ?></span></td></tr>
        <tr><td class="text-muted"><?= e(t('tasks.conflict_status')) ?></td><td><span class="badge badge-<?= e($task['conflict_status']) ?>"><?= e($task['conflict_status'] ?: '—') ?></span></td></tr>
        <tr><td class="text-muted"><?= e(t('tasks.run_mode')) ?></td><td><?= e(t('statuses.run_mode_' . $task['run_mode'])) ?></td></tr>
        <tr><td class="text-muted"><?= e(t('tasks.review_cycles')) ?></td><td><?= (int)$task['review_cycles'] ?>/3</td></tr>
        <?php if ($task['branch_name']): ?>
        <tr><td class="text-muted"><?= e(t('tasks.branch_name')) ?></td><td><code><?= e($task['branch_name']) ?></code></td></tr>
        <?php endif; ?>
        <?php if ($task['github_issue_url']): ?>
        <tr><td class="text-muted"><?= e(t('tasks.github_issue')) ?></td><td><a href="<?= e($task['github_issue_url']) ?>" target="_blank" rel="noopener">#<?= (int)$task['github_issue_number'] ?> ↗</a></td></tr>
        <?php endif; ?>
        <?php if ($task['github_pr_url']): ?>
        <tr><td class="text-muted">PR</td><td><a href="<?= e($task['github_pr_url']) ?>" target="_blank" rel="noopener">#<?= (int)$task['github_pr_number'] ?> ↗</a></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <!-- Link PR card -->
    <div class="card">
      <div class="card-title">🔗 <?= e(t('tasks.link_pr')) ?></div>
      <form method="post" class="flex gap-2 items-center">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="link_pr">
        <input type="text" name="pr_input" style="flex:1"
               value="<?= $task['github_pr_number'] ? '#' . (int)$task['github_pr_number'] : '' ?>"
               placeholder="<?= e(t('tasks.pr_number_or_url')) ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= e(t('tasks.btn_link_pr')) ?></button>
      </form>
    </div>

    <?php if ($task['original_operator_request']): ?>
    <div class="card">
      <div class="card-title"><?= e(t('tasks.original_req_card')) ?></div>
      <p style="white-space:pre-wrap;font-size:0.9rem"><?= e($task['original_operator_request']) ?></p>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: Spec + Review -->
  <div style="flex:2">
    <?php if ($task['final_task_spec']): ?>
    <div class="card">
      <div class="card-title"><?= e(t('tasks.final_spec')) ?></div>
      <pre style="font-size:0.83rem;max-height:400px;overflow:auto;white-space:pre-wrap"><?= e($task['final_task_spec']) ?></pre>
    </div>
    <?php endif; ?>

    <?php if ($reviewData): ?>
    <div class="card">
      <div class="flex justify-between items-center mb-3">
        <div class="card-title" style="margin:0"><?= e(t('tasks.latest_review')) ?></div>
        <span class="badge badge-<?= $reviewData['status'] === 'approved' ? 'approved' : ($reviewData['status'] === 'needs_operator_decision' ? 'waiting' : 'failed') ?>">
          <?= e($reviewData['status'] ?? '') ?>
        </span>
      </div>
      <p><strong><?= e(t('tasks.score')) ?>:</strong> <?= (int)($reviewData['score'] ?? 0) ?>/100</p>
      <p><strong><?= e(t('tasks.summary')) ?>:</strong> <?= e($reviewData['summary'] ?? '') ?></p>
      <p><strong><?= e(t('tasks.merge_decision')) ?>:</strong> <code><?= e($reviewData['merge_decision'] ?? '') ?></code></p>

      <?php if (!empty($reviewData['blocking_issues'])): ?>
        <hr class="divider">
        <strong><?= e(t('tasks.blocking_issues')) ?></strong>
        <?php foreach ($reviewData['blocking_issues'] as $issue): ?>
          <div style="background:var(--bg);border:1px solid #7f1d1d;border-radius:8px;padding:10px;margin-top:8px;font-size:0.85rem">
            <span class="badge badge-failed"><?= e($issue['severity'] ?? '') ?></span>
            <span class="badge badge-draft"><?= e($issue['type'] ?? '') ?></span>
            <?php if ($issue['file'] ?? ''): ?><code><?= e($issue['file']) ?></code><?php endif; ?>
            <p style="margin:6px 0"><?= e($issue['message'] ?? '') ?></p>
            <?php if ($issue['suggested_fix'] ?? ''): ?><p class="text-muted" style="margin:0">Fix: <?= e($issue['suggested_fix']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($reviewData['operator_question'])): ?>
        <hr class="divider">
        <div class="alert alert-warning">
          <strong><?= e(t('tasks.gpt_question')) ?></strong> <?= e($reviewData['operator_question']) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($task['conflict_details_json'] && $task['conflict_details_json'] !== 'null'): ?>
    <div class="card">
      <div class="card-title"><?= e(t('tasks.conflict_details')) ?></div>
      <pre style="font-size:0.8rem"><?= e(json_encode(json_decode($task['conflict_details_json']), JSON_PRETTY_PRINT)) ?></pre>
      <?php if ($task['status'] === 'blocked_by_dependency' || $task['conflict_status'] === 'blocking'): ?>
      <form method="post" class="mt-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create_issue">
        <input type="hidden" name="override_conflict" value="1">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('<?= e(t('tasks.override_confirm')) ?>')">
          <?= e(t('tasks.btn_override_conflict')) ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
