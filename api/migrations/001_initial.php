<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $autoId = 'INT AUTO_INCREMENT PRIMARY KEY';
    $textPk = 'VARCHAR(64) PRIMARY KEY';
    $boolType = 'TINYINT(1)';

    $db->exec("CREATE TABLE IF NOT EXISTS migrations (
        id {$autoId},
        name VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id {$autoId},
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        avatar_url TEXT NULL,
        created_at DATETIME NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS invites (
        id {$autoId},
        token VARCHAR(128) NOT NULL UNIQUE,
        created_by INT NOT NULL,
        expires_at DATETIME NOT NULL,
        used_by INT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id),
        FOREIGN KEY(used_by) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS projects (
        id {$autoId},
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'other',
        client VARCHAR(180) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'baslaniyor',
        priority VARCHAR(20) NOT NULL DEFAULT 'med',
        due_date DATE NULL,
        tags_json TEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS project_members (
        project_id INT NOT NULL,
        user_id INT NOT NULL,
        member_status VARCHAR(40) NOT NULL DEFAULT 'baslaniyor',
        created_at DATETIME NOT NULL,
        PRIMARY KEY(project_id, user_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS stages (
        id {$autoId},
        project_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        order_index INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'baslanmadi',
        assignees_json TEXT NOT NULL,
        est_hours REAL NULL,
        spent_hours REAL NULL,
        links_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS tasks (
        id {$autoId},
        project_id INT NOT NULL,
        stage_id INT NULL,
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        assignee_id INT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'backlog',
        priority VARCHAR(20) NOT NULL DEFAULT 'med',
        due_date DATE NULL,
        order_index INT NOT NULL DEFAULT 0,
        checklist_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(stage_id) REFERENCES stages(id) ON DELETE SET NULL,
        FOREIGN KEY(assignee_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS comments (
        id {$autoId},
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        project_id INT NULL,
        user_id INT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ideas (
        id {$autoId},
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        category VARCHAR(120) NULL,
        tags_json TEXT NOT NULL,
        impact INT NOT NULL DEFAULT 3,
        effort INT NOT NULL DEFAULT 3,
        status VARCHAR(30) NOT NULL DEFAULT 'idea',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id {$autoId},
        actor_id INT NOT NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        project_id INT NULL,
        meta_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(actor_id) REFERENCES users(id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id {$autoId},
        user_id INT NOT NULL,
        type VARCHAR(80) NOT NULL,
        payload_json TEXT NOT NULL,
        is_read {$boolType} NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS events (
        id {$autoId},
        type VARCHAR(80) NOT NULL,
        payload_json TEXT NOT NULL,
        created_at DATETIME NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS stage_templates (
        id {$autoId},
        name VARCHAR(120) NOT NULL,
        order_index INT NOT NULL,
        created_at DATETIME NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        ip {$textPk},
        failed_count INT NOT NULL DEFAULT 0,
        first_failed_at DATETIME NOT NULL,
        lock_until DATETIME NULL
    )");
};
