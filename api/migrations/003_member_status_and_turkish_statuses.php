<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $hasColumn = static function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        return (int) $stmt->fetch()['c'] > 0;
    };

    if (!$hasColumn('project_members', 'member_status')) {
        $db->exec("ALTER TABLE project_members ADD COLUMN member_status VARCHAR(40) NOT NULL DEFAULT 'baslaniyor'");
    }

    $db->exec("UPDATE project_members SET member_status = 'baslaniyor' WHERE member_status IS NULL OR member_status = ''");

    $projectMap = [
        'active' => 'gelistiriliyor',
        'paused' => 'duraklatildi',
        'done' => 'tamamlandi',
    ];

    $stageMap = [
        'not_started' => 'baslanmadi',
        'in_progress' => 'gelistiriliyor',
        'blocked' => 'engellendi',
        'done' => 'tamamlandi',
    ];

    $taskMap = [
        'todo' => 'backlog',
        'doing' => 'gelistiriliyor',
        'blocked' => 'engellendi',
        'done' => 'tamamlandi',
    ];

    $projectStmt = $db->prepare('UPDATE projects SET status = :new_status WHERE status = :old_status');
    foreach ($projectMap as $old => $new) {
        $projectStmt->execute([
            'old_status' => $old,
            'new_status' => $new,
        ]);
    }

    $stageStmt = $db->prepare('UPDATE stages SET status = :new_status WHERE status = :old_status');
    foreach ($stageMap as $old => $new) {
        $stageStmt->execute([
            'old_status' => $old,
            'new_status' => $new,
        ]);
    }

    $taskStmt = $db->prepare('UPDATE tasks SET status = :new_status WHERE status = :old_status');
    foreach ($taskMap as $old => $new) {
        $taskStmt->execute([
            'old_status' => $old,
            'new_status' => $new,
        ]);
    }
};
