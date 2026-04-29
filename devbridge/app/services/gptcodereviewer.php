<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\AI\AiClientInterface;
use DevBridge\Core\Database;
use DevBridge\Core\Logger;

class GPTCodeReviewer
{
    private AiClientInterface $ai;

    public function __construct(AiClientInterface $ai)
    {
        $this->ai = $ai;
    }

    /**
     * Perform a GPT review of a PR.
     *
     * @param  int   $taskId  The dev_task id
     * @return array  Decoded JSON review result
     */
    public function review(int $taskId): array
    {
        $db   = Database::getInstance();
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
        if (!$task) {
            throw new \RuntimeException("Task $taskId not found.");
        }

        // Fetch latest review snapshot
        $snapStmt = $db->prepare(
            'SELECT * FROM dev_task_reviews WHERE task_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $snapStmt->execute([$taskId]);
        $snapshot = $snapStmt->fetch();

        $messages = $this->buildReviewMessages($task, $snapshot);

        Logger::log('review', 'GPT code review started', $taskId, $task['project_id']);

        try {
            $result = $this->ai->chatJson($messages);
        } catch (\RuntimeException $e) {
            Logger::log('error', 'GPT review failed: ' . $e->getMessage(), $taskId);
            // Store error review record
            $this->storeReview($taskId, [], $e->getMessage());
            throw $e;
        }

        $this->storeReview($taskId, $result);
        Logger::log('review', 'GPT code review completed: ' . ($result['status'] ?? 'unknown'), $taskId, $task['project_id']);

        return $result;
    }

    private function buildReviewMessages(array $task, array|false $snapshot): array
    {
        $criteria  = json_decode($task['acceptance_criteria_json'] ?? '[]', true) ?: [];
        $allowed   = json_decode($task['allowed_files_json'] ?? '[]', true) ?: [];
        $forbidden = json_decode($task['forbidden_files_json'] ?? '[]', true) ?: [];

        $diff        = $snapshot['pr_diff'] ?? '[no diff available]';
        $files       = $snapshot ? json_decode($snapshot['pr_files_json'] ?? '[]', true) : [];
        $checkStatus = $snapshot['check_status_json'] ?? 'unknown';
        $prMeta      = $snapshot ? json_decode($snapshot['pr_meta_json'] ?? '{}', true) : [];

        // Trim diff to avoid token overflow (keep last N chars if huge)
        $maxDiff = 20000;
        if (strlen($diff) > $maxDiff) {
            $diff = '... [TRUNCATED - first part omitted] ...' . substr($diff, -$maxDiff);
        }

        $system = <<<PROMPT
You are an expert AI code reviewer working inside DevBridge, an AI development management system.

Your job is to review a pull request against the original task specification and return a strict JSON result.

Project: {$task['project_name']}
Tech Stack: {$task['tech_stack']}
Project Rules:
{$task['global_rules']}

REVIEW RULES:
- If forbidden files were modified, status MUST be "rejected".
- If a critical or high security issue exists, status MUST be "request_changes" or "rejected".
- If acceptance criteria are not satisfied, status MUST be "request_changes".
- If you cannot safely decide, status MUST be "needs_operator_decision".
- Never return "safe_to_merge" if tests are failing or unknown for critical code.
- No auto-merge, no auto-deploy decisions. Only assess code quality.

Return ONLY valid JSON, no markdown, no explanation.
PROMPT;

        $criteriaList = implode("\n", array_map(fn($c, $i) => ($i + 1) . '. ' . $c, $criteria, array_keys($criteria)));
        $allowedList  = implode(', ', $allowed) ?: 'any';
        $forbiddenList = implode(', ', $forbidden) ?: 'none';
        $filesChanged = implode(', ', array_column($files, 'filename')) ?: 'unknown';

        $user = <<<PROMPT
ORIGINAL OPERATOR REQUEST:
{$task['original_operator_request']}

FINAL TASK SPEC:
{$task['final_task_spec']}

ACCEPTANCE CRITERIA:
$criteriaList

ALLOWED FILES: $allowedList
FORBIDDEN FILES: $forbiddenList
FILES CHANGED IN PR: $filesChanged
CI/CHECK STATUS: $checkStatus

PULL REQUEST DIFF:
$diff

Return this EXACT JSON structure:
{
  "status": "approved|request_changes|rejected|needs_operator_decision",
  "score": <0-100>,
  "summary": "<brief summary>",
  "acceptance_criteria": {"<criterion>": true|false},
  "blocking_issues": [{"severity":"critical|high|medium","type":"security|architecture|billing|bug|compatibility","file":"<file>","line":"<line or null>","message":"<msg>","suggested_fix":"<fix>"}],
  "non_blocking_issues": [{"severity":"low","type":"style|refactor|docs","file":"<file>","message":"<msg>","suggested_fix":"<fix>"}],
  "files_that_should_not_be_changed": [],
  "missing_requirements": [],
  "roadmap_update_needed": false,
  "roadmap_update_reason": null,
  "operator_question": null,
  "merge_decision": "do_not_merge|merge_after_fixes|safe_to_merge"
}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ];
    }

    private function storeReview(int $taskId, array $result, string $error = ''): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO dev_task_reviews (task_id, review_json, error, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([
            $taskId,
            $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            $error ?: null,
        ]);
    }
}
