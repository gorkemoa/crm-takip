<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    $hasTable = static function (string $table) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM sqlite_master WHERE type = 'table' AND name = :table_name");
            $stmt->execute(['table_name' => $table]);
            return (int) ($stmt->fetch()['c'] ?? 0) > 0;
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
        $stmt->execute(['table_name' => $table]);
        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    };

    $hasColumn = static function (string $table, string $column) use ($db, $driver): bool {
        if ($driver === 'sqlite') {
            $stmt = $db->query('PRAGMA table_info(' . $table . ')');
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    };

    if (!$hasColumn('projects', 'mvp_json')) {
        $db->exec("ALTER TABLE projects ADD COLUMN mvp_json TEXT NULL");
    }
    if (!$hasColumn('projects', 'todo_features_json')) {
        $db->exec("ALTER TABLE projects ADD COLUMN todo_features_json TEXT NULL");
    }
    $db->exec("UPDATE projects SET mvp_json = '[]' WHERE mvp_json IS NULL OR mvp_json = ''");
    $db->exec("UPDATE projects SET todo_features_json = '[]' WHERE todo_features_json IS NULL OR todo_features_json = ''");

    if (!$hasColumn('ideas', 'mvp_json')) {
        $db->exec("ALTER TABLE ideas ADD COLUMN mvp_json TEXT NULL");
    }
    if (!$hasColumn('ideas', 'todo_features_json')) {
        $db->exec("ALTER TABLE ideas ADD COLUMN todo_features_json TEXT NULL");
    }
    $db->exec("UPDATE ideas SET mvp_json = '[]' WHERE mvp_json IS NULL OR mvp_json = ''");
    $db->exec("UPDATE ideas SET todo_features_json = '[]' WHERE todo_features_json IS NULL OR todo_features_json = ''");

    if (!$hasTable('design_assets')) {
        if ($driver === 'sqlite') {
            $db->exec('CREATE TABLE design_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INT NOT NULL,
                project_id INT NULL,
                title TEXT NULL,
                image_url TEXT NOT NULL,
                description TEXT NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL
            )');
        } else {
            $db->exec('CREATE TABLE design_assets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(20) NOT NULL,
                entity_id INT NOT NULL,
                project_id INT NULL,
                title VARCHAR(180) NULL,
                image_url TEXT NOT NULL,
                description TEXT NULL,
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
    }

    if (!$hasTable('design_asset_likes')) {
        if ($driver === 'sqlite') {
            $db->exec('CREATE TABLE design_asset_likes (
                asset_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(asset_id, user_id)
            )');
        } else {
            $db->exec('CREATE TABLE design_asset_likes (
                asset_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(asset_id, user_id),
                FOREIGN KEY(asset_id) REFERENCES design_assets(id) ON DELETE CASCADE,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
        }
    }

    $indexes = [
        'CREATE INDEX idx_design_assets_entity ON design_assets(entity_type, entity_id, created_at)',
        'CREATE INDEX idx_design_assets_project ON design_assets(project_id)',
        'CREATE INDEX idx_design_assets_creator ON design_assets(created_by)',
        'CREATE INDEX idx_design_asset_likes_user ON design_asset_likes(user_id)',
    ];

    foreach ($indexes as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            $message = strtolower($e->getMessage());
            $alreadyExists = str_contains($message, 'already exists') || str_contains($message, 'duplicate key name');
            if (!$alreadyExists) {
                throw $e;
            }
        }
    }
};

