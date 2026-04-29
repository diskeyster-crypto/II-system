<?php
declare(strict_types=1);

namespace DevBridge\Core;

use PDO;

class Logger
{
    public static function log(
        string $type,
        string $message,
        ?int $taskId = null,
        ?int $projectId = null,
        ?string $context = null
    ): void {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO logs (type, message, task_id, project_id, context, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$type, $message, $taskId, $projectId, $context]);
        } catch (\Throwable $e) {
            error_log('DevBridge Logger failed: ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 50): array
    {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT * FROM logs ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
