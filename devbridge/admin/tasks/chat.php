<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Settings;
use DevBridge\Core\Logger;
use DevBridge\AI\OpenRouterClient;

Auth::requireLogin();

$db     = Database::getInstance();
$taskId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT t.*,
            p.name AS project_name, p.global_rules, p.tech_stack, p.business_goal,
            r.github_owner, r.github_repo, r.default_branch
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.id = ?'
);
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); die('Task not found.'); }

// Load chat history
$msgStmt = $db->prepare('SELECT * FROM dev_task_messages WHERE task_id = ? ORDER BY created_at ASC');
$msgStmt->execute([$taskId]);
$messages = $msgStmt->fetchAll();

$error = '';

// -----------------------------------------------------------------------
// POST: send message or approve spec
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'message';

    if ($action === 'message') {
        $userMsg = trim($_POST['message'] ?? '');
        if (!$userMsg) {
            $error = 'Message cannot be empty.';
        } else {
            // Store operator message
            $db->prepare('INSERT INTO dev_task_messages (task_id, role, content) VALUES (?, "operator", ?)')
               ->execute([$taskId, $userMsg]);

            // Update status to clarifying if still draft
            if ($task['status'] === 'draft') {
                $db->prepare('UPDATE dev_tasks SET status = "clarifying" WHERE id = ?')->execute([$taskId]);
                $task['status'] = 'clarifying';
            }

            // Build GPT context
            try {
                $ai      = new OpenRouterClient();
                $gptMsgs = buildGptMessages($task, $messages, $userMsg, $db);
                $reply   = $ai->chat($gptMsgs);

                // Store assistant reply
                $db->prepare('INSERT INTO dev_task_messages (task_id, role, content) VALUES (?, "assistant", ?)')
                   ->execute([$taskId, $reply]);

                // Check if reply contains final spec JSON
                if (str_contains($reply, '===FINAL_TASK_SPEC===')) {
                    // Extract spec
                    $specPart = extractFinalSpec($reply);
                    if ($specPart) {
                        $db->prepare(
                            'UPDATE dev_tasks SET final_task_spec = ?, status = "ready_to_run" WHERE id = ?'
                        )->execute([$specPart, $taskId]);
                        $task['final_task_spec'] = $specPart;
                        $task['status'] = 'ready_to_run';
                    }
                }

                Logger::log('ai_request', "Chat message processed for task $taskId", $taskId, $task['project_id']);
            } catch (\Throwable $e) {
                $error = 'AI error: ' . $e->getMessage();
                Logger::log('error', 'Chat AI error: ' . $e->getMessage(), $taskId);
            }

            // Reload messages
            $msgStmt->execute([$taskId]);
            $messages = $msgStmt->fetchAll();
        }
    } elseif ($action === 'update_spec') {
        $spec = trim($_POST['final_task_spec'] ?? '');
        $db->prepare('UPDATE dev_tasks SET final_task_spec = ?, status = "ready_to_run" WHERE id = ?')
           ->execute([$spec, $taskId]);
        $task['final_task_spec'] = $spec;
        $task['status'] = 'ready_to_run';
    } elseif ($action === 'generate_spec') {
        // Ask GPT to generate the final spec now
        try {
            $ai      = new OpenRouterClient();
            $gptMsgs = buildGptMessages($task, $messages, 'Generate the Final Task Spec now. Include all required sections and mark it with ===FINAL_TASK_SPEC===.', $db);
            $reply   = $ai->chat($gptMsgs);

            $db->prepare('INSERT INTO dev_task_messages (task_id, role, content) VALUES (?, "assistant", ?)')
               ->execute([$taskId, $reply]);

            $specPart = extractFinalSpec($reply);
            if ($specPart) {
                $db->prepare('UPDATE dev_tasks SET final_task_spec = ?, status = "ready_to_run" WHERE id = ?')
                   ->execute([$specPart, $taskId]);
                $task['final_task_spec'] = $specPart;
                $task['status'] = 'ready_to_run';
            }

            $msgStmt->execute([$taskId]);
            $messages = $msgStmt->fetchAll();
        } catch (\Throwable $e) {
            $error = 'AI error: ' . $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------------
function buildGptMessages(array $task, array $history, string $newUserMsg, \PDO $db): array
{
    // Fetch active tasks for context
    $activeStmt = $db->prepare(
        'SELECT id, title, status, branch_name
         FROM dev_tasks
         WHERE repository_id = ? AND id != ? AND status NOT IN ("draft","merged","failed","cancelled")'
    );
    $activeStmt->execute([$task['repository_id'], $task['id']]);
    $activeTasks = $activeStmt->fetchAll();
    $activeList  = implode("\n", array_map(
        fn($t) => "- #{$t['id']}: {$t['title']} [{$t['status']}]" . ($t['branch_name'] ? " ({$t['branch_name']})" : ''),
        $activeTasks
    ));

    $system = <<<SYS
You are an expert technical lead and AI development assistant inside DevBridge.

Your role is to help an operator define a clear, detailed development task for a coding agent.

Project: {$task['project_name']}
Tech Stack: {$task['tech_stack']}
Business Goal: {$task['business_goal']}

Project Rules (MUST be followed by all tasks):
{$task['global_rules']}

Repository: {$task['github_owner']}/{$task['github_repo']} (branch: {$task['default_branch']})

Currently active tasks in this repository:
{$activeList}

Your job:
1. Ask clarifying questions if the task is unclear.
2. Propose allowed files, forbidden files, acceptance criteria, and dependencies.
3. Decide if the task should be split into sub-tasks.
4. Decide if the task can run in parallel with current active tasks.
5. Propose a conflict risk level (none/low/medium/high/blocking).
6. When you have enough information, generate the FINAL TASK SPEC.

IMPORTANT: When you generate the Final Task Spec, format it like this:

===FINAL_TASK_SPEC===
# Task: [title]
## Goal
...
## Final Expected Behavior
...
## UI Requirements
...
## API Requirements
...
## Database Changes
...
## Files Likely Affected
...
## Allowed Files
...
## Forbidden Files
...
## Expected Files
...
## Acceptance Criteria
- [ ] ...
## Test Plan
...
## Rollback Notes
...
## Roadmap Branch
...
## Roadmap Item Code
...
## Dependencies
...
## Parallel Execution
...
## Conflict Risk
...
## Risk Level
...
## Prompt for Coding Agent
...
===END_SPEC===

Do NOT create a GitHub Issue yet. Only generate the spec.
SYS;

    $msgs = [['role' => 'system', 'content' => $system]];

    // Add history
    foreach ($history as $m) {
        $role = $m['role'] === 'operator' ? 'user' : ($m['role'] === 'assistant' ? 'assistant' : 'system');
        $msgs[] = ['role' => $role, 'content' => $m['content']];
    }

    // Add initial context if first message
    if (empty($history)) {
        $msgs[] = [
            'role'    => 'user',
            'content' => "Original operator request:\n{$task['original_operator_request']}\n\n$newUserMsg",
        ];
    } else {
        $msgs[] = ['role' => 'user', 'content' => $newUserMsg];
    }

    return $msgs;
}

function extractFinalSpec(string $reply): string
{
    if (preg_match('/===FINAL_TASK_SPEC===([\s\S]+?)===END_SPEC===/i', $reply, $m)) {
        return trim($m[1]);
    }
    // Fallback: everything after the marker
    if (preg_match('/===FINAL_TASK_SPEC===([\s\S]+)/i', $reply, $m)) {
        return trim($m[1]);
    }
    return '';
}

$pageTitle = 'Task Chat: ' . $task['title'];
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="flex gap-2 mb-4 items-center">
  <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $taskId ?>" class="btn btn-secondary btn-sm">← Back to Task</a>
  <span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $task['status']), ENT_QUOTES, 'UTF-8') ?></span>
  <span class="text-muted text-sm"><?= htmlspecialchars($task['github_owner'] . '/' . $task['github_repo'], ENT_QUOTES, 'UTF-8') ?></span>
</div>

<div class="flex gap-3" style="align-items:flex-start">
  <!-- Chat Column -->
  <div style="flex:1.4">
    <div class="card">
      <div class="card-title">💬 Chat with GPT</div>

      <?php if (empty($messages)): ?>
        <p class="text-muted text-sm">Start the conversation. Describe your task or ask GPT to analyze the initial request.</p>
      <?php else: ?>
        <div class="chat-messages" id="chat-messages">
          <?php foreach ($messages as $msg): ?>
            <div>
              <div class="chat-bubble <?= htmlspecialchars($msg['role'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="chat-meta <?= $msg['role'] === 'operator' ? 'text-right' : '' ?>">
                <?= $msg['role'] === 'operator' ? '👤 You' : '🤖 GPT' ?> · <?= htmlspecialchars(substr($msg['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="mt-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="message">
        <div class="form-group">
          <textarea name="message" id="msg-input" style="min-height:80px"
                    placeholder="Ask GPT to clarify, analyze, or generate the spec..."><?= htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn btn-primary">Send</button>
          <button type="submit" name="action" value="generate_spec" class="btn btn-secondary">⚡ Generate Final Spec Now</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Spec Column -->
  <div style="flex:1">
    <div class="card">
      <div class="card-title">📋 Final Task Spec</div>
      <?php if ($task['final_task_spec']): ?>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;max-height:400px;overflow:auto;font-size:0.85rem;white-space:pre-wrap;margin-bottom:14px"><?= htmlspecialchars($task['final_task_spec'], ENT_QUOTES, 'UTF-8') ?></div>

        <form method="post">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="update_spec">
          <div class="form-group">
            <label>Edit spec manually (optional)</label>
            <textarea name="final_task_spec" style="min-height:200px"><?= htmlspecialchars($task['final_task_spec'], ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>
          <div class="flex gap-2">
            <button type="submit" class="btn btn-secondary btn-sm">Save Edits</button>
          </div>
        </form>

        <?php if (in_array($task['status'], ['ready_to_run', 'clarifying'])): ?>
          <hr class="divider">
          <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $taskId ?>#approve"
             class="btn btn-success" style="width:100%;justify-content:center">
            ✅ Approve &amp; Create GitHub Issue →
          </a>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-muted text-sm">No final spec yet. Continue the chat until GPT generates it, or click "Generate Final Spec Now".</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-title">Task Info</div>
      <table>
        <tr><td class="text-muted" style="width:90px">Status</td><td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $task['status']), ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td class="text-muted">Priority</td><td><?= htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td class="text-muted">Risk</td><td><?= htmlspecialchars($task['risk_level'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td class="text-muted">Run Mode</td><td><?= htmlspecialchars(str_replace('_', ' ', $task['run_mode']), ENT_QUOTES, 'UTF-8') ?></td></tr>
      </table>
    </div>
  </div>
</div>

<script>
// Auto-scroll chat to bottom
const chatEl = document.getElementById('chat-messages');
if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;

// Ctrl+Enter to submit
const msgInput = document.getElementById('msg-input');
if (msgInput) {
    msgInput.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            this.closest('form').submit();
        }
    });
}
</script>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
