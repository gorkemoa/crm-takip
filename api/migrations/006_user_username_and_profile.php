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

    if (!$hasColumn('users', 'username')) {
        $db->exec("ALTER TABLE users ADD COLUMN username VARCHAR(80) NULL");
    }

    if (!$hasColumn('users', 'title')) {
        $db->exec("ALTER TABLE users ADD COLUMN title VARCHAR(120) NULL");
    }

    $rows = $db->query('SELECT id, email, username FROM users ORDER BY id ASC')->fetchAll();
    $used = [];

    foreach ($rows as $row) {
        $currentUsername = trim((string) ($row['username'] ?? ''));
        if ($currentUsername !== '') {
            $used[strtolower($currentUsername)] = true;
            continue;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $base = $email !== '' && str_contains($email, '@')
            ? explode('@', $email, 2)[0]
            : 'kullanici' . (int) $row['id'];

        $base = strtolower((string) preg_replace('/[^a-z0-9._-]/', '', $base));
        if ($base === '') {
            $base = 'kullanici' . (int) $row['id'];
        }

        $candidate = $base;
        $suffix = 1;
        while (isset($used[strtolower($candidate)])) {
            $candidate = $base . $suffix;
            $suffix++;
        }

        $updateStmt = $db->prepare('UPDATE users SET username = :username WHERE id = :id');
        $updateStmt->execute([
            'username' => $candidate,
            'id' => (int) $row['id'],
        ]);

        $used[strtolower($candidate)] = true;
    }

    try {
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username_unique ON users(username)');
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $alreadyExists = str_contains($message, 'already exists') || str_contains($message, 'duplicate key name');
        if (!$alreadyExists) {
            throw $e;
        }
    }
};
