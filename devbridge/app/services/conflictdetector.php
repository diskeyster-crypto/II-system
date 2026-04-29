<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\Core\Database;

/**
 * Detects file conflicts between active dev tasks.
 */
class ConflictDetector
{
    // Conflict levels (ordered by severity)
    public const NONE     = 'none';
    public const LOW      = 'low';
    public const MEDIUM   = 'medium';
    public const HIGH     = 'high';
    public const BLOCKING = 'blocking';

    /** Sensitive paths that elevate conflict to blocking when touched. */
    private const SENSITIVE_PATHS = [
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        '.env',
        'config/',
        'app/Core/',
        'database/',
        'migrations/',
    ];

    /**
     * Analyse conflicts for a candidate task against currently active tasks.
     *
     * @param  int    $taskId          The task being evaluated
     * @param  int    $projectId
     * @param  int    $repositoryId
     * @param  array  $allowedFiles    Files the task is allowed to touch
     * @param  array  $forbiddenFiles  Files the task must not touch
     * @return array  ['level' => string, 'details' => array]
     */
    public function detect(
        int   $taskId,
        int   $projectId,
        int   $repositoryId,
        array $allowedFiles = [],
        array $forbiddenFiles = []
    ): array {
        $db = Database::getInstance();

        // Active statuses that mean another task is working on this repo
        $activeStatuses = "('issue_created','assigned_to_agent','pr_created','reviewing','changes_requested','waiting_for_agent')";

        $stmt = $db->prepare(
            "SELECT id, title, allowed_files_json, forbidden_files_json, expected_files_json
             FROM dev_tasks
             WHERE repository_id = ?
               AND id != ?
               AND status IN $activeStatuses"
        );
        $stmt->execute([$repositoryId, $taskId]);
        $activeTasks = $stmt->fetchAll();

        if (empty($activeTasks)) {
            return ['level' => self::NONE, 'details' => []];
        }

        // Check project-level parallel task limit
        $projStmt = $db->prepare('SELECT max_parallel_tasks FROM projects WHERE id = ?');
        $projStmt->execute([$projectId]);
        $project = $projStmt->fetch();

        $repoStmt = $db->prepare('SELECT max_parallel_tasks FROM repositories WHERE id = ?');
        $repoStmt->execute([$repositoryId]);
        $repo = $repoStmt->fetch();

        // Count active tasks for repo
        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM dev_tasks WHERE repository_id = ? AND id != ? AND status IN $activeStatuses"
        );
        $countStmt->execute([$repositoryId, $taskId]);
        $activeCount = (int)$countStmt->fetchColumn();

        $repoMax    = (int)($repo['max_parallel_tasks'] ?? 3);
        $details    = [];
        $maxLevel   = self::NONE;

        // Check parallel task limits
        if ($repoMax > 0 && $activeCount >= $repoMax) {
            $maxLevel = self::BLOCKING;
            $details[] = [
                'type'    => 'parallel_limit',
                'message' => "Repository has reached max parallel tasks ($repoMax). $activeCount active tasks exist.",
            ];
        }

        // Check file conflicts against other active tasks
        foreach ($activeTasks as $other) {
            $otherAllowed   = json_decode($other['allowed_files_json'] ?? '[]', true) ?: [];
            $otherExpected  = json_decode($other['expected_files_json'] ?? '[]', true) ?: [];
            $otherForbidden = json_decode($other['forbidden_files_json'] ?? '[]', true) ?: [];
            $otherFiles     = array_unique(array_merge($otherAllowed, $otherExpected));

            $myFiles = array_unique(array_merge($allowedFiles));

            $overlap = array_intersect($myFiles, $otherFiles);
            if (!empty($overlap)) {
                $level   = $this->fileConflictLevel($overlap);
                $maxLevel = $this->maxLevel($maxLevel, $level);
                $details[] = [
                    'type'    => 'file_overlap',
                    'task_id' => $other['id'],
                    'task'    => $other['title'],
                    'files'   => array_values($overlap),
                    'level'   => $level,
                ];
            }

            // Check if any of my files are forbidden by the other task
            $crossForbidden = array_intersect($myFiles, $otherForbidden);
            if (!empty($crossForbidden)) {
                $maxLevel = $this->maxLevel($maxLevel, self::HIGH);
                $details[] = [
                    'type'    => 'forbidden_by_other',
                    'task_id' => $other['id'],
                    'task'    => $other['title'],
                    'files'   => array_values($crossForbidden),
                    'level'   => self::HIGH,
                ];
            }
        }

        return ['level' => $maxLevel, 'details' => $details];
    }

    /** Determine conflict level for a set of overlapping files. */
    private function fileConflictLevel(array $files): string
    {
        foreach ($files as $file) {
            foreach (self::SENSITIVE_PATHS as $sensitive) {
                if (str_starts_with($file, $sensitive) || $file === $sensitive) {
                    return self::BLOCKING;
                }
            }
        }
        return count($files) >= 5 ? self::HIGH : (count($files) >= 2 ? self::MEDIUM : self::LOW);
    }

    private function maxLevel(string $current, string $candidate): string
    {
        $order = [self::NONE => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3, self::BLOCKING => 4];
        return ($order[$candidate] ?? 0) > ($order[$current] ?? 0) ? $candidate : $current;
    }
}
