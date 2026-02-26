<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    $indexSql = [
        'CREATE INDEX IF NOT EXISTS idx_projects_status_updated ON projects(status, updated_at)',
        'CREATE INDEX IF NOT EXISTS idx_projects_created_by ON projects(created_by)',

        'CREATE INDEX IF NOT EXISTS idx_project_members_user_project ON project_members(user_id, project_id)',
        'CREATE INDEX IF NOT EXISTS idx_project_members_project_status ON project_members(project_id, member_status)',

        'CREATE INDEX IF NOT EXISTS idx_stages_project_order ON stages(project_id, order_index)',
        'CREATE INDEX IF NOT EXISTS idx_stages_project_status ON stages(project_id, status)',

        'CREATE INDEX IF NOT EXISTS idx_tasks_project_status_order ON tasks(project_id, status, order_index)',
        'CREATE INDEX IF NOT EXISTS idx_tasks_assignee_status ON tasks(assignee_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_tasks_stage ON tasks(stage_id)',
        'CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks(due_date)',

        'CREATE INDEX IF NOT EXISTS idx_comments_entity ON comments(entity_type, entity_id)',
        'CREATE INDEX IF NOT EXISTS idx_comments_project ON comments(project_id)',
        'CREATE INDEX IF NOT EXISTS idx_comments_user ON comments(user_id)',

        'CREATE INDEX IF NOT EXISTS idx_audit_project_created ON audit_logs(project_id, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_audit_actor_created ON audit_logs(actor_id, created_at)',

        'CREATE INDEX IF NOT EXISTS idx_notifications_user_read_created ON notifications(user_id, is_read, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id, id)',

        'CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_ideas_status_updated ON ideas(status, updated_at)',
        'CREATE INDEX IF NOT EXISTS idx_invites_token_expires ON invites(token, expires_at)',
    ];

    if ($driver === 'mysql') {
        $indexSql = [
            'CREATE INDEX idx_projects_status_updated ON projects(status, updated_at)',
            'CREATE INDEX idx_projects_created_by ON projects(created_by)',

            'CREATE INDEX idx_project_members_user_project ON project_members(user_id, project_id)',
            'CREATE INDEX idx_project_members_project_status ON project_members(project_id, member_status)',

            'CREATE INDEX idx_stages_project_order ON stages(project_id, order_index)',
            'CREATE INDEX idx_stages_project_status ON stages(project_id, status)',

            'CREATE INDEX idx_tasks_project_status_order ON tasks(project_id, status, order_index)',
            'CREATE INDEX idx_tasks_assignee_status ON tasks(assignee_id, status)',
            'CREATE INDEX idx_tasks_stage ON tasks(stage_id)',
            'CREATE INDEX idx_tasks_due_date ON tasks(due_date)',

            'CREATE INDEX idx_comments_entity ON comments(entity_type, entity_id)',
            'CREATE INDEX idx_comments_project ON comments(project_id)',
            'CREATE INDEX idx_comments_user ON comments(user_id)',

            'CREATE INDEX idx_audit_project_created ON audit_logs(project_id, created_at)',
            'CREATE INDEX idx_audit_actor_created ON audit_logs(actor_id, created_at)',

            'CREATE INDEX idx_notifications_user_read_created ON notifications(user_id, is_read, created_at)',
            'CREATE INDEX idx_notifications_user_id ON notifications(user_id, id)',

            'CREATE INDEX idx_events_created ON events(created_at)',
            'CREATE INDEX idx_ideas_status_updated ON ideas(status, updated_at)',
            'CREATE INDEX idx_invites_token_expires ON invites(token, expires_at)',
        ];
    }

    foreach ($indexSql as $sql) {
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
