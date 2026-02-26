<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function user_ids_for_project(PDO $db, int $projectId): array
{
    $stmt = $db->prepare('SELECT DISTINCT user_id FROM project_members WHERE project_id = :project_id');
    $stmt->execute(['project_id' => $projectId]);
    $rows = $stmt->fetchAll();

    $ids = array_map(static fn(array $row): int => (int) $row['user_id'], $rows);
    return array_values(array_unique($ids));
}

function all_user_ids(PDO $db): array
{
    $rows = $db->query('SELECT id FROM users')->fetchAll();
    return array_map(static fn(array $row): int => (int) $row['id'], $rows);
}

function publish_event(PDO $db, string $type, array $payload, ?array $recipientIds = null): int
{
    $payloadJson = encode_json($payload);
    $createdAt = now_utc();
    $eventId = 0;

    try {
        $eventStmt = $db->prepare('INSERT INTO events(type, payload_json, created_at) VALUES(:type, :payload_json, :created_at)');
        $eventStmt->execute([
            'type' => $type,
            'payload_json' => $payloadJson,
            'created_at' => $createdAt,
        ]);
        $eventId = (int) $db->lastInsertId();
    } catch (Throwable $e) {
        error_log('publish_event(events) failed: ' . $e->getMessage());
    }

    $users = $recipientIds;
    if ($users === null) {
        $users = all_user_ids($db);
    }

    $users = array_values(array_unique(array_map('intval', $users)));

    if ($users) {
        try {
            $notifStmt = $db->prepare('INSERT INTO notifications(user_id, type, payload_json, is_read, created_at) VALUES(:user_id, :type, :payload_json, 0, :created_at)');
            foreach ($users as $userId) {
                $notifStmt->execute([
                    'user_id' => $userId,
                    'type' => $type,
                    'payload_json' => $payloadJson,
                    'created_at' => $createdAt,
                ]);
            }
        } catch (Throwable $e) {
            error_log('publish_event(notifications) failed: ' . $e->getMessage());
        }
    }

    return $eventId;
}

function audit_log(PDO $db, int $actorId, string $action, string $entityType, int $entityId, ?int $projectId = null, array $meta = []): void
{
    try {
        $stmt = $db->prepare('INSERT INTO audit_logs(actor_id, action, entity_type, entity_id, project_id, meta_json, created_at) VALUES(:actor_id, :action, :entity_type, :entity_id, :project_id, :meta_json, :created_at)');
        $stmt->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'project_id' => $projectId,
            'meta_json' => encode_json($meta),
            'created_at' => now_utc(),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}
