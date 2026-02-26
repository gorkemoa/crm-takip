<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/sse.php';

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$dataDir = $root . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

date_default_timezone_set('UTC');

try {
    $db = db();
    $db->beginTransaction();

    $files = glob(__DIR__ . '/../migrations/*.php');
    sort($files);

    $db->exec('CREATE TABLE IF NOT EXISTS migrations (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL)');

    $checkStmt = $db->prepare('SELECT COUNT(*) AS c FROM migrations WHERE name = :name');
    $insertStmt = $db->prepare('INSERT INTO migrations(name, applied_at) VALUES(:name, :applied_at)');

    foreach ($files as $file) {
        $name = basename($file);
        $checkStmt->execute(['name' => $name]);
        $exists = (int) $checkStmt->fetch()['c'] > 0;

        if ($exists) {
            continue;
        }

        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException("Migration callable değil: {$name}");
        }

        $migration($db);

        $insertStmt->execute([
            'name' => $name,
            'applied_at' => now_utc(),
        ]);

        echo "Applied migration: {$name}\n";
    }

    $adminStmt = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $adminStmt->execute(['username' => 'admin']);
    $admin = $adminStmt->fetch();

    if (!$admin) {
        $adminStmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $adminStmt->execute(['email' => 'admin@local']);
        $admin = $adminStmt->fetch();
    }

    if (!$admin) {
        $insertAdmin = $db->prepare('INSERT INTO users(name, username, title, email, password_hash, role, avatar_url, created_at) VALUES(:name, :username, :title, :email, :password_hash, :role, :avatar_url, :created_at)');
        $insertAdmin->execute([
            'name' => 'System Admin',
            'username' => 'admin',
            'title' => 'Yönetici',
            'email' => 'admin@local',
            'password_hash' => password_hash('Admin123!', PASSWORD_DEFAULT),
            'role' => 'admin',
            'avatar_url' => null,
            'created_at' => now_utc(),
        ]);

        $adminId = (int) $db->lastInsertId();
        echo "Seeded admin user: admin / Admin123!\n";
    } else {
        $adminId = (int) $admin['id'];

        $updates = [];
        $bind = ['id' => $adminId];
        if (empty($admin['username'])) {
            $updates[] = 'username = :username';
            $bind['username'] = 'admin';
        }
        if (empty($admin['title'])) {
            $updates[] = 'title = :title';
            $bind['title'] = 'Yönetici';
        }
        if ($updates) {
            $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id')->execute($bind);
        }
    }

    $projectCount = (int) $db->query('SELECT COUNT(*) AS c FROM projects')->fetch()['c'];
    if ($projectCount === 0) {
        $now = now_utc();
        $insertProject = $db->prepare('INSERT INTO projects(title, description, type, client, status, priority, due_date, tags_json, created_by, created_at, updated_at) VALUES(:title, :description, :type, :client, :status, :priority, :due_date, :tags_json, :created_by, :created_at, :updated_at)');
        $insertProject->execute([
            'title' => 'Apple-Style CRM v1',
            'description' => 'Ekip içi proje ve iş akışı takibi için ilk sürüm.',
            'type' => 'web',
            'client' => 'Internal',
            'status' => 'gelistiriliyor',
            'priority' => 'high',
            'due_date' => gmdate('Y-m-d', strtotime('+21 days UTC')),
            'tags_json' => encode_json(['crm', 'internal', 'v1']),
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $projectId = (int) $db->lastInsertId();

        $db->prepare('INSERT INTO project_members(project_id, user_id, member_status, created_at) VALUES(:project_id, :user_id, :member_status, :created_at)')
            ->execute([
                'project_id' => $projectId,
                'user_id' => $adminId,
                'member_status' => 'gelistiriliyor',
                'created_at' => $now,
            ]);

        $templates = $db->query('SELECT name, order_index FROM stage_templates ORDER BY order_index ASC')->fetchAll();
        $stageStmt = $db->prepare('INSERT INTO stages(project_id, name, order_index, status, assignees_json, est_hours, spent_hours, links_json, created_at, updated_at) VALUES(:project_id, :name, :order_index, :status, :assignees_json, :est_hours, :spent_hours, :links_json, :created_at, :updated_at)');

        foreach ($templates as $tpl) {
            $stageStmt->execute([
                'project_id' => $projectId,
                'name' => $tpl['name'],
                'order_index' => (int) $tpl['order_index'],
                'status' => 'baslanmadi',
                'assignees_json' => encode_json([$adminId]),
                'est_hours' => 8,
                'spent_hours' => 0,
                'links_json' => encode_json([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $firstStageId = (int) $db->query('SELECT id FROM stages WHERE project_id = ' . $projectId . ' ORDER BY order_index ASC LIMIT 1')->fetch()['id'];

        $taskStmt = $db->prepare('INSERT INTO tasks(project_id, stage_id, title, description, assignee_id, status, priority, due_date, order_index, checklist_json, created_at, updated_at) VALUES(:project_id, :stage_id, :title, :description, :assignee_id, :status, :priority, :due_date, :order_index, :checklist_json, :created_at, :updated_at)');
        $taskStmt->execute([
            'project_id' => $projectId,
            'stage_id' => $firstStageId,
            'title' => 'Bilgi mimarisi çıkar',
            'description' => 'CRM ana akışları için IA dokümanı hazırla.',
            'assignee_id' => $adminId,
            'status' => 'arastiriliyor',
            'priority' => 'high',
            'due_date' => gmdate('Y-m-d', strtotime('+5 days UTC')),
            'order_index' => 0,
            'checklist_json' => encode_json(['Dashboard kartları', 'Proje detay sekmeleri']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $taskStmt->execute([
            'project_id' => $projectId,
            'stage_id' => $firstStageId,
            'title' => 'SSE akışını doğrula',
            'description' => 'İki sekmede canlı bildirim testi yap.',
            'assignee_id' => $adminId,
            'status' => 'test_ediliyor',
            'priority' => 'med',
            'due_date' => gmdate('Y-m-d', strtotime('+7 days UTC')),
            'order_index' => 1,
            'checklist_json' => encode_json(['Event stream', 'Notification center']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        audit_log($db, $adminId, 'create', 'project', $projectId, $projectId, ['title' => 'Apple-Style CRM v1']);
        publish_event($db, 'project_created', [
            'project_id' => $projectId,
            'title' => 'Apple-Style CRM v1',
            'actor_id' => $adminId,
            'message' => 'Seed proje oluşturuldu.',
            'created_at' => $now,
        ]);

        echo "Seeded sample project.\n";
    }

    $ideaCount = (int) $db->query('SELECT COUNT(*) AS c FROM ideas')->fetch()['c'];
    if ($ideaCount === 0) {
        $ideaStmt = $db->prepare('INSERT INTO ideas(title, description, category, tags_json, impact, effort, status, created_by, created_at, updated_at) VALUES(:title, :description, :category, :tags_json, :impact, :effort, :status, :created_by, :created_at, :updated_at)');
        $now = now_utc();

        $ideaStmt->execute([
            'title' => 'AI destekli görev öncelikleme',
            'description' => 'Görev yoğunluğuna göre öneri üreten mini asistan.',
            'category' => 'crm_automasyon',
            'tags_json' => encode_json(['ai', 'priority']),
            'impact' => 5,
            'effort' => 3,
            'status' => 'idea',
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ideaStmt->execute([
            'title' => 'Müşteri portal entegrasyonu',
            'description' => 'Müşteriye sadece gerekli proje bilgilerini açan sade görünüm.',
            'category' => 'urun_stratejisi',
            'tags_json' => encode_json(['portal', 'client']),
            'impact' => 4,
            'effort' => 4,
            'status' => 'researching',
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        echo "Seeded sample ideas.\n";
    }

    $db->commit();
    echo "Initialization complete.\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Init failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
