<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/validators.php';
require_once __DIR__ . '/lib/sse.php';

date_default_timezone_set('UTC');

set_exception_handler(static function (Throwable $e): void {
    error_log('Unhandled exception: ' . $e->getMessage());

    $isApiRequest = str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    $isDebug = app_debug_enabled();

    if ($isApiRequest) {
        error_response(
            'INTERNAL_SERVER_ERROR',
            $isDebug ? ('Sunucu hatası: ' . $e->getMessage()) : 'Sunucu tarafında beklenmeyen bir hata oluştu.',
            500
        );
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $isDebug ? ('Internal Server Error: ' . $e->getMessage()) : 'Internal Server Error';
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function request_path(): string
{
    $pathInfo = $_SERVER['PATH_INFO'] ?? null;
    if ($pathInfo && is_string($pathInfo)) {
        return '/' . ltrim($pathInfo, '/');
    }

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    if ($script && str_starts_with($uriPath, $script)) {
        $uriPath = substr($uriPath, strlen($script));
    }

    $uriPath = '/' . ltrim((string) $uriPath, '/');
    return $uriPath === '' ? '/' : $uriPath;
}

function public_user(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'username' => $row['username'] ?? null,
        'title' => $row['title'] ?? null,
        'email' => $row['email'],
        'role' => $row['role'],
        'avatar_url' => $row['avatar_url'] ?? null,
        'created_at' => $row['created_at'],
    ];
}

function normalize_tags(mixed $value): array
{
    if ($value === null) {
        return [];
    }

    if (is_string($value)) {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');
        return array_values(array_unique($parts));
    }

    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        $text = trim((string) $item);
        if ($text !== '') {
            $result[] = $text;
        }
    }

    return array_values(array_unique($result));
}

function normalize_work_items(mixed $value, int $maxItems = 120, int $maxText = 220): array
{
    $items = [];

    if (is_string($value)) {
        $parts = preg_split('/\r\n|\r|\n/', $value) ?: [];
        foreach ($parts as $part) {
            $text = trim((string) $part);
            if ($text !== '') {
                $items[] = ['text' => $text, 'done' => false];
            }
        }
    } elseif (is_array($value)) {
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $text = trim($entry);
                if ($text === '') {
                    continue;
                }
                $items[] = ['text' => $text, 'done' => false];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $text = trim((string) ($entry['text'] ?? $entry['title'] ?? ''));
            if ($text === '') {
                continue;
            }

            $done = bool_param($entry['done'] ?? ($entry['completed'] ?? false), false);
            $items[] = ['text' => $text, 'done' => $done];
        }
    }

    $normalized = [];
    foreach ($items as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        if (text_length($text) > $maxText) {
            $text = text_slice($text, 0, $maxText);
        }
        $normalized[] = [
            'text' => $text,
            'done' => (bool) ($item['done'] ?? false),
        ];

        if (count($normalized) >= $maxItems) {
            break;
        }
    }

    return $normalized;
}

function normalize_string_list(mixed $value): array
{
    if ($value === null) {
        return [];
    }

    $items = [];

    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = explode(',', $trimmed);
        }
    } else {
        $items = [(string) $value];
    }

    $result = [];
    foreach ($items as $item) {
        $text = trim((string) $item);
        if ($text !== '') {
            $result[] = $text;
        }
    }

    return array_values(array_unique($result));
}

function decode_multi_value_field(mixed $value): array
{
    return normalize_string_list($value);
}

function encode_multi_value_field(array $values): ?string
{
    $clean = normalize_string_list($values);
    if (!$clean) {
        return null;
    }

    if (count($clean) === 1) {
        return $clean[0];
    }

    return encode_json($clean);
}

function build_multi_value_filter_sql(string $column, array $values, string $paramPrefix, array &$params): ?string
{
    $parts = [];
    $index = 0;

    foreach ($values as $value) {
        $eqKey = $paramPrefix . '_eq_' . $index;
        $likeKey = $paramPrefix . '_like_' . $index;
        $parts[] = '(' . $column . ' = :' . $eqKey . ' OR ' . $column . ' LIKE :' . $likeKey . ')';
        $params[$eqKey] = $value;
        $params[$likeKey] = '%"' . $value . '"%';
        $index++;
    }

    if (!$parts) {
        return null;
    }

    return '(' . implode(' OR ', $parts) . ')';
}

function project_type_values(): array
{
    return ['web', 'mobil', 'app', 'tasarim', 'diger'];
}

function normalize_project_type_value(string $value): string
{
    return match (trim(strtolower($value))) {
        'mobile', 'mobil_uygulama' => 'mobil',
        'design' => 'tasarim',
        'other' => 'diger',
        default => trim(strtolower($value)),
    };
}

function normalize_project_types(mixed $value): array
{
    $items = decode_multi_value_field($value);
    $allowed = project_type_values();
    $result = [];

    foreach ($items as $item) {
        $normalized = normalize_project_type_value((string) $item);
        if (in_array($normalized, $allowed, true)) {
            $result[] = $normalized;
        }
    }

    return array_values(array_unique($result));
}

function normalize_idea_category_value(string $value): string
{
    return match (trim(strtolower($value))) {
        'web_platformu' => 'web',
        'mobil_uygulama' => 'mobil',
        default => trim(strtolower($value)),
    };
}

function normalize_idea_categories(mixed $value): array
{
    $items = decode_multi_value_field($value);
    $allowed = idea_category_values();
    $result = [];

    foreach ($items as $item) {
        $normalized = normalize_idea_category_value((string) $item);
        if (in_array($normalized, $allowed, true)) {
            $result[] = $normalized;
        }
    }

    return array_values(array_unique($result));
}

function normalize_username(string $value): string
{
    $username = strtolower(trim($value));
    $username = str_replace(' ', '.', $username);
    $username = (string) preg_replace('/[^a-z0-9._-]/', '', $username);
    return trim($username, '.-_');
}

function validate_username_or_error(string $username, string $field = 'username'): void
{
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
        error_response('VALIDATION_ERROR', 'Geçersiz kullanıcı adı.', 422, [
            $field => '3-40 karakter, küçük harf/rakam/._- kullanın.',
        ]);
    }
}

function ensure_username_available(PDO $db, string $username, ?int $excludeUserId = null): void
{
    $sql = 'SELECT id FROM users WHERE username = :username';
    $params = ['username' => $username];
    if ($excludeUserId !== null) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        error_response('USERNAME_EXISTS', 'Bu kullanıcı adı kullanımda.', 409, [
            'username' => 'Kullanıcı adı kullanımda.',
        ]);
    }
}

function build_internal_email(PDO $db, string $username, ?int $excludeUserId = null): string
{
    $base = $username . '@local';
    $candidate = $base;
    $suffix = 1;

    while (true) {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = ['email' => $candidate];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $candidate;
        }

        $candidate = $username . '+' . $suffix . '@local';
        $suffix++;
        if ($suffix > 1000) {
            error_response('EMAIL_GENERATION_FAILED', 'Kullanıcı için benzersiz dahili e-posta üretilemedi.', 500);
        }
    }
}

function user_names_for_ids(PDO $db, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT id, name FROM users WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $nameById = [];
    foreach ($rows as $row) {
        $nameById[(int) $row['id']] = (string) $row['name'];
    }

    $result = [];
    foreach ($ids as $id) {
        if (isset($nameById[$id])) {
            $result[] = $nameById[$id];
        }
    }

    return $result;
}

function project_status_values(): array
{
    return [
        'baslaniyor',
        'tartisiliyor',
        'arastiriliyor',
        'tasarlaniyor',
        'gelistiriliyor',
        'test_ediliyor',
        'yayina_hazir',
        'yayinda',
        'bakimda',
        'duraklatildi',
        'iptal_talebi',
        'iptal_edildi',
        'tamamlandi',
    ];
}

function stage_status_values(): array
{
    return [
        'baslanmadi',
        'baslaniyor',
        'tartisiliyor',
        'arastiriliyor',
        'tasarlaniyor',
        'gelistiriliyor',
        'test_ediliyor',
        'engellendi',
        'tamamlandi',
    ];
}

function task_status_values(): array
{
    return [
        'backlog',
        'baslaniyor',
        'tartisiliyor',
        'arastiriliyor',
        'tasarlaniyor',
        'gelistiriliyor',
        'test_ediliyor',
        'incelemede',
        'engellendi',
        'tamamlandi',
    ];
}

function member_status_values(): array
{
    return project_status_values();
}

function normalize_project_status(string $status): string
{
    return match ($status) {
        'active' => 'gelistiriliyor',
        'paused' => 'duraklatildi',
        'done' => 'tamamlandi',
        'cancel_requested' => 'iptal_talebi',
        'canceled', 'cancelled' => 'iptal_edildi',
        default => $status,
    };
}

function normalize_stage_status(string $status): string
{
    return match ($status) {
        'not_started' => 'baslanmadi',
        'in_progress' => 'gelistiriliyor',
        'blocked' => 'engellendi',
        'done' => 'tamamlandi',
        default => $status,
    };
}

function normalize_task_status(string $status): string
{
    return match ($status) {
        'todo' => 'backlog',
        'doing' => 'gelistiriliyor',
        'blocked' => 'engellendi',
        'done' => 'tamamlandi',
        default => $status,
    };
}

function is_task_done_status(string $status): bool
{
    $normalized = normalize_task_status($status);
    return $normalized === 'tamamlandi';
}

function ensure_project_manage_access(array $user, array $project): void
{
    if (($user['role'] ?? '') === 'admin') {
        return;
    }

    if ((int) ($project['created_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return;
    }

    error_response('FORBIDDEN', 'Bu işlem için proje sahibi veya admin olmalısınız.', 403);
}

function add_project_members(PDO $db, int $projectId, array $memberIds, string $defaultStatus = 'baslaniyor'): void
{
    if (!$memberIds) {
        return;
    }

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'mysql'
        ? 'INSERT IGNORE INTO project_members(project_id, user_id, member_status, created_at) VALUES(:project_id, :user_id, :member_status, :created_at)'
        : 'INSERT OR IGNORE INTO project_members(project_id, user_id, member_status, created_at) VALUES(:project_id, :user_id, :member_status, :created_at)';

    $stmt = $db->prepare($sql);
    $createdAt = now_utc();
    $status = in_array($defaultStatus, member_status_values(), true) ? $defaultStatus : 'baslaniyor';

    foreach (array_unique(array_map('intval', $memberIds)) as $memberId) {
        $stmt->execute([
            'project_id' => $projectId,
            'user_id' => $memberId,
            'member_status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}

function create_project_stages_from_templates(PDO $db, int $projectId): void
{
    $templates = $db->query('SELECT name, order_index FROM stage_templates ORDER BY order_index ASC')->fetchAll();

    $insert = $db->prepare('INSERT INTO stages(project_id, name, order_index, status, assignees_json, est_hours, spent_hours, links_json, created_at, updated_at) VALUES(:project_id, :name, :order_index, :status, :assignees_json, :est_hours, :spent_hours, :links_json, :created_at, :updated_at)');
    $now = now_utc();

    foreach ($templates as $template) {
        $insert->execute([
            'project_id' => $projectId,
            'name' => $template['name'],
            'order_index' => (int) $template['order_index'],
            'status' => 'baslanmadi',
            'assignees_json' => encode_json([]),
            'est_hours' => null,
            'spent_hours' => null,
            'links_json' => encode_json([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function ensure_project_access(PDO $db, array $user, int $projectId): array
{
    $projectStmt = $db->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $projectStmt->execute(['id' => $projectId]);
    $project = $projectStmt->fetch();

    if (!$project) {
        error_response('NOT_FOUND', 'Proje bulunamadı.', 404);
    }

    if ($user['role'] === 'admin') {
        return $project;
    }

    $memberStmt = $db->prepare('SELECT 1 FROM project_members WHERE project_id = :project_id AND user_id = :user_id LIMIT 1');
    $memberStmt->execute([
        'project_id' => $projectId,
        'user_id' => (int) $user['id'],
    ]);

    if (!$memberStmt->fetchColumn()) {
        error_response('FORBIDDEN', 'Bu projeyi görüntüleme yetkiniz yok.', 403);
    }

    return $project;
}

function load_project_members(PDO $db, int $projectId): array
{
    $stmt = $db->prepare('SELECT u.id, u.name, u.username, u.title, u.email, u.role, u.avatar_url, pm.member_status FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = :project_id ORDER BY u.name ASC');
    $stmt->execute(['project_id' => $projectId]);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'username' => $row['username'] ?? null,
            'title' => $row['title'] ?? null,
            'email' => $row['email'],
            'role' => $row['role'],
            'avatar_url' => $row['avatar_url'],
            'member_status' => normalize_project_status((string) ($row['member_status'] ?? 'baslaniyor')),
        ];
    }, $rows);
}

function load_stage_row(PDO $db, int $stageId): array
{
    $stmt = $db->prepare('SELECT * FROM stages WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $stageId]);
    $stage = $stmt->fetch();

    if (!$stage) {
        error_response('NOT_FOUND', 'Aşama bulunamadı.', 404);
    }

    return $stage;
}

function load_task_row(PDO $db, int $taskId): array
{
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $taskId]);
    $task = $stmt->fetch();

    if (!$task) {
        error_response('NOT_FOUND', 'Görev bulunamadı.', 404);
    }

    return $task;
}

function design_asset_entity_values(): array
{
    return ['project', 'idea'];
}

function map_design_asset_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_id' => (int) $row['entity_id'],
        'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
        'title' => $row['title'],
        'image_url' => $row['image_url'],
        'description' => $row['description'],
        'created_by' => (int) $row['created_by'],
        'creator_name' => $row['creator_name'] ?? null,
        'like_count' => isset($row['like_count']) ? (int) $row['like_count'] : 0,
        'comment_count' => isset($row['comment_count']) ? (int) $row['comment_count'] : 0,
        'is_liked' => isset($row['is_liked']) ? (bool) $row['is_liked'] : false,
        'can_edit' => isset($row['can_edit']) ? (bool) $row['can_edit'] : false,
        'can_delete' => isset($row['can_delete']) ? (bool) $row['can_delete'] : false,
        'created_at' => $row['created_at'],
    ];
}

function load_design_assets(PDO $db, array $user, string $entityType, int $entityId): array
{
    if (!in_array($entityType, design_asset_entity_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz tasarım varlığı.', 422, ['entity_type' => 'project veya idea olmalı.']);
    }

    if ($entityId < 1) {
        error_response('VALIDATION_ERROR', 'Geçersiz kayıt.', 422, ['entity_id' => 'Geçersiz değer.']);
    }

    if ($entityType === 'project') {
        ensure_project_access($db, $user, $entityId);
    } else {
        $ideaStmt = $db->prepare('SELECT id FROM ideas WHERE id = :id LIMIT 1');
        $ideaStmt->execute(['id' => $entityId]);
        if (!$ideaStmt->fetch()) {
            error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
        }
    }

    $stmt = $db->prepare('SELECT da.*,
                                 u.name AS creator_name,
                                 COALESCE(like_stats.like_count, 0) AS like_count,
                                 COALESCE(comment_stats.comment_count, 0) AS comment_count,
                                 CASE WHEN my_like.user_id IS NULL THEN 0 ELSE 1 END AS is_liked
                          FROM design_assets da
                          JOIN users u ON u.id = da.created_by
                          LEFT JOIN (
                              SELECT asset_id, COUNT(*) AS like_count
                              FROM design_asset_likes
                              GROUP BY asset_id
                          ) like_stats ON like_stats.asset_id = da.id
                          LEFT JOIN design_asset_likes my_like ON my_like.asset_id = da.id AND my_like.user_id = :viewer_id
                          LEFT JOIN (
                              SELECT entity_id, COUNT(*) AS comment_count
                              FROM comments
                              WHERE entity_type = "design_asset"
                              GROUP BY entity_id
                          ) comment_stats ON comment_stats.entity_id = da.id
                          WHERE da.entity_type = :entity_type AND da.entity_id = :entity_id
                          ORDER BY da.id DESC');
    $stmt->execute([
        'viewer_id' => (int) $user['id'],
        'entity_type' => $entityType,
        'entity_id' => $entityId,
    ]);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row) use ($user): array {
        $canEdit = ($user['role'] ?? '') === 'admin' || (int) $row['created_by'] === (int) $user['id'];
        $row['can_edit'] = $canEdit;
        $row['can_delete'] = $canEdit;
        return map_design_asset_row($row);
    }, $rows);
}

function ensure_design_asset_access(PDO $db, array $user, int $assetId): array
{
    $stmt = $db->prepare('SELECT * FROM design_assets WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $assetId]);
    $asset = $stmt->fetch();

    if (!$asset) {
        error_response('NOT_FOUND', 'Tasarım bulunamadı.', 404);
    }

    $entityType = (string) ($asset['entity_type'] ?? '');
    $entityId = (int) ($asset['entity_id'] ?? 0);

    if ($entityType === 'project') {
        ensure_project_access($db, $user, $entityId);
    } elseif ($entityType === 'idea') {
        $ideaStmt = $db->prepare('SELECT id FROM ideas WHERE id = :id LIMIT 1');
        $ideaStmt->execute(['id' => $entityId]);
        if (!$ideaStmt->fetch()) {
            error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
        }
    } else {
        error_response('VALIDATION_ERROR', 'Geçersiz tasarım varlığı.', 422);
    }

    return $asset;
}

function map_project_row(array $row): array
{
    $types = normalize_project_types($row['type'] ?? null);
    if (!$types) {
        $types = ['diger'];
    }

    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'type' => $types[0],
        'types' => $types,
        'client' => $row['client'],
        'status' => normalize_project_status((string) $row['status']),
        'priority' => $row['priority'],
        'due_date' => $row['due_date'],
        'tags' => decode_json($row['tags_json'], []),
        'mvp' => normalize_work_items(decode_json($row['mvp_json'] ?? '[]', [])),
        'feature_todos' => normalize_work_items(decode_json($row['todo_features_json'] ?? '[]', [])),
        'created_by' => (int) $row['created_by'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function map_stage_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'name' => $row['name'],
        'order_index' => (int) $row['order_index'],
        'status' => normalize_stage_status((string) $row['status']),
        'assignees' => decode_json($row['assignees_json'], []),
        'est_hours' => $row['est_hours'] !== null ? (float) $row['est_hours'] : null,
        'spent_hours' => $row['spent_hours'] !== null ? (float) $row['spent_hours'] : null,
        'links' => decode_json($row['links_json'], []),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function map_task_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'stage_id' => $row['stage_id'] !== null ? (int) $row['stage_id'] : null,
        'title' => $row['title'],
        'description' => $row['description'],
        'assignee_id' => $row['assignee_id'] !== null ? (int) $row['assignee_id'] : null,
        'status' => normalize_task_status((string) $row['status']),
        'priority' => $row['priority'],
        'due_date' => $row['due_date'],
        'order_index' => (int) $row['order_index'],
        'checklist' => decode_json($row['checklist_json'], []),
        'comment_count' => isset($row['comment_count']) ? (int) $row['comment_count'] : 0,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function idea_status_values(): array
{
    return ['idea', 'researching', 'planned'];
}

function idea_category_values(): array
{
    return [
        'web',
        'mobil',
        'urun_stratejisi',
        'e_ticaret',
        'crm_automasyon',
        'ai_ml',
        'ui_ux',
        'entegrasyon_api',
        'veri_raporlama',
        'performans',
        'guvenlik',
        'pazarlama_buyume',
        'musteri_deneyimi',
    ];
}

function map_idea_row(array $row): array
{
    $categories = normalize_idea_categories($row['category'] ?? null);

    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'category' => $categories[0] ?? null,
        'categories' => $categories,
        'tags' => decode_json($row['tags_json'], []),
        'impact' => (int) $row['impact'],
        'effort' => (int) $row['effort'],
        'status' => $row['status'],
        'mvp' => normalize_work_items(decode_json($row['mvp_json'] ?? '[]', [])),
        'feature_todos' => normalize_work_items(decode_json($row['todo_features_json'] ?? '[]', [])),
        'created_by' => (int) $row['created_by'],
        'creator_name' => $row['creator_name'] ?? null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function relevant_notification(array $notification, int $userId): bool
{
    $payload = decode_json($notification['payload_json'] ?? '{}', []);

    if (($notification['type'] ?? '') === 'task_assigned') {
        return (int) ($payload['assignee_id'] ?? 0) === $userId;
    }

    if (isset($payload['target_user_id'])) {
        return (int) $payload['target_user_id'] === $userId;
    }

    return false;
}

function project_ids_visible_to_user(PDO $db, array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        $rows = $db->query('SELECT id FROM projects')->fetchAll();
        return array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
    }

    $stmt = $db->prepare('SELECT project_id FROM project_members WHERE user_id = :user_id');
    $stmt->execute(['user_id' => (int) $user['id']]);
    $rows = $stmt->fetchAll();

    return array_values(array_unique(array_map(static fn(array $row): int => (int) $row['project_id'], $rows)));
}

function load_project_member_snapshots(PDO $db, array $projectIds): array
{
    $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
    if (!$projectIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $sql = 'SELECT pm.project_id, pm.user_id, pm.member_status, u.name, u.avatar_url
            FROM project_members pm
            JOIN users u ON u.id = pm.user_id
            WHERE pm.project_id IN (' . $placeholders . ')
            ORDER BY u.name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($projectIds);
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $projectId = (int) $row['project_id'];
        if (!isset($grouped[$projectId])) {
            $grouped[$projectId] = [];
        }

        $grouped[$projectId][] = [
            'id' => (int) $row['user_id'],
            'name' => $row['name'],
            'avatar_url' => $row['avatar_url'],
            'member_status' => normalize_project_status((string) ($row['member_status'] ?? 'baslaniyor')),
        ];
    }

    return $grouped;
}

$db = db();
start_secure_session();
$router = new Router();

$router->get('/health', static function (): void {
    success([
        'status' => 'ok',
        'time' => now_utc(),
    ]);
});

$router->get('/auth/csrf', static function (): void {
    success(['csrfToken' => csrf_token()]);
});

$router->get('/auth/me', static function () use ($db): void {
    $user = current_user($db);
    success([
        'user' => $user ? public_user($user) : null,
        'csrfToken' => csrf_token(),
    ]);
});

$router->post('/auth/login', static function () use ($db): void {
    $body = request_json_body();
    $usernameRaw = (string) ($body['username'] ?? '');
    $passwordRaw = (string) ($body['password'] ?? '');
    $username = normalize_username($usernameRaw);

    $errors = [];
    if ($username === '' || mb_strlen($username) < 3) {
        $errors['username'] = 'Kullanıcı adı en az 3 karakter olmalı.';
    }
    if ($passwordRaw === '' || mb_strlen($passwordRaw) < 6) {
        $errors['password'] = 'Parola en az 6 karakter olmalı.';
    }
    if ($errors) {
        error_response('VALIDATION_ERROR', 'Form doğrulama hatası.', 422, $errors);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    login_rate_limit_check($db, $ip);

    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($passwordRaw, $user['password_hash'])) {
        login_rate_limit_fail($db, $ip);
        error_response('AUTH_FAILED', 'Kullanıcı adı veya parola hatalı.', 401);
    }

    login_rate_limit_success($db, $ip);
    login_user($user);

    success([
        'user' => public_user($user),
        'csrfToken' => csrf_token(),
    ]);
});

$router->post('/auth/register', static function () use ($db): void {
    error_response('REGISTER_DISABLED', 'Açık kayıt kapalı. Kullanıcıları sadece admin Ayarlar sayfasından oluşturabilir.', 403);
});

$router->post('/auth/logout', static function () use ($db): void {
    require_auth($db);
    require_csrf();
    logout_user();
    success(['logged_out' => true]);
});

$router->get('/users', static function () use ($db): void {
    require_auth($db);
    $rows = $db->query('SELECT id, name, username, title, email, role, avatar_url, created_at FROM users ORDER BY name ASC')->fetchAll();

    $users = array_map(static fn(array $row): array => public_user($row), $rows);
    success(['users' => $users]);
});

$router->patch('/users/me', static function () use ($db): void {
    $user = require_auth($db, false);
    require_csrf();

    $body = request_json_body();
    $userId = (int) $user['id'];
    $fields = [];
    $params = ['id' => $userId];

    if (array_key_exists('name', $body)) {
        $name = clean_text((string) $body['name'], 120);
        if ($name === null || mb_strlen($name) < 2) {
            error_response('VALIDATION_ERROR', 'Geçersiz ad bilgisi.', 422, [
                'name' => 'Ad en az 2 karakter olmalı.',
            ]);
        }
        $fields[] = 'name = :name';
        $params['name'] = $name;
    }

    if (array_key_exists('username', $body)) {
        $username = normalize_username((string) $body['username']);
        validate_username_or_error($username);
        ensure_username_available($db, $username, $userId);
        $fields[] = 'username = :username';
        $params['username'] = $username;
    }

    if (array_key_exists('title', $body)) {
        $fields[] = 'title = :title';
        $params['title'] = clean_text((string) $body['title'], 120);
    }

    if (array_key_exists('avatar_url', $body)) {
        $fields[] = 'avatar_url = :avatar_url';
        $params['avatar_url'] = clean_text((string) $body['avatar_url'], 500);
    }

    if (array_key_exists('password', $body)) {
        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                error_response('VALIDATION_ERROR', 'Parola çok kısa.', 422, [
                    'password' => 'Parola en az 8 karakter olmalı.',
                ]);
            }

            $currentPassword = (string) ($body['current_password'] ?? '');
            if ($currentPassword === '') {
                error_response('VALIDATION_ERROR', 'Mevcut parola gerekli.', 422, [
                    'current_password' => 'Parola değişikliği için mevcut parolanızı girin.',
                ]);
            }

            $passwordStmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $passwordStmt->execute(['id' => $userId]);
            $row = $passwordStmt->fetch();
            if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
                error_response('AUTH_FAILED', 'Mevcut parola doğrulanamadı.', 422, [
                    'current_password' => 'Mevcut parola hatalı.',
                ]);
            }

            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    if (!$fields) {
        error_response('VALIDATION_ERROR', 'Güncellenecek alan bulunamadı.', 422);
    }

    $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
    $stmt->execute($params);

    $reload = $db->prepare('SELECT id, name, username, title, email, role, avatar_url, created_at FROM users WHERE id = :id LIMIT 1');
    $reload->execute(['id' => $userId]);
    $updated = $reload->fetch();
    if (!$updated) {
        error_response('NOT_FOUND', 'Kullanıcı bulunamadı.', 404);
    }

    audit_log($db, $userId, 'update', 'user', $userId, null, ['self_update' => true]);
    success(['user' => public_user($updated)]);
});

$router->post('/users', static function () use ($db): void {
    $user = require_auth($db, false);
    require_admin($user);
    require_csrf();

    $body = request_json_body();
    require_fields($body, [
        'name' => ['required' => true, 'min' => 2, 'max' => 120],
        'username' => ['required' => true, 'min' => 3, 'max' => 80],
        'password' => ['required' => true, 'min' => 8, 'max' => 120],
    ]);

    $name = clean_text((string) $body['name'], 120);
    if ($name === null || mb_strlen($name) < 2) {
        error_response('VALIDATION_ERROR', 'Geçersiz ad bilgisi.', 422, [
            'name' => 'Ad en az 2 karakter olmalı.',
        ]);
    }
    $username = normalize_username((string) $body['username']);
    validate_username_or_error($username);
    ensure_username_available($db, $username);

    $password = (string) $body['password'];
    if (mb_strlen($password) < 8) {
        error_response('VALIDATION_ERROR', 'Parola çok kısa.', 422, [
            'password' => 'Parola en az 8 karakter olmalı.',
        ]);
    }

    $role = clean_text((string) ($body['role'] ?? 'member'), 20) ?? 'member';
    if (!in_array($role, ['admin', 'member'], true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz rol.', 422, [
            'role' => 'Rol admin veya member olabilir.',
        ]);
    }

    $email = build_internal_email($db, $username);
    $now = now_utc();

    $insert = $db->prepare('INSERT INTO users(name, username, title, email, password_hash, role, avatar_url, created_at) VALUES(:name, :username, :title, :email, :password_hash, :role, :avatar_url, :created_at)');
    $insert->execute([
        'name' => $name,
        'username' => $username,
        'title' => clean_text((string) ($body['title'] ?? ''), 120),
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'avatar_url' => clean_text((string) ($body['avatar_url'] ?? ''), 500),
        'created_at' => $now,
    ]);

    $createdId = (int) $db->lastInsertId();
    $reload = $db->prepare('SELECT id, name, username, title, email, role, avatar_url, created_at FROM users WHERE id = :id LIMIT 1');
    $reload->execute(['id' => $createdId]);
    $createdUser = $reload->fetch();
    if (!$createdUser) {
        error_response('USER_CREATE_FAILED', 'Kullanıcı oluşturulamadı.', 500);
    }

    audit_log($db, (int) $user['id'], 'create', 'user', $createdId, null, [
        'username' => $username,
        'role' => $role,
    ]);

    $recipientIds = all_user_ids($db);
    publish_event($db, 'member_joined', [
        'user_id' => $createdId,
        'name' => $createdUser['name'],
        'actor_id' => (int) $user['id'],
        'created_at' => $now,
    ], $recipientIds);

    success(['user' => public_user($createdUser)], 201);
});

$router->patch('/users/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db, false);
    require_admin($user);
    require_csrf();

    $targetUserId = (int) $params['id'];
    $body = request_json_body();
    $fields = [];
    $bind = ['id' => $targetUserId];

    $targetStmt = $db->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
    $targetStmt->execute(['id' => $targetUserId]);
    $targetUser = $targetStmt->fetch();
    if (!$targetUser) {
        error_response('NOT_FOUND', 'Kullanıcı bulunamadı.', 404);
    }

    if (array_key_exists('name', $body)) {
        $name = clean_text((string) $body['name'], 120);
        if ($name === null || mb_strlen($name) < 2) {
            error_response('VALIDATION_ERROR', 'Geçersiz ad bilgisi.', 422, [
                'name' => 'Ad en az 2 karakter olmalı.',
            ]);
        }
        $fields[] = 'name = :name';
        $bind['name'] = $name;
    }

    if (array_key_exists('username', $body)) {
        $username = normalize_username((string) $body['username']);
        validate_username_or_error($username);
        ensure_username_available($db, $username, $targetUserId);
        $fields[] = 'username = :username';
        $bind['username'] = $username;
    }

    if (array_key_exists('title', $body)) {
        $fields[] = 'title = :title';
        $bind['title'] = clean_text((string) $body['title'], 120);
    }

    if (array_key_exists('avatar_url', $body)) {
        $fields[] = 'avatar_url = :avatar_url';
        $bind['avatar_url'] = clean_text((string) $body['avatar_url'], 500);
    }

    if (array_key_exists('role', $body)) {
        $role = clean_text((string) $body['role'], 20);
        if ($role === null || !in_array($role, ['admin', 'member'], true)) {
            error_response('VALIDATION_ERROR', 'Geçersiz rol.', 422, [
                'role' => 'Rol admin veya member olabilir.',
            ]);
        }

        if ((int) $targetUser['id'] === (int) $user['id'] && $role !== 'admin') {
            error_response('VALIDATION_ERROR', 'Kendi admin rolünüzü kaldıramazsınız.', 422, [
                'role' => 'Kendi admin rolünüzü kaldıramazsınız.',
            ]);
        }

        $fields[] = 'role = :role';
        $bind['role'] = $role;
    }

    if (array_key_exists('password', $body)) {
        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                error_response('VALIDATION_ERROR', 'Parola çok kısa.', 422, [
                    'password' => 'Parola en az 8 karakter olmalı.',
                ]);
            }
            $fields[] = 'password_hash = :password_hash';
            $bind['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    if (!$fields) {
        error_response('VALIDATION_ERROR', 'Güncellenecek alan bulunamadı.', 422);
    }

    $update = $db->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
    $update->execute($bind);

    $reload = $db->prepare('SELECT id, name, username, title, email, role, avatar_url, created_at FROM users WHERE id = :id LIMIT 1');
    $reload->execute(['id' => $targetUserId]);
    $updated = $reload->fetch();
    if (!$updated) {
        error_response('NOT_FOUND', 'Kullanıcı bulunamadı.', 404);
    }

    audit_log($db, (int) $user['id'], 'update', 'user', $targetUserId, null, [
        'fields' => array_values(array_map(static fn(string $field): string => explode(' = ', $field)[0], $fields)),
    ]);

    success(['user' => public_user($updated)]);
});

$router->get('/invites', static function () use ($db): void {
    $user = require_auth($db);
    require_admin($user);

    $stmt = $db->query('SELECT i.*, u.name AS creator_name FROM invites i JOIN users u ON u.id = i.created_by ORDER BY i.id DESC');
    success(['invites' => $stmt->fetchAll()]);
});

$router->post('/invites', static function () use ($db): void {
    $user = require_auth($db);
    require_admin($user);
    require_csrf();

    $body = request_json_body();
    $days = max(1, min(60, (int) ($body['expires_in_days'] ?? 7)));

    $token = bin2hex(random_bytes(18));
    $now = now_utc();
    $expiresAt = add_days($now, $days);

    $stmt = $db->prepare('INSERT INTO invites(token, created_by, expires_at, used_by, used_at, created_at) VALUES(:token, :created_by, :expires_at, NULL, NULL, :created_at)');
    $stmt->execute([
        'token' => $token,
        'created_by' => (int) $user['id'],
        'expires_at' => $expiresAt,
        'created_at' => $now,
    ]);

    success([
        'invite' => [
            'id' => (int) $db->lastInsertId(),
            'token' => $token,
            'expires_at' => $expiresAt,
            'register_url' => '/register?invite=' . urlencode($token),
        ],
    ], 201);
});

$router->get('/stage-templates', static function () use ($db): void {
    require_auth($db);

    $rows = $db->query('SELECT id, name, order_index FROM stage_templates ORDER BY order_index ASC')->fetchAll();
    $stages = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'order_index' => (int) $row['order_index'],
    ], $rows);

    success(['stages' => $stages]);
});

$router->post('/stage-templates', static function () use ($db): void {
    $user = require_auth($db);
    require_admin($user);
    require_csrf();

    $body = request_json_body();
    $stages = $body['stages'] ?? [];
    if (!is_array($stages) || count($stages) < 1) {
        error_response('VALIDATION_ERROR', 'En az bir aşama adı gerekli.', 422, ['stages' => 'Liste boş olamaz.']);
    }

    $names = [];
    foreach ($stages as $item) {
        $name = clean_text((string) $item, 120);
        if ($name !== null) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));
    if (count($names) < 1) {
        error_response('VALIDATION_ERROR', 'En az bir geçerli aşama adı gerekli.', 422, ['stages' => 'Geçerli değer girin.']);
    }

    $db->beginTransaction();
    $db->exec('DELETE FROM stage_templates');

    $insert = $db->prepare('INSERT INTO stage_templates(name, order_index, created_at) VALUES(:name, :order_index, :created_at)');
    foreach ($names as $index => $name) {
        $insert->execute([
            'name' => $name,
            'order_index' => $index,
            'created_at' => now_utc(),
        ]);
    }

    $db->commit();

    success(['stages' => $names]);
});

$router->get('/dashboard/summary', static function () use ($db): void {
    $user = require_auth($db);
    $userId = (int) $user['id'];
    $activityLimit = max(20, min(200, (int) ($_GET['activity_limit'] ?? 40)));

    $visibleProjectIds = project_ids_visible_to_user($db, $user);
    if (!$visibleProjectIds) {
        $unreadStmt = $db->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $unreadStmt->execute(['user_id' => $userId]);
        $unreadCount = (int) $unreadStmt->fetch()['c'];

        success([
            'active_projects' => [],
            'assigned_tasks' => [],
            'recent_activity' => [],
            'team_pulse' => [],
            'unread_notifications' => $unreadCount,
        ]);
    }

    $projectPlaceholders = implode(',', array_fill(0, count($visibleProjectIds), '?'));

    $projectsSql = 'SELECT p.*,
                        (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status NOT IN ("tamamlandi", "done")) AS open_task_count
                    FROM projects p
                    WHERE id IN (' . $projectPlaceholders . ')
                    AND status NOT IN (?, ?, ?, ?)
                    ORDER BY updated_at DESC
                    LIMIT 8';
    $projectsStmt = $db->prepare($projectsSql);
    $projectsStmt->execute(array_merge($visibleProjectIds, ['tamamlandi', 'done', 'iptal_talebi', 'iptal_edildi']));
    $projectRows = $projectsStmt->fetchAll();
    $activeProjects = array_map(static function (array $row): array {
        $project = map_project_row($row);
        $project['open_task_count'] = (int) ($row['open_task_count'] ?? 0);
        return $project;
    }, $projectRows);

    $projectMemberSnapshots = load_project_member_snapshots(
        $db,
        array_map(static fn(array $project): int => (int) $project['id'], $activeProjects)
    );
    foreach ($activeProjects as &$project) {
        $project['members'] = $projectMemberSnapshots[$project['id']] ?? [];
    }
    unset($project);

    $tasksSql = 'SELECT t.*, s.name AS stage_name
                 FROM tasks t
                 LEFT JOIN stages s ON s.id = t.stage_id
                 WHERE t.assignee_id = ?
                 AND t.project_id IN (' . $projectPlaceholders . ')
                 AND t.status NOT IN (?, ?)
                 ORDER BY t.due_date IS NULL, t.due_date ASC, t.updated_at DESC
                 LIMIT 12';
    $tasksStmt = $db->prepare($tasksSql);
    $tasksStmt->execute(array_merge([$userId], $visibleProjectIds, ['tamamlandi', 'done']));

    $assignedTasks = array_map(static function (array $row): array {
        $task = map_task_row($row);
        $task['stage_name'] = $row['stage_name'] ?? null;
        return $task;
    }, $tasksStmt->fetchAll());

    if (($user['role'] ?? '') === 'admin') {
        $activityStmt = $db->query('SELECT a.*, u.name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT ' . $activityLimit);
        $activityRows = $activityStmt->fetchAll();
    } else {
        $activitySql = 'SELECT a.*, u.name AS actor_name
                        FROM audit_logs a
                        JOIN users u ON u.id = a.actor_id
                        WHERE a.project_id IS NULL OR a.project_id IN (' . $projectPlaceholders . ')
                        ORDER BY a.created_at DESC
                        LIMIT ' . $activityLimit;
        $activityStmt = $db->prepare($activitySql);
        $activityStmt->execute($visibleProjectIds);
        $activityRows = $activityStmt->fetchAll();
    }

    $activities = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'actor_id' => (int) $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'meta' => decode_json($row['meta_json'], []),
            'created_at' => $row['created_at'],
        ];
    }, $activityRows);

    $teamCountSql = 'SELECT t.assignee_id AS user_id,
                            SUM(CASE WHEN t.status NOT IN ("tamamlandi", "done") THEN 1 ELSE 0 END) AS open_task_count,
                            SUM(CASE WHEN t.status IN ("gelistiriliyor", "test_ediliyor", "tasarlaniyor", "baslaniyor") THEN 1 ELSE 0 END) AS active_task_count
                     FROM tasks t
                     WHERE t.assignee_id IS NOT NULL
                     AND t.project_id IN (' . $projectPlaceholders . ')
                     GROUP BY t.assignee_id';
    $teamCountStmt = $db->prepare($teamCountSql);
    $teamCountStmt->execute($visibleProjectIds);
    $teamCountRows = $teamCountStmt->fetchAll();

    $taskCountByUser = [];
    foreach ($teamCountRows as $row) {
        $taskCountByUser[(int) $row['user_id']] = [
            'open_task_count' => (int) $row['open_task_count'],
            'active_task_count' => (int) $row['active_task_count'],
        ];
    }

    $teamUsersSql = 'SELECT DISTINCT u.id, u.name, u.username, u.title, u.role, u.avatar_url
                     FROM users u
                     JOIN project_members pm ON pm.user_id = u.id
                     WHERE pm.project_id IN (' . $projectPlaceholders . ')';
    $teamUsersStmt = $db->prepare($teamUsersSql);
    $teamUsersStmt->execute($visibleProjectIds);
    $teamRows = $teamUsersStmt->fetchAll();

    $teamRows = array_map(static function (array $row) use ($taskCountByUser): array {
        $uid = (int) $row['id'];
        return [
            'id' => $uid,
            'name' => $row['name'],
            'username' => $row['username'] ?? null,
            'title' => $row['title'] ?? null,
            'role' => $row['role'],
            'avatar_url' => $row['avatar_url'],
            'open_task_count' => $taskCountByUser[$uid]['open_task_count'] ?? 0,
            'active_task_count' => $taskCountByUser[$uid]['active_task_count'] ?? 0,
        ];
    }, $teamRows);

    usort($teamRows, static function (array $a, array $b): int {
        if ($a['active_task_count'] !== $b['active_task_count']) {
            return $b['active_task_count'] <=> $a['active_task_count'];
        }
        if ($a['open_task_count'] !== $b['open_task_count']) {
            return $b['open_task_count'] <=> $a['open_task_count'];
        }
        return strcmp((string) $a['name'], (string) $b['name']);
    });
    $teamRows = array_slice($teamRows, 0, 12);

    $memberSql = 'SELECT pm.user_id, pm.project_id, pm.member_status, p.title AS project_title
                  FROM project_members pm
                  JOIN projects p ON p.id = pm.project_id
                  WHERE pm.project_id IN (' . $projectPlaceholders . ')
                  ORDER BY p.updated_at DESC, p.title ASC';
    $memberStmt = $db->prepare($memberSql);
    $memberStmt->execute($visibleProjectIds);
    $memberRows = $memberStmt->fetchAll();

    $membersByUser = [];
    foreach ($memberRows as $row) {
        $uid = (int) $row['user_id'];
        if (!isset($membersByUser[$uid])) {
            $membersByUser[$uid] = [];
        }
        if (count($membersByUser[$uid]) >= 6) {
            continue;
        }
        $membersByUser[$uid][] = [
            'project_id' => (int) $row['project_id'],
            'project_title' => $row['project_title'],
            'member_status' => normalize_project_status((string) ($row['member_status'] ?? 'baslaniyor')),
        ];
    }

    $lastActionByUser = [];
    $recentActionsByUser = [];
    foreach ($activities as $activity) {
        $actorId = (int) $activity['actor_id'];
        if (!isset($lastActionByUser[$actorId])) {
            $lastActionByUser[$actorId] = $activity;
        }
        if (!isset($recentActionsByUser[$actorId])) {
            $recentActionsByUser[$actorId] = [];
        }
        if (count($recentActionsByUser[$actorId]) < 3) {
            $recentActionsByUser[$actorId][] = [
                'action' => $activity['action'],
                'entity_type' => $activity['entity_type'],
                'entity_id' => $activity['entity_id'],
                'project_id' => $activity['project_id'],
                'created_at' => $activity['created_at'],
                'meta' => $activity['meta'],
            ];
        }
    }

    $teamPulse = array_map(static function (array $row) use ($membersByUser, $lastActionByUser, $recentActionsByUser): array {
        $userIdForRow = (int) $row['id'];
        return [
            'id' => $userIdForRow,
            'name' => $row['name'],
            'role' => $row['role'],
            'avatar_url' => $row['avatar_url'],
            'open_task_count' => (int) $row['open_task_count'],
            'active_task_count' => (int) $row['active_task_count'],
            'last_action' => $lastActionByUser[$userIdForRow]['action'] ?? null,
            'last_action_at' => $lastActionByUser[$userIdForRow]['created_at'] ?? null,
            'last_entity' => isset($lastActionByUser[$userIdForRow]) ? ($lastActionByUser[$userIdForRow]['entity_type'] . '#' . $lastActionByUser[$userIdForRow]['entity_id']) : null,
            'recent_actions' => $recentActionsByUser[$userIdForRow] ?? [],
            'project_states' => $membersByUser[$userIdForRow] ?? [],
        ];
    }, $teamRows);

    $unreadStmt = $db->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $unreadStmt->execute(['user_id' => $userId]);
    $unreadCount = (int) $unreadStmt->fetch()['c'];

    success([
        'active_projects' => $activeProjects,
        'assigned_tasks' => $assignedTasks,
        'recent_activity' => $activities,
        'team_pulse' => $teamPulse,
        'unread_notifications' => $unreadCount,
    ]);
});

$router->get('/projects', static function () use ($db): void {
    $user = require_auth($db);

    $conditions = [];
    $params = [];

    if ($user['role'] !== 'admin') {
        $conditions[] = 'EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = p.id AND pm.user_id = :viewer_id)';
        $params['viewer_id'] = (int) $user['id'];
    }

    $query = $_GET['q'] ?? null;
    if (is_string($query) && trim($query) !== '') {
        $conditions[] = '(p.title LIKE :q OR p.description LIKE :q OR p.client LIKE :q)';
        $params['q'] = '%' . trim($query) . '%';
    }

    $rawTypeFilters = $_GET['types'] ?? ($_GET['type'] ?? null);
    $typeFilters = normalize_project_types($rawTypeFilters);
    if (normalize_string_list($rawTypeFilters) && !$typeFilters) {
        error_response('VALIDATION_ERROR', 'Geçersiz proje türü filtresi.', 422, ['types' => 'Geçersiz değer.']);
    }
    if ($typeFilters) {
        $typeCondition = build_multi_value_filter_sql('p.type', $typeFilters, 'project_type', $params);
        if ($typeCondition !== null) {
            $conditions[] = $typeCondition;
        }
    }

    if (!empty($_GET['status'])) {
        $conditions[] = 'p.status = :status';
        $params['status'] = normalize_project_status((string) $_GET['status']);
    }

    if (!empty($_GET['tag'])) {
        $conditions[] = 'p.tags_json LIKE :tag';
        $params['tag'] = '%"' . trim((string) $_GET['tag']) . '"%';
    }

    if (!empty($_GET['assignee'])) {
        $conditions[] = 'EXISTS (SELECT 1 FROM tasks t WHERE t.project_id = p.id AND t.assignee_id = :assignee)';
        $params['assignee'] = (int) $_GET['assignee'];
    }

    $sort = (string) ($_GET['sort'] ?? 'updated_desc');
    $orderBy = match ($sort) {
        'created_desc' => 'p.created_at DESC',
        'created_asc' => 'p.created_at ASC',
        'title_asc' => 'p.title ASC',
        'due_asc' => 'p.due_date IS NULL, p.due_date ASC',
        default => 'p.updated_at DESC',
    };

    $sql = 'SELECT p.*, u.name AS created_by_name,
                COALESCE(task_stats.task_count, 0) AS task_count,
                COALESCE(task_stats.open_task_count, 0) AS open_task_count
            FROM projects p
            JOIN users u ON u.id = p.created_by
            LEFT JOIN (
                SELECT project_id,
                       COUNT(*) AS task_count,
                       SUM(CASE WHEN status NOT IN ("tamamlandi", "done") THEN 1 ELSE 0 END) AS open_task_count
                FROM tasks
                GROUP BY project_id
            ) task_stats ON task_stats.project_id = p.id';

    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY ' . $orderBy;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $projects = array_map(static function (array $row): array {
        $project = map_project_row($row);
        $project['created_by_name'] = $row['created_by_name'];
        $project['task_count'] = (int) $row['task_count'];
        $project['open_task_count'] = (int) $row['open_task_count'];
        return $project;
    }, $rows);

    $snapshots = load_project_member_snapshots(
        $db,
        array_map(static fn(array $project): int => (int) $project['id'], $projects)
    );
    foreach ($projects as &$project) {
        $project['members'] = $snapshots[$project['id']] ?? [];
    }
    unset($project);

    success(['projects' => $projects]);
});

$router->post('/projects', static function () use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $body = request_json_body();
    require_fields($body, [
        'title' => ['required' => true, 'min' => 2, 'max' => 180],
    ]);

    $title = clean_text((string) $body['title'], 180);
    $description = clean_text((string) ($body['description'] ?? ''), 5000);
    $typesInputProvided = array_key_exists('types', $body) || array_key_exists('type', $body);
    $types = normalize_project_types($body['types'] ?? ($body['type'] ?? ['web']));
    if ($typesInputProvided && !$types) {
        error_response('VALIDATION_ERROR', 'Geçersiz proje türü.', 422, ['types' => 'En az bir geçerli tür seçin.']);
    }
    if (!$types) {
        $types = ['web'];
    }
    $typeStorage = encode_multi_value_field($types) ?? 'web';
    $client = clean_text((string) ($body['client'] ?? ''), 180);
    $status = normalize_project_status(clean_text((string) ($body['status'] ?? 'baslaniyor'), 32) ?? 'baslaniyor');
    $priority = clean_text((string) ($body['priority'] ?? 'med'), 20) ?? 'med';
    $dueDate = clean_text((string) ($body['due_date'] ?? ''), 20);
    $tags = normalize_tags($body['tags'] ?? []);
    $mvpItems = normalize_work_items($body['mvp'] ?? ($body['mvp_items'] ?? []));
    $featureTodos = normalize_work_items($body['feature_todos'] ?? ($body['todo_items'] ?? []));

    if (!in_array($status, project_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz proje durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    if (!in_array($priority, ['low', 'med', 'high'], true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz öncelik.', 422, ['priority' => 'Geçersiz değer.']);
    }

    $now = now_utc();

    $stmt = $db->prepare('INSERT INTO projects(title, description, type, client, status, priority, due_date, tags_json, mvp_json, todo_features_json, created_by, created_at, updated_at) VALUES(:title, :description, :type, :client, :status, :priority, :due_date, :tags_json, :mvp_json, :todo_features_json, :created_by, :created_at, :updated_at)');
    $stmt->execute([
        'title' => $title,
        'description' => $description,
        'type' => $typeStorage,
        'client' => $client,
        'status' => $status,
        'priority' => $priority,
        'due_date' => $dueDate,
        'tags_json' => encode_json($tags),
        'mvp_json' => encode_json($mvpItems),
        'todo_features_json' => encode_json($featureTodos),
        'created_by' => (int) $user['id'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $projectId = (int) $db->lastInsertId();

    $memberIds = array_map('intval', (array) ($body['member_ids'] ?? []));
    $memberIds[] = (int) $user['id'];
    $memberDefaultStatus = normalize_project_status(clean_text((string) ($body['member_default_status'] ?? 'baslaniyor'), 32) ?? 'baslaniyor');
    add_project_members($db, $projectId, $memberIds, $memberDefaultStatus);

    create_project_stages_from_templates($db, $projectId);

    audit_log($db, (int) $user['id'], 'create', 'project', $projectId, $projectId, [
        'title' => $title,
        'status' => $status,
    ]);

    $recipientIds = all_user_ids($db);
    publish_event($db, 'project_created', [
        'project_id' => $projectId,
        'title' => $title,
        'status' => $status,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => $now,
    ], $recipientIds);

    $projectStmt = $db->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $projectStmt->execute(['id' => $projectId]);
    $project = map_project_row($projectStmt->fetch());

    success([
        'project' => $project,
        'members' => load_project_members($db, $projectId),
    ], 201);
});

$router->get('/projects/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    $projectId = (int) $params['id'];

    $projectRow = ensure_project_access($db, $user, $projectId);
    $project = map_project_row($projectRow);

    $availableIncludes = ['members', 'stages', 'tasks', 'activity'];
    $includeRaw = trim((string) ($_GET['include'] ?? ''));
    if ($includeRaw === '') {
        $includes = $availableIncludes;
    } else {
        $parts = array_map('trim', explode(',', $includeRaw));
        $parts = array_filter($parts, static fn(string $part): bool => $part !== '');
        $includes = array_values(array_intersect($availableIncludes, $parts));
    }

    $includeSet = array_flip($includes);

    $members = [];
    if (isset($includeSet['members'])) {
        $members = load_project_members($db, $projectId);
    }

    $stages = [];
    if (isset($includeSet['stages'])) {
        $stagesStmt = $db->prepare('SELECT * FROM stages WHERE project_id = :project_id ORDER BY order_index ASC, id ASC');
        $stagesStmt->execute(['project_id' => $projectId]);
        $stages = array_map(static fn(array $row): array => map_stage_row($row), $stagesStmt->fetchAll());
    }

    $tasks = [];
    if (isset($includeSet['tasks'])) {
        $taskLimit = max(1, min(500, (int) ($_GET['task_limit'] ?? 500)));
        $tasksStmt = $db->prepare('SELECT t.*, s.order_index AS stage_order_index, COALESCE(task_comments.comment_count, 0) AS comment_count
                                   FROM tasks t
                                   LEFT JOIN stages s ON s.id = t.stage_id
                                   LEFT JOIN (
                                       SELECT entity_id, COUNT(*) AS comment_count
                                       FROM comments
                                       WHERE entity_type = "task"
                                       GROUP BY entity_id
                                   ) task_comments ON task_comments.entity_id = t.id
                                   WHERE t.project_id = :project_id
                                   ORDER BY CASE WHEN t.stage_id IS NULL THEN 9999 ELSE COALESCE(s.order_index, 9998) END ASC,
                                            t.order_index ASC,
                                            t.id ASC
                                   LIMIT ' . $taskLimit);
        $tasksStmt->execute(['project_id' => $projectId]);
        $tasks = array_map(static fn(array $row): array => map_task_row($row), $tasksStmt->fetchAll());
    }

    $activity = [];
    if (isset($includeSet['activity'])) {
        $activityLimit = max(1, min(200, (int) ($_GET['activity_limit'] ?? 40)));
        $activityStmt = $db->prepare('SELECT a.*, u.name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_id WHERE a.project_id = :project_id ORDER BY a.created_at DESC LIMIT ' . $activityLimit);
        $activityStmt->execute(['project_id' => $projectId]);
        $activity = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'actor_id' => (int) $row['actor_id'],
                'actor_name' => $row['actor_name'],
                'action' => $row['action'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
                'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
                'meta' => decode_json($row['meta_json'], []),
                'created_at' => $row['created_at'],
            ];
        }, $activityStmt->fetchAll());
    }

    success([
        'project' => $project,
        'members' => $members,
        'stages' => $stages,
        'tasks' => $tasks,
        'activity' => $activity,
    ]);
});

$router->post('/projects/{id}/members', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    $project = ensure_project_access($db, $user, $projectId);
    ensure_project_manage_access($user, $project);

    $body = request_json_body();
    $memberIds = array_values(array_unique(array_filter(array_map('intval', (array) ($body['member_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
    if (!$memberIds) {
        error_response('VALIDATION_ERROR', 'Eklenecek en az bir ekip üyesi seçmelisiniz.', 422, ['member_ids' => 'Zorunlu alan.']);
    }

    $memberStatus = normalize_project_status(clean_text((string) ($body['member_status'] ?? 'baslaniyor'), 32) ?? 'baslaniyor');
    if (!in_array($memberStatus, member_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz ekip üyesi durumu.', 422, ['member_status' => 'Geçersiz değer.']);
    }

    $userCheck = $db->prepare('SELECT id, name FROM users WHERE id = :id LIMIT 1');
    $memberNames = [];
    foreach ($memberIds as $memberId) {
        $userCheck->execute(['id' => $memberId]);
        $userRow = $userCheck->fetch();
        if (!$userRow) {
            error_response('VALIDATION_ERROR', 'Seçilen ekip üyesi bulunamadı.', 422, ['member_ids' => "Kullanıcı bulunamadı: {$memberId}"]);
        }
        $memberNames[] = (string) $userRow['name'];
    }

    add_project_members($db, $projectId, $memberIds, $memberStatus);

    audit_log($db, (int) $user['id'], 'assign', 'project_member', $projectId, $projectId, [
        'project_title' => $project['title'],
        'member_ids' => $memberIds,
        'member_names' => array_values(array_unique($memberNames)),
        'member_status' => $memberStatus,
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'project_member_updated', [
        'project_id' => $projectId,
        'project_title' => $project['title'],
        'member_ids' => $memberIds,
        'member_names' => array_values(array_unique($memberNames)),
        'member_status' => $memberStatus,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success([
        'members' => load_project_members($db, $projectId),
    ], 201);
});

$router->patch('/projects/{id}/members/{userId}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    $targetUserId = (int) $params['userId'];
    $project = ensure_project_access($db, $user, $projectId);
    ensure_project_manage_access($user, $project);

    $body = request_json_body();
    $memberStatus = normalize_project_status(clean_text((string) ($body['member_status'] ?? ''), 32) ?? '');
    if ($memberStatus === '' || !in_array($memberStatus, member_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz ekip üyesi durumu.', 422, ['member_status' => 'Geçersiz değer.']);
    }

    $memberExistsStmt = $db->prepare('SELECT u.name FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = :project_id AND pm.user_id = :user_id LIMIT 1');
    $memberExistsStmt->execute([
        'project_id' => $projectId,
        'user_id' => $targetUserId,
    ]);
    $memberRow = $memberExistsStmt->fetch();
    if (!$memberRow) {
        error_response('NOT_FOUND', 'Bu kullanıcı projeye dahil değil.', 404);
    }
    $targetMemberName = (string) ($memberRow['name'] ?? '');

    $updateStmt = $db->prepare('UPDATE project_members SET member_status = :member_status WHERE project_id = :project_id AND user_id = :user_id');
    $updateStmt->execute([
        'member_status' => $memberStatus,
        'project_id' => $projectId,
        'user_id' => $targetUserId,
    ]);

    audit_log($db, (int) $user['id'], 'update', 'project_member', $targetUserId, $projectId, [
        'project_title' => $project['title'],
        'member_name' => $targetMemberName,
        'member_status' => $memberStatus,
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'project_member_updated', [
        'project_id' => $projectId,
        'project_title' => $project['title'],
        'target_user_id' => $targetUserId,
        'member_name' => $targetMemberName,
        'member_status' => $memberStatus,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success([
        'members' => load_project_members($db, $projectId),
    ]);
});

$router->delete('/projects/{id}/members/{userId}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    $targetUserId = (int) $params['userId'];
    $project = ensure_project_access($db, $user, $projectId);
    ensure_project_manage_access($user, $project);

    if ($targetUserId === (int) $project['created_by']) {
        error_response('VALIDATION_ERROR', 'Proje sahibi ekipten çıkarılamaz.', 422, ['user_id' => 'Proje sahibi çıkarılamaz.']);
    }

    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM project_members WHERE project_id = :project_id');
    $countStmt->execute(['project_id' => $projectId]);
    $memberCount = (int) $countStmt->fetch()['c'];

    if ($memberCount <= 1) {
        error_response('VALIDATION_ERROR', 'Projede en az bir ekip üyesi kalmalıdır.', 422, ['user_id' => 'Son kullanıcı çıkarılamaz.']);
    }

    $targetMemberName = user_names_for_ids($db, [$targetUserId])[0] ?? null;

    $deleteStmt = $db->prepare('DELETE FROM project_members WHERE project_id = :project_id AND user_id = :user_id');
    $deleteStmt->execute([
        'project_id' => $projectId,
        'user_id' => $targetUserId,
    ]);

    if ($deleteStmt->rowCount() < 1) {
        error_response('NOT_FOUND', 'Bu kullanıcı projeye dahil değil.', 404);
    }

    audit_log($db, (int) $user['id'], 'unassign', 'project_member', $targetUserId, $projectId, [
        'project_title' => $project['title'],
        'member_name' => $targetMemberName,
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'project_member_updated', [
        'project_id' => $projectId,
        'project_title' => $project['title'],
        'target_user_id' => $targetUserId,
        'member_name' => $targetMemberName,
        'removed' => true,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success([
        'members' => load_project_members($db, $projectId),
    ]);
});

$router->patch('/projects/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    $project = ensure_project_access($db, $user, $projectId);
    ensure_project_manage_access($user, $project);
    $body = request_json_body();

    $fields = [];
    $bind = ['id' => $projectId];
    $allowed = ['title', 'description', 'client', 'status', 'priority', 'due_date'];

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }

        if ($key === 'due_date') {
            $fields[] = 'due_date = :due_date';
            $bind['due_date'] = clean_text((string) ($body['due_date'] ?? ''), 20);
            continue;
        }

        if ($key === 'status') {
            $normalizedStatus = normalize_project_status((string) $body[$key]);
            if (!in_array($normalizedStatus, project_status_values(), true)) {
                error_response('VALIDATION_ERROR', 'Geçersiz proje durumu.', 422, ['status' => 'Geçersiz değer.']);
            }
            $fields[] = $key . ' = :' . $key;
            $bind[$key] = $normalizedStatus;
            continue;
        }

        $clean = clean_text((string) $body[$key], match ($key) {
            'title' => 180,
            'description' => 5000,
            'client' => 180,
            default => 32,
        });

        $fields[] = $key . ' = :' . $key;
        $bind[$key] = $clean;
    }

    if (array_key_exists('types', $body) || array_key_exists('type', $body)) {
        $types = normalize_project_types($body['types'] ?? $body['type']);
        if (!$types) {
            error_response('VALIDATION_ERROR', 'Geçersiz proje türü.', 422, ['types' => 'En az bir geçerli tür seçin.']);
        }
        $fields[] = 'type = :type';
        $bind['type'] = encode_multi_value_field($types) ?? 'web';
    }

    if (array_key_exists('tags', $body)) {
        $fields[] = 'tags_json = :tags_json';
        $bind['tags_json'] = encode_json(normalize_tags($body['tags']));
    }

    if (array_key_exists('mvp', $body) || array_key_exists('mvp_items', $body)) {
        $fields[] = 'mvp_json = :mvp_json';
        $bind['mvp_json'] = encode_json(normalize_work_items($body['mvp'] ?? $body['mvp_items']));
    }

    if (array_key_exists('feature_todos', $body) || array_key_exists('todo_items', $body)) {
        $fields[] = 'todo_features_json = :todo_features_json';
        $bind['todo_features_json'] = encode_json(normalize_work_items($body['feature_todos'] ?? $body['todo_items']));
    }

    if (!$fields) {
        error_response('NO_CHANGES', 'Güncellenecek alan bulunamadı.', 422);
    }

    $fields[] = 'updated_at = :updated_at';
    $bind['updated_at'] = now_utc();

    $sql = 'UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $projectStmt = $db->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
    $projectStmt->execute(['id' => $projectId]);
    $updatedProject = $projectStmt->fetch();

    $statusChanged = isset($bind['status']) && $bind['status'] !== $project['status'];

    audit_log($db, (int) $user['id'], 'update', 'project', $projectId, $projectId, [
        'title' => $updatedProject['title'],
        'before_status' => $project['status'],
        'after_status' => $updatedProject['status'],
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    $eventType = $statusChanged ? 'project_status_changed' : 'stage_updated';
    publish_event($db, $eventType, [
        'project_id' => $projectId,
        'project_title' => $updatedProject['title'],
        'status' => $updatedProject['status'],
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success(['project' => map_project_row($updatedProject)]);
});

$router->delete('/projects/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    $project = ensure_project_access($db, $user, $projectId);
    ensure_project_manage_access($user, $project);

    $db->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $projectId]);

    audit_log($db, (int) $user['id'], 'delete', 'project', $projectId, null, ['title' => $project['title']]);

    publish_event($db, 'project_status_changed', [
        'project_id' => $projectId,
        'project_title' => $project['title'],
        'status' => 'deleted',
        'actor_id' => (int) $user['id'],
        'recipient_ids' => all_user_ids($db),
        'created_at' => now_utc(),
    ]);

    success(['deleted' => true]);
});

$router->get('/projects/{id}/stages', static function (array $params) use ($db): void {
    $user = require_auth($db);
    $projectId = (int) $params['id'];
    ensure_project_access($db, $user, $projectId);

    $stmt = $db->prepare('SELECT * FROM stages WHERE project_id = :project_id ORDER BY order_index ASC, id ASC');
    $stmt->execute(['project_id' => $projectId]);

    $stages = array_map(static fn(array $row): array => map_stage_row($row), $stmt->fetchAll());
    success(['stages' => $stages]);
});

$router->post('/projects/{id}/stages', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_admin($user);
    require_csrf();

    $projectId = (int) $params['id'];
    ensure_project_access($db, $user, $projectId);

    $body = request_json_body();
    require_fields($body, [
        'name' => ['required' => true, 'min' => 2, 'max' => 120],
    ]);

    $name = clean_text((string) $body['name'], 120);
    $status = normalize_stage_status(clean_text((string) ($body['status'] ?? 'baslanmadi'), 32) ?? 'baslanmadi');
    $assignees = array_values(array_unique(array_map('intval', (array) ($body['assignees'] ?? []))));
    $links = array_values(array_filter(array_map('trim', (array) ($body['links'] ?? [])), static fn(string $v): bool => $v !== ''));

    $orderIndex = (int) ($body['order_index'] ?? 999);
    $now = now_utc();

    if (!in_array($status, stage_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz aşama durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    $stmt = $db->prepare('INSERT INTO stages(project_id, name, order_index, status, assignees_json, est_hours, spent_hours, links_json, created_at, updated_at) VALUES(:project_id, :name, :order_index, :status, :assignees_json, :est_hours, :spent_hours, :links_json, :created_at, :updated_at)');
    $stmt->execute([
        'project_id' => $projectId,
        'name' => $name,
        'order_index' => $orderIndex,
        'status' => $status,
        'assignees_json' => encode_json($assignees),
        'est_hours' => isset($body['est_hours']) ? (float) $body['est_hours'] : null,
        'spent_hours' => isset($body['spent_hours']) ? (float) $body['spent_hours'] : null,
        'links_json' => encode_json($links),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $stageId = (int) $db->lastInsertId();

    audit_log($db, (int) $user['id'], 'create', 'stage', $stageId, $projectId, ['name' => $name]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'stage_updated', [
        'project_id' => $projectId,
        'stage_id' => $stageId,
        'stage_name' => $name,
        'status' => $status,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => $now,
    ], $recipientIds);

    $stage = load_stage_row($db, $stageId);
    success(['stage' => map_stage_row($stage)], 201);
});

$router->patch('/stages/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $stageId = (int) $params['id'];
    $stage = load_stage_row($db, $stageId);
    $projectId = (int) $stage['project_id'];
    ensure_project_access($db, $user, $projectId);

    $body = request_json_body();

    $fields = [];
    $bind = ['id' => $stageId];

    $simpleFields = ['name', 'status', 'order_index', 'est_hours', 'spent_hours'];
    foreach ($simpleFields as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }

        if ($field === 'status' && !in_array(normalize_stage_status((string) $body['status']), stage_status_values(), true)) {
            error_response('VALIDATION_ERROR', 'Geçersiz aşama durumu.', 422, ['status' => 'Geçersiz değer.']);
        }

        $fields[] = $field . ' = :' . $field;
        if (in_array($field, ['order_index'], true)) {
            $bind[$field] = (int) $body[$field];
        } elseif (in_array($field, ['est_hours', 'spent_hours'], true)) {
            $bind[$field] = $body[$field] !== null ? (float) $body[$field] : null;
        } else {
            $bind[$field] = $field === 'status'
                ? normalize_stage_status((string) $body[$field])
                : clean_text((string) $body[$field], $field === 'name' ? 120 : 32);
        }
    }

    if (array_key_exists('assignees', $body)) {
        $assignees = array_values(array_unique(array_map('intval', (array) $body['assignees'])));
        $fields[] = 'assignees_json = :assignees_json';
        $bind['assignees_json'] = encode_json($assignees);
    }

    if (array_key_exists('links', $body)) {
        $links = array_values(array_filter(array_map('trim', (array) $body['links']), static fn(string $v): bool => $v !== ''));
        $fields[] = 'links_json = :links_json';
        $bind['links_json'] = encode_json($links);
    }

    if (!$fields) {
        error_response('NO_CHANGES', 'Güncellenecek alan bulunamadı.', 422);
    }

    $bind['updated_at'] = now_utc();
    $fields[] = 'updated_at = :updated_at';

    $sql = 'UPDATE stages SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $updated = load_stage_row($db, $stageId);

    audit_log($db, (int) $user['id'], 'update', 'stage', $stageId, $projectId, [
        'name' => $updated['name'],
        'before_status' => $stage['status'],
        'after_status' => $updated['status'],
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'stage_updated', [
        'project_id' => $projectId,
        'stage_id' => $stageId,
        'stage_name' => $updated['name'],
        'status' => $updated['status'],
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success(['stage' => map_stage_row($updated)]);
});

$router->delete('/stages/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_admin($user);
    require_csrf();

    $stageId = (int) $params['id'];
    $stage = load_stage_row($db, $stageId);
    $projectId = (int) $stage['project_id'];
    ensure_project_access($db, $user, $projectId);

    $db->prepare('DELETE FROM stages WHERE id = :id')->execute(['id' => $stageId]);

    audit_log($db, (int) $user['id'], 'delete', 'stage', $stageId, $projectId, ['name' => $stage['name']]);

    $recipientIds = user_ids_for_project($db, $projectId);
    publish_event($db, 'stage_updated', [
        'project_id' => $projectId,
        'stage_id' => $stageId,
        'stage_name' => $stage['name'],
        'status' => 'deleted',
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => now_utc(),
    ], $recipientIds);

    success(['deleted' => true]);
});

$router->get('/projects/{id}/tasks', static function (array $params) use ($db): void {
    $user = require_auth($db);
    $projectId = (int) $params['id'];
    ensure_project_access($db, $user, $projectId);

    $stmt = $db->prepare('SELECT t.*, u.name AS assignee_name, s.name AS stage_name, s.order_index AS stage_order_index,
                                 COALESCE(task_comments.comment_count, 0) AS comment_count
                          FROM tasks t
                          LEFT JOIN users u ON u.id = t.assignee_id
                          LEFT JOIN stages s ON s.id = t.stage_id
                          LEFT JOIN (
                              SELECT entity_id, COUNT(*) AS comment_count
                              FROM comments
                              WHERE entity_type = "task"
                              GROUP BY entity_id
                          ) task_comments ON task_comments.entity_id = t.id
                          WHERE t.project_id = :project_id
                          ORDER BY CASE WHEN t.stage_id IS NULL THEN 9999 ELSE COALESCE(s.order_index, 9998) END ASC,
                                   t.order_index ASC,
                                   t.id ASC');
    $stmt->execute(['project_id' => $projectId]);
    $rows = $stmt->fetchAll();

    $tasks = array_map(static function (array $row): array {
        $task = map_task_row($row);
        $task['assignee_name'] = $row['assignee_name'] ?? null;
        $task['stage_name'] = $row['stage_name'] ?? null;
        return $task;
    }, $rows);

    success(['tasks' => $tasks]);
});

$router->post('/projects/{id}/tasks', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $projectId = (int) $params['id'];
    ensure_project_access($db, $user, $projectId);

    $body = request_json_body();
    require_fields($body, [
        'title' => ['required' => true, 'min' => 2, 'max' => 180],
    ]);

    $title = clean_text((string) $body['title'], 180);
    $description = clean_text((string) ($body['description'] ?? ''), 5000);
    $assigneeId = isset($body['assignee_id']) && $body['assignee_id'] !== '' ? (int) $body['assignee_id'] : null;
    $status = normalize_task_status(clean_text((string) ($body['status'] ?? 'backlog'), 32) ?? 'backlog');
    $priority = clean_text((string) ($body['priority'] ?? 'med'), 20) ?? 'med';
    $dueDate = clean_text((string) ($body['due_date'] ?? ''), 20);
    $stageId = isset($body['stage_id']) && $body['stage_id'] !== '' ? (int) $body['stage_id'] : null;
    $checklist = (array) ($body['checklist'] ?? []);
    $checklist = array_values(array_filter(array_map('trim', $checklist), static fn(string $v): bool => $v !== ''));

    if (!in_array($status, task_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz görev durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    if (!in_array($priority, ['low', 'med', 'high'], true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz görev önceliği.', 422, ['priority' => 'Geçersiz değer.']);
    }

    if ($stageId !== null) {
        $stageStmt = $db->prepare('SELECT id FROM stages WHERE id = :id AND project_id = :project_id LIMIT 1');
        $stageStmt->execute([
            'id' => $stageId,
            'project_id' => $projectId,
        ]);
        if (!$stageStmt->fetch()) {
            error_response('VALIDATION_ERROR', 'Görev için geçersiz aşama.', 422, ['stage_id' => 'Aşama projeye ait değil.']);
        }
    }

    if ($stageId !== null) {
        $orderStmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) AS max_order FROM tasks WHERE project_id = :project_id AND stage_id = :stage_id');
        $orderStmt->execute([
            'project_id' => $projectId,
            'stage_id' => $stageId,
        ]);
    } else {
        $orderStmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) AS max_order FROM tasks WHERE project_id = :project_id AND stage_id IS NULL');
        $orderStmt->execute([
            'project_id' => $projectId,
        ]);
    }
    $orderIndex = ((int) $orderStmt->fetch()['max_order']) + 1;

    $now = now_utc();
    $stmt = $db->prepare('INSERT INTO tasks(project_id, stage_id, title, description, assignee_id, status, priority, due_date, order_index, checklist_json, created_at, updated_at) VALUES(:project_id, :stage_id, :title, :description, :assignee_id, :status, :priority, :due_date, :order_index, :checklist_json, :created_at, :updated_at)');
    $stmt->execute([
        'project_id' => $projectId,
        'stage_id' => $stageId,
        'title' => $title,
        'description' => $description,
        'assignee_id' => $assigneeId,
        'status' => $status,
        'priority' => $priority,
        'due_date' => $dueDate,
        'order_index' => $orderIndex,
        'checklist_json' => encode_json($checklist),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $taskId = (int) $db->lastInsertId();

    audit_log($db, (int) $user['id'], 'create', 'task', $taskId, $projectId, [
        'title' => $title,
        'status' => $status,
        'assignee_id' => $assigneeId,
    ]);

    $recipientIds = user_ids_for_project($db, $projectId);
    if ($assigneeId !== null) {
        $recipientIds[] = $assigneeId;
    }
    $recipientIds = array_values(array_unique($recipientIds));

    publish_event($db, 'task_assigned', [
        'project_id' => $projectId,
        'task_id' => $taskId,
        'title' => $title,
        'assignee_id' => $assigneeId,
        'status' => $status,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => $now,
    ], $recipientIds);

    $task = load_task_row($db, $taskId);
    success(['task' => map_task_row($task)], 201);
});

$router->patch('/tasks/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $taskId = (int) $params['id'];
    $task = load_task_row($db, $taskId);
    $projectId = (int) $task['project_id'];
    ensure_project_access($db, $user, $projectId);

    $body = request_json_body();

    $fields = [];
    $bind = ['id' => $taskId];

    $simpleFields = ['title', 'description', 'status', 'priority', 'due_date', 'order_index'];
    foreach ($simpleFields as $field) {
        if (!array_key_exists($field, $body)) {
            continue;
        }

        $fields[] = $field . ' = :' . $field;

        if ($field === 'order_index') {
            $bind[$field] = (int) $body[$field];
            continue;
        }

        if ($field === 'due_date') {
            $bind[$field] = clean_text((string) $body[$field], 20);
            continue;
        }

        $bind[$field] = clean_text((string) $body[$field], $field === 'title' ? 180 : ($field === 'description' ? 5000 : 32));
    }

    if (array_key_exists('assignee_id', $body)) {
        $fields[] = 'assignee_id = :assignee_id';
        $bind['assignee_id'] = $body['assignee_id'] !== null && $body['assignee_id'] !== '' ? (int) $body['assignee_id'] : null;
    }

    $newStageId = $task['stage_id'] !== null ? (int) $task['stage_id'] : null;
    $stageChanged = false;
    if (array_key_exists('stage_id', $body)) {
        $newStageId = $body['stage_id'] !== null && $body['stage_id'] !== '' ? (int) $body['stage_id'] : null;
        if ($newStageId !== null) {
            $stageStmt = $db->prepare('SELECT id FROM stages WHERE id = :id AND project_id = :project_id LIMIT 1');
            $stageStmt->execute([
                'id' => $newStageId,
                'project_id' => $projectId,
            ]);
            if (!$stageStmt->fetch()) {
                error_response('VALIDATION_ERROR', 'Geçersiz stage_id.', 422, ['stage_id' => 'Aşama projeye ait değil.']);
            }
        }

        $fields[] = 'stage_id = :stage_id';
        $bind['stage_id'] = $newStageId;
        $currentStageId = $task['stage_id'] !== null ? (int) $task['stage_id'] : null;
        $stageChanged = $currentStageId !== $newStageId;
    }

    if ($stageChanged && !array_key_exists('order_index', $body)) {
        if ($newStageId !== null) {
            $orderStmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) AS max_order FROM tasks WHERE project_id = :project_id AND stage_id = :stage_id AND id != :id');
            $orderStmt->execute([
                'project_id' => $projectId,
                'stage_id' => $newStageId,
                'id' => $taskId,
            ]);
        } else {
            $orderStmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) AS max_order FROM tasks WHERE project_id = :project_id AND stage_id IS NULL AND id != :id');
            $orderStmt->execute([
                'project_id' => $projectId,
                'id' => $taskId,
            ]);
        }

        $fields[] = 'order_index = :order_index';
        $bind['order_index'] = ((int) $orderStmt->fetch()['max_order']) + 1;
    }

    if (array_key_exists('checklist', $body)) {
        $items = array_values(array_filter(array_map('trim', (array) $body['checklist']), static fn(string $v): bool => $v !== ''));
        $fields[] = 'checklist_json = :checklist_json';
        $bind['checklist_json'] = encode_json($items);
    }

    if (!$fields) {
        error_response('NO_CHANGES', 'Güncellenecek alan bulunamadı.', 422);
    }

    if (isset($bind['status'])) {
        $bind['status'] = normalize_task_status((string) $bind['status']);
    }

    if (isset($bind['status']) && !in_array($bind['status'], task_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz görev durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    if (isset($bind['priority']) && !in_array($bind['priority'], ['low', 'med', 'high'], true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz görev önceliği.', 422, ['priority' => 'Geçersiz değer.']);
    }

    $fields[] = 'updated_at = :updated_at';
    $bind['updated_at'] = now_utc();

    $sql = 'UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);

    $updated = load_task_row($db, $taskId);
    $statusChanged = $updated['status'] !== $task['status'];
    $assigneeChanged = (int) ($updated['assignee_id'] ?? 0) !== (int) ($task['assignee_id'] ?? 0);

    audit_log($db, (int) $user['id'], 'update', 'task', $taskId, $projectId, [
        'title' => $updated['title'],
        'before_status' => $task['status'],
        'after_status' => $updated['status'],
        'before_assignee' => $task['assignee_id'],
        'after_assignee' => $updated['assignee_id'],
    ]);

    $projectRecipients = user_ids_for_project($db, $projectId);

    if ($assigneeChanged) {
        $recipients = $projectRecipients;
        if ($updated['assignee_id'] !== null) {
            $recipients[] = (int) $updated['assignee_id'];
        }

        $recipients = array_values(array_unique($recipients));

        publish_event($db, 'task_assigned', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'title' => $updated['title'],
            'assignee_id' => $updated['assignee_id'] !== null ? (int) $updated['assignee_id'] : null,
            'status' => $updated['status'],
            'actor_id' => (int) $user['id'],
            'recipient_ids' => $recipients,
            'created_at' => now_utc(),
        ], $recipients);
    }

    if ($statusChanged) {
        publish_event($db, 'task_status_changed', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'title' => $updated['title'],
            'from_status' => $task['status'],
            'to_status' => $updated['status'],
            'actor_id' => (int) $user['id'],
            'recipient_ids' => $projectRecipients,
            'created_at' => now_utc(),
        ], $projectRecipients);
    }

    success(['task' => map_task_row($updated)]);
});

$router->delete('/tasks/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $taskId = (int) $params['id'];
    $task = load_task_row($db, $taskId);
    $projectId = (int) $task['project_id'];
    ensure_project_access($db, $user, $projectId);

    $db->prepare('DELETE FROM tasks WHERE id = :id')->execute(['id' => $taskId]);

    audit_log($db, (int) $user['id'], 'delete', 'task', $taskId, $projectId, ['title' => $task['title']]);

    success(['deleted' => true]);
});

$router->get('/design-assets', static function () use ($db): void {
    $user = require_auth($db);

    $entityType = clean_text((string) ($_GET['entity_type'] ?? ''), 20) ?? '';
    $entityId = (int) ($_GET['entity_id'] ?? 0);

    if (!in_array($entityType, design_asset_entity_values(), true) || $entityId < 1) {
        error_response('VALIDATION_ERROR', 'entity_type ve entity_id zorunludur.', 422, [
            'entity_type' => 'project veya idea olmalı.',
            'entity_id' => 'Geçerli bir kayıt seçin.',
        ]);
    }

    $assets = load_design_assets($db, $user, $entityType, $entityId);
    success(['assets' => $assets]);
});

$router->post('/design-assets', static function () use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $body = request_json_body();
    require_fields($body, [
        'entity_type' => ['required' => true, 'min' => 4, 'max' => 20],
        'entity_id' => ['required' => true],
        'image_url' => ['required' => true, 'min' => 6, 'max' => 2000],
    ]);

    $entityType = clean_text((string) $body['entity_type'], 20) ?? '';
    $entityId = (int) $body['entity_id'];
    $imageUrl = clean_text((string) $body['image_url'], 2000);
    $title = clean_text((string) ($body['title'] ?? ''), 180);
    $description = clean_text((string) ($body['description'] ?? ''), 4000);

    if (!in_array($entityType, design_asset_entity_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz entity_type.', 422, ['entity_type' => 'project veya idea olmalı.']);
    }
    if (!$imageUrl || !preg_match('/^https?:\/\//i', $imageUrl)) {
        error_response('VALIDATION_ERROR', 'Geçersiz görsel adresi.', 422, ['image_url' => 'http/https ile başlamalı.']);
    }

    $projectId = null;
    $entityTitle = null;
    if ($entityType === 'project') {
        $project = ensure_project_access($db, $user, $entityId);
        $projectId = $entityId;
        $entityTitle = (string) ($project['title'] ?? '');
    } else {
        $ideaStmt = $db->prepare('SELECT id, title FROM ideas WHERE id = :id LIMIT 1');
        $ideaStmt->execute(['id' => $entityId]);
        $idea = $ideaStmt->fetch();
        if (!$idea) {
            error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
        }
        $entityTitle = (string) ($idea['title'] ?? '');
    }

    $now = now_utc();
    $stmt = $db->prepare('INSERT INTO design_assets(entity_type, entity_id, project_id, title, image_url, description, created_by, created_at) VALUES(:entity_type, :entity_id, :project_id, :title, :image_url, :description, :created_by, :created_at)');
    $stmt->execute([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'project_id' => $projectId,
        'title' => $title,
        'image_url' => $imageUrl,
        'description' => $description,
        'created_by' => (int) $user['id'],
        'created_at' => $now,
    ]);
    $assetId = (int) $db->lastInsertId();

    audit_log($db, (int) $user['id'], 'create', 'design_asset', $assetId, $projectId, [
        'title' => $title ?: 'Tasarım',
        'entity_type' => $entityType,
        'entity_title' => $entityTitle,
    ]);

    $recipientIds = $projectId ? user_ids_for_project($db, $projectId) : all_user_ids($db);
    publish_event($db, 'design_asset_added', [
        'design_asset_id' => $assetId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_title' => $entityTitle,
        'title' => $title,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => $now,
    ], $recipientIds);

    $assets = load_design_assets($db, $user, $entityType, $entityId);
    $created = null;
    foreach ($assets as $assetRow) {
        if ((int) $assetRow['id'] === $assetId) {
            $created = $assetRow;
            break;
        }
    }

    success(['asset' => $created], 201);
});

$router->patch('/design-assets/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $assetId = (int) $params['id'];
    $asset = ensure_design_asset_access($db, $user, $assetId);

    $canEdit = ($user['role'] ?? '') === 'admin' || (int) $asset['created_by'] === (int) $user['id'];
    if (!$canEdit) {
        error_response('FORBIDDEN', 'Bu tasarımı güncelleme yetkiniz yok.', 403);
    }

    $body = request_json_body();
    $fields = [];
    $bind = ['id' => $assetId];

    if (array_key_exists('title', $body)) {
        $fields[] = 'title = :title';
        $bind['title'] = clean_text((string) $body['title'], 180);
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = :description';
        $bind['description'] = clean_text((string) $body['description'], 4000);
    }
    if (array_key_exists('image_url', $body)) {
        $imageUrl = clean_text((string) $body['image_url'], 2000);
        if ($imageUrl && !preg_match('/^https?:\/\//i', $imageUrl)) {
            error_response('VALIDATION_ERROR', 'Geçersiz görsel adresi.', 422, ['image_url' => 'http/https ile başlamalı.']);
        }
        $fields[] = 'image_url = :image_url';
        $bind['image_url'] = $imageUrl;
    }

    if (!$fields) {
        error_response('NO_CHANGES', 'Güncellenecek alan bulunamadı.', 422);
    }

    $stmt = $db->prepare('UPDATE design_assets SET ' . implode(', ', $fields) . ' WHERE id = :id');
    $stmt->execute($bind);

    audit_log($db, (int) $user['id'], 'update', 'design_asset', $assetId, $asset['project_id'] !== null ? (int) $asset['project_id'] : null, [
        'entity_type' => $asset['entity_type'],
        'entity_id' => (int) $asset['entity_id'],
    ]);

    $assets = load_design_assets($db, $user, (string) $asset['entity_type'], (int) $asset['entity_id']);
    $updated = null;
    foreach ($assets as $assetRow) {
        if ((int) $assetRow['id'] === $assetId) {
            $updated = $assetRow;
            break;
        }
    }

    success(['asset' => $updated]);
});

$router->delete('/design-assets/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $assetId = (int) $params['id'];
    $asset = ensure_design_asset_access($db, $user, $assetId);

    $canDelete = ($user['role'] ?? '') === 'admin' || (int) $asset['created_by'] === (int) $user['id'];
    if (!$canDelete) {
        error_response('FORBIDDEN', 'Bu tasarımı silme yetkiniz yok.', 403);
    }

    $db->prepare('DELETE FROM design_assets WHERE id = :id')->execute(['id' => $assetId]);

    audit_log($db, (int) $user['id'], 'delete', 'design_asset', $assetId, $asset['project_id'] !== null ? (int) $asset['project_id'] : null, [
        'entity_type' => $asset['entity_type'],
        'entity_id' => (int) $asset['entity_id'],
        'title' => $asset['title'],
    ]);

    success(['deleted' => true]);
});

$router->post('/design-assets/{id}/like', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $assetId = (int) $params['id'];
    $asset = ensure_design_asset_access($db, $user, $assetId);
    $userId = (int) $user['id'];

    $existsStmt = $db->prepare('SELECT 1 FROM design_asset_likes WHERE asset_id = :asset_id AND user_id = :user_id LIMIT 1');
    $existsStmt->execute([
        'asset_id' => $assetId,
        'user_id' => $userId,
    ]);

    $liked = false;
    if ($existsStmt->fetchColumn()) {
        $db->prepare('DELETE FROM design_asset_likes WHERE asset_id = :asset_id AND user_id = :user_id')->execute([
            'asset_id' => $assetId,
            'user_id' => $userId,
        ]);
    } else {
        $db->prepare('INSERT INTO design_asset_likes(asset_id, user_id, created_at) VALUES(:asset_id, :user_id, :created_at)')->execute([
            'asset_id' => $assetId,
            'user_id' => $userId,
            'created_at' => now_utc(),
        ]);
        $liked = true;
    }

    $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM design_asset_likes WHERE asset_id = :asset_id');
    $countStmt->execute(['asset_id' => $assetId]);
    $likeCount = (int) ($countStmt->fetch()['c'] ?? 0);

    audit_log($db, $userId, 'update', 'design_asset', $assetId, $asset['project_id'] !== null ? (int) $asset['project_id'] : null, [
        'action' => $liked ? 'like' : 'unlike',
        'entity_type' => $asset['entity_type'],
        'entity_id' => (int) $asset['entity_id'],
    ]);

    success([
        'liked' => $liked,
        'like_count' => $likeCount,
    ]);
});

$router->get('/comments', static function () use ($db): void {
    $user = require_auth($db);

    $entityType = clean_text((string) ($_GET['entity_type'] ?? ''), 30);
    $entityId = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : 0;

    if (!$entityType || $entityId < 1) {
        error_response('VALIDATION_ERROR', 'entity_type ve entity_id zorunludur.', 422, [
            'entity_type' => 'Zorunlu.',
            'entity_id' => 'Zorunlu.',
        ]);
    }

    if ($entityType === 'design_asset') {
        ensure_design_asset_access($db, $user, $entityId);
    }

    $stmt = $db->prepare('SELECT c.*, u.name AS user_name, u.avatar_url FROM comments c JOIN users u ON u.id = c.user_id WHERE c.entity_type = :entity_type AND c.entity_id = :entity_id ORDER BY c.created_at ASC');
    $stmt->execute([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
    ]);

    $comments = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'user_id' => (int) $row['user_id'],
            'user_name' => $row['user_name'],
            'avatar_url' => $row['avatar_url'],
            'content' => $row['content'],
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    success(['comments' => $comments]);
});

$router->post('/comments', static function () use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $body = request_json_body();
    require_fields($body, [
        'entity_type' => ['required' => true, 'min' => 2, 'max' => 30],
        'entity_id' => ['required' => true],
        'content' => ['required' => true, 'min' => 1, 'max' => 4000],
    ]);

    $entityType = clean_text((string) $body['entity_type'], 30);
    $entityId = (int) $body['entity_id'];
    $content = clean_text((string) $body['content'], 4000);

    if (!in_array($entityType, ['project', 'stage', 'task', 'idea', 'design_asset'], true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz entity_type.', 422, [
            'entity_type' => 'Yalnızca project, stage, task, idea veya design_asset olabilir.',
        ]);
    }

    $projectId = null;
    if ($entityType === 'project') {
        $projectId = $entityId;
    } elseif ($entityType === 'stage') {
        $stmt = $db->prepare('SELECT project_id FROM stages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $entityId]);
        $projectId = (int) ($stmt->fetch()['project_id'] ?? 0);
    } elseif ($entityType === 'task') {
        $stmt = $db->prepare('SELECT project_id FROM tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $entityId]);
        $projectId = (int) ($stmt->fetch()['project_id'] ?? 0);
    } elseif ($entityType === 'idea') {
        $stmt = $db->prepare('SELECT id FROM ideas WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $entityId]);
        if (!$stmt->fetch()) {
            error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
        }
    } elseif ($entityType === 'design_asset') {
        $asset = ensure_design_asset_access($db, $user, $entityId);
        if (($asset['entity_type'] ?? '') === 'project') {
            $projectId = (int) ($asset['entity_id'] ?? 0);
        }
    }

    if ($projectId) {
        ensure_project_access($db, $user, $projectId);
    }

    $now = now_utc();
    $stmt = $db->prepare('INSERT INTO comments(entity_type, entity_id, project_id, user_id, content, created_at) VALUES(:entity_type, :entity_id, :project_id, :user_id, :content, :created_at)');
    $stmt->execute([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'project_id' => $projectId,
        'user_id' => (int) $user['id'],
        'content' => $content,
        'created_at' => $now,
    ]);

    $commentId = (int) $db->lastInsertId();

    audit_log($db, (int) $user['id'], 'comment', $entityType, $entityId, $projectId ?: null, [
        'comment_id' => $commentId,
        'content_preview' => mb_substr((string) ($content ?? ''), 0, 120),
    ]);

    $recipientIds = $projectId ? user_ids_for_project($db, $projectId) : all_user_ids($db);
    publish_event($db, 'comment_added', [
        'project_id' => $projectId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'comment_id' => $commentId,
        'content_preview' => mb_substr($content ?? '', 0, 120),
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipientIds,
        'created_at' => $now,
    ], $recipientIds);

    success([
        'comment' => [
            'id' => $commentId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'project_id' => $projectId,
            'user_id' => (int) $user['id'],
            'user_name' => $user['name'],
            'content' => $content,
            'created_at' => $now,
        ],
    ], 201);
});

$router->patch('/comments/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $commentId = (int) $params['id'];
    $commentStmt = $db->prepare('SELECT * FROM comments WHERE id = :id LIMIT 1');
    $commentStmt->execute(['id' => $commentId]);
    $comment = $commentStmt->fetch();

    if (!$comment) {
        error_response('NOT_FOUND', 'Yorum bulunamadı.', 404);
    }

    if ($user['role'] !== 'admin' && (int) $comment['user_id'] !== (int) $user['id']) {
        error_response('FORBIDDEN', 'Bu yorumu güncelleme yetkiniz yok.', 403);
    }

    $body = request_json_body();
    require_fields($body, [
        'content' => ['required' => true, 'min' => 1, 'max' => 4000],
    ]);

    $content = clean_text((string) $body['content'], 4000);
    $stmt = $db->prepare('UPDATE comments SET content = :content WHERE id = :id');
    $stmt->execute([
        'content' => $content,
        'id' => $commentId,
    ]);

    audit_log($db, (int) $user['id'], 'update', 'comment', $commentId, $comment['project_id'] !== null ? (int) $comment['project_id'] : null, [
        'entity_type' => $comment['entity_type'],
        'entity_id' => (int) $comment['entity_id'],
        'content_preview' => mb_substr((string) ($content ?? ''), 0, 120),
    ]);

    success([
        'comment' => [
            'id' => $commentId,
            'entity_type' => $comment['entity_type'],
            'entity_id' => (int) $comment['entity_id'],
            'project_id' => $comment['project_id'] !== null ? (int) $comment['project_id'] : null,
            'user_id' => (int) $comment['user_id'],
            'content' => $content,
            'created_at' => $comment['created_at'],
        ],
    ]);
});

$router->delete('/comments/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $commentId = (int) $params['id'];
    $commentStmt = $db->prepare('SELECT * FROM comments WHERE id = :id LIMIT 1');
    $commentStmt->execute(['id' => $commentId]);
    $comment = $commentStmt->fetch();

    if (!$comment) {
        error_response('NOT_FOUND', 'Yorum bulunamadı.', 404);
    }

    if ($user['role'] !== 'admin' && (int) $comment['user_id'] !== (int) $user['id']) {
        error_response('FORBIDDEN', 'Bu yorumu silme yetkiniz yok.', 403);
    }

    $db->prepare('DELETE FROM comments WHERE id = :id')->execute(['id' => $commentId]);

    audit_log($db, (int) $user['id'], 'delete', 'comment', $commentId, $comment['project_id'] !== null ? (int) $comment['project_id'] : null, [
        'entity_type' => $comment['entity_type'],
        'entity_id' => (int) $comment['entity_id'],
        'content_preview' => mb_substr((string) ($comment['content'] ?? ''), 0, 120),
    ]);

    success(['deleted' => true]);
});

$router->get('/ideas', static function () use ($db): void {
    require_auth($db);

    $conditions = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $status = (string) $_GET['status'];
        if (!in_array($status, idea_status_values(), true)) {
            error_response('VALIDATION_ERROR', 'Geçersiz fikir durumu.', 422, ['status' => 'Geçersiz değer.']);
        }

        $conditions[] = 'i.status = :status';
        $params['status'] = $status;
    }

    if (!empty($_GET['q'])) {
        $conditions[] = '(i.title LIKE :q OR i.description LIKE :q OR i.category LIKE :q)';
        $params['q'] = '%' . trim((string) $_GET['q']) . '%';
    }

    $rawCategoryFilters = $_GET['categories'] ?? ($_GET['category'] ?? null);
    $categoryFilters = normalize_idea_categories($rawCategoryFilters);
    if (normalize_string_list($rawCategoryFilters) && !$categoryFilters) {
        error_response('VALIDATION_ERROR', 'Geçersiz fikir kategorisi filtresi.', 422, ['categories' => 'Geçersiz değer.']);
    }
    if ($categoryFilters) {
        $categoryCondition = build_multi_value_filter_sql('i.category', $categoryFilters, 'idea_category', $params);
        if ($categoryCondition !== null) {
            $conditions[] = $categoryCondition;
        }
    }

    $sort = (string) ($_GET['sort'] ?? 'updated_desc');
    $orderBy = match ($sort) {
        'created_desc' => 'i.created_at DESC',
        'title_asc' => 'i.title ASC',
        'impact_desc' => 'i.impact DESC, i.updated_at DESC',
        'effort_asc' => 'i.effort ASC, i.updated_at DESC',
        default => 'i.updated_at DESC',
    };

    $sql = 'SELECT i.*, u.name AS creator_name FROM ideas i JOIN users u ON u.id = i.created_by';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY ' . $orderBy;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $ideas = array_map(static fn(array $row): array => map_idea_row($row), $stmt->fetchAll());

    success(['ideas' => $ideas]);
});

$router->get('/ideas/{id}', static function (array $params) use ($db): void {
    require_auth($db);

    $ideaId = (int) $params['id'];
    $ideaStmt = $db->prepare('SELECT i.*, u.name AS creator_name FROM ideas i JOIN users u ON u.id = i.created_by WHERE i.id = :id LIMIT 1');
    $ideaStmt->execute(['id' => $ideaId]);
    $ideaRow = $ideaStmt->fetch();

    if (!$ideaRow) {
        error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
    }

    $availableIncludes = ['comments', 'activity'];
    $includeRaw = trim((string) ($_GET['include'] ?? 'comments,activity'));
    if ($includeRaw === '') {
        $includes = [];
    } else {
        $parts = array_map('trim', explode(',', $includeRaw));
        $parts = array_filter($parts, static fn(string $part): bool => $part !== '');
        $includes = array_values(array_intersect($availableIncludes, $parts));
    }
    $includeSet = array_flip($includes);

    $comments = [];
    if (isset($includeSet['comments'])) {
        $commentsLimit = max(1, min(300, (int) ($_GET['comments_limit'] ?? 200)));
        $commentsStmt = $db->prepare('SELECT c.*, u.name AS user_name, u.avatar_url
                                      FROM comments c
                                      JOIN users u ON u.id = c.user_id
                                      WHERE c.entity_type = "idea" AND c.entity_id = :idea_id
                                      ORDER BY c.created_at ASC
                                      LIMIT ' . $commentsLimit);
        $commentsStmt->execute(['idea_id' => $ideaId]);
        $comments = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
                'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
                'user_id' => (int) $row['user_id'],
                'user_name' => $row['user_name'],
                'avatar_url' => $row['avatar_url'],
                'content' => $row['content'],
                'created_at' => $row['created_at'],
            ];
        }, $commentsStmt->fetchAll());
    }

    $activity = [];
    if (isset($includeSet['activity'])) {
        $activityLimit = max(1, min(300, (int) ($_GET['activity_limit'] ?? 120)));
        $activityStmt = $db->prepare('SELECT a.*, u.name AS actor_name
                                      FROM audit_logs a
                                      JOIN users u ON u.id = a.actor_id
                                      WHERE a.entity_type = "idea" AND a.entity_id = :idea_id
                                      ORDER BY a.created_at DESC
                                      LIMIT ' . $activityLimit);
        $activityStmt->execute(['idea_id' => $ideaId]);
        $activity = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'actor_id' => (int) $row['actor_id'],
                'actor_name' => $row['actor_name'],
                'action' => $row['action'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
                'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
                'meta' => decode_json($row['meta_json'], []),
                'created_at' => $row['created_at'],
            ];
        }, $activityStmt->fetchAll());
    }

    success([
        'idea' => map_idea_row($ideaRow),
        'comments' => $comments,
        'activity' => $activity,
    ]);
});

$router->post('/ideas', static function () use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $body = request_json_body();
    require_fields($body, [
        'title' => ['required' => true, 'min' => 2, 'max' => 180],
    ]);

    $title = clean_text((string) $body['title'], 180);
    $description = clean_text((string) ($body['description'] ?? ''), 4000);
    $categoriesInputProvided = array_key_exists('categories', $body) || array_key_exists('category', $body);
    $categories = normalize_idea_categories($body['categories'] ?? ($body['category'] ?? []));
    if ($categoriesInputProvided && !$categories) {
        error_response('VALIDATION_ERROR', 'Geçersiz fikir kategorisi.', 422, ['categories' => 'En az bir geçerli kategori seçin.']);
    }
    if (!$categories) {
        $categories = ['urun_stratejisi'];
    }
    $categoryStorage = encode_multi_value_field($categories);
    $tags = normalize_tags($body['tags'] ?? []);
    $impact = max(1, min(5, (int) ($body['impact'] ?? 3)));
    $effort = max(1, min(5, (int) ($body['effort'] ?? 3)));
    $status = clean_text((string) ($body['status'] ?? 'idea'), 30) ?? 'idea';
    $mvpItems = normalize_work_items($body['mvp'] ?? ($body['mvp_items'] ?? []));
    $featureTodos = normalize_work_items($body['feature_todos'] ?? ($body['todo_items'] ?? []));

    if (!in_array($status, idea_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz fikir durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    $now = now_utc();
    $stmt = $db->prepare('INSERT INTO ideas(title, description, category, tags_json, impact, effort, status, mvp_json, todo_features_json, created_by, created_at, updated_at) VALUES(:title, :description, :category, :tags_json, :impact, :effort, :status, :mvp_json, :todo_features_json, :created_by, :created_at, :updated_at)');
    $stmt->execute([
        'title' => $title,
        'description' => $description,
        'category' => $categoryStorage,
        'tags_json' => encode_json($tags),
        'impact' => $impact,
        'effort' => $effort,
        'status' => $status,
        'mvp_json' => encode_json($mvpItems),
        'todo_features_json' => encode_json($featureTodos),
        'created_by' => (int) $user['id'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $ideaId = (int) $db->lastInsertId();

    audit_log($db, (int) $user['id'], 'create', 'idea', $ideaId, null, ['title' => $title]);

    $recipients = all_user_ids($db);
    publish_event($db, 'idea_added', [
        'idea_id' => $ideaId,
        'title' => $title,
        'status' => $status,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipients,
        'created_at' => $now,
    ], $recipients);

    $reload = $db->prepare('SELECT i.*, u.name AS creator_name FROM ideas i JOIN users u ON u.id = i.created_by WHERE i.id = :id LIMIT 1');
    $reload->execute(['id' => $ideaId]);

    success([
        'idea' => map_idea_row($reload->fetch()),
    ], 201);
});

$router->patch('/ideas/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $ideaId = (int) $params['id'];
    $ideaStmt = $db->prepare('SELECT * FROM ideas WHERE id = :id LIMIT 1');
    $ideaStmt->execute(['id' => $ideaId]);
    $idea = $ideaStmt->fetch();

    if (!$idea) {
        error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
    }

    if ($user['role'] !== 'admin' && (int) $idea['created_by'] !== (int) $user['id']) {
        error_response('FORBIDDEN', 'Bu fikri güncelleme yetkiniz yok.', 403);
    }

    $body = request_json_body();

    $fields = [];
    $bind = ['id' => $ideaId];

    $textFields = ['title' => 180, 'description' => 4000, 'status' => 30];
    foreach ($textFields as $key => $maxLen) {
        if (!array_key_exists($key, $body)) {
            continue;
        }

        $fields[] = $key . ' = :' . $key;
        $bind[$key] = clean_text((string) $body[$key], $maxLen);
    }

    if (array_key_exists('impact', $body)) {
        $fields[] = 'impact = :impact';
        $bind['impact'] = max(1, min(5, (int) $body['impact']));
    }

    if (array_key_exists('effort', $body)) {
        $fields[] = 'effort = :effort';
        $bind['effort'] = max(1, min(5, (int) $body['effort']));
    }

    if (array_key_exists('tags', $body)) {
        $fields[] = 'tags_json = :tags_json';
        $bind['tags_json'] = encode_json(normalize_tags($body['tags']));
    }

    if (array_key_exists('mvp', $body) || array_key_exists('mvp_items', $body)) {
        $fields[] = 'mvp_json = :mvp_json';
        $bind['mvp_json'] = encode_json(normalize_work_items($body['mvp'] ?? $body['mvp_items']));
    }

    if (array_key_exists('feature_todos', $body) || array_key_exists('todo_items', $body)) {
        $fields[] = 'todo_features_json = :todo_features_json';
        $bind['todo_features_json'] = encode_json(normalize_work_items($body['feature_todos'] ?? $body['todo_items']));
    }

    if (isset($bind['status']) && !in_array($bind['status'], idea_status_values(), true)) {
        error_response('VALIDATION_ERROR', 'Geçersiz fikir durumu.', 422, ['status' => 'Geçersiz değer.']);
    }

    if (array_key_exists('categories', $body) || array_key_exists('category', $body)) {
        $categories = normalize_idea_categories($body['categories'] ?? $body['category']);
        if (!$categories) {
            error_response('VALIDATION_ERROR', 'Geçersiz fikir kategorisi.', 422, ['categories' => 'En az bir geçerli kategori seçin.']);
        }
        $fields[] = 'category = :category';
        $bind['category'] = encode_multi_value_field($categories);
    }

    if (!$fields) {
        error_response('NO_CHANGES', 'Güncellenecek alan bulunamadı.', 422);
    }

    $fields[] = 'updated_at = :updated_at';
    $bind['updated_at'] = now_utc();

    $stmt = $db->prepare('UPDATE ideas SET ' . implode(', ', $fields) . ' WHERE id = :id');
    $stmt->execute($bind);

    audit_log($db, (int) $user['id'], 'update', 'idea', $ideaId, null, [
        'title' => $bind['title'] ?? $idea['title'],
        'before_status' => $idea['status'],
        'after_status' => $bind['status'] ?? $idea['status'],
    ]);

    $reload = $db->prepare('SELECT i.*, u.name AS creator_name FROM ideas i JOIN users u ON u.id = i.created_by WHERE i.id = :id LIMIT 1');
    $reload->execute(['id' => $ideaId]);
    $row = $reload->fetch();

    success([
        'idea' => map_idea_row($row),
    ]);
});

$router->delete('/ideas/{id}', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $ideaId = (int) $params['id'];
    $stmt = $db->prepare('SELECT * FROM ideas WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
    }

    if ($user['role'] !== 'admin' && (int) $idea['created_by'] !== (int) $user['id']) {
        error_response('FORBIDDEN', 'Bu fikri silme yetkiniz yok.', 403);
    }

    $db->prepare('DELETE FROM ideas WHERE id = :id')->execute(['id' => $ideaId]);

    audit_log($db, (int) $user['id'], 'delete', 'idea', $ideaId, null, ['title' => $idea['title']]);

    success(['deleted' => true]);
});

$router->post('/ideas/{id}/convert-to-project', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $ideaId = (int) $params['id'];

    $ideaStmt = $db->prepare('SELECT * FROM ideas WHERE id = :id LIMIT 1');
    $ideaStmt->execute(['id' => $ideaId]);
    $idea = $ideaStmt->fetch();

    if (!$idea) {
        error_response('NOT_FOUND', 'Fikir bulunamadı.', 404);
    }

    if ($user['role'] !== 'admin' && (int) $idea['created_by'] !== (int) $user['id']) {
        error_response('FORBIDDEN', 'Bu fikri projeye çevirme yetkiniz yok.', 403);
    }

    $now = now_utc();
    $ideaCategories = normalize_idea_categories($idea['category'] ?? null);
    $projectTypesFromIdea = array_values(array_intersect($ideaCategories, ['web', 'mobil', 'app']));
    if (!$projectTypesFromIdea) {
        $projectTypesFromIdea = ['app'];
    }
    $projectTypeStorage = encode_multi_value_field($projectTypesFromIdea) ?? 'app';

    $projectStmt = $db->prepare('INSERT INTO projects(title, description, type, client, status, priority, due_date, tags_json, mvp_json, todo_features_json, created_by, created_at, updated_at) VALUES(:title, :description, :type, :client, :status, :priority, :due_date, :tags_json, :mvp_json, :todo_features_json, :created_by, :created_at, :updated_at)');
    $projectStmt->execute([
        'title' => $idea['title'],
        'description' => $idea['description'],
        'type' => $projectTypeStorage,
        'client' => null,
        'status' => 'baslaniyor',
        'priority' => 'med',
        'due_date' => null,
        'tags_json' => $idea['tags_json'],
        'mvp_json' => $idea['mvp_json'] ?? encode_json([]),
        'todo_features_json' => $idea['todo_features_json'] ?? encode_json([]),
        'created_by' => (int) $user['id'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $projectId = (int) $db->lastInsertId();
    add_project_members($db, $projectId, [(int) $user['id']]);
    create_project_stages_from_templates($db, $projectId);

    $updateIdea = $db->prepare('UPDATE ideas SET status = :status, updated_at = :updated_at WHERE id = :id');
    $updateIdea->execute([
        'status' => 'planned',
        'updated_at' => $now,
        'id' => $ideaId,
    ]);

    audit_log($db, (int) $user['id'], 'convert', 'idea', $ideaId, $projectId, [
        'title' => $idea['title'],
        'project_title' => $idea['title'],
        'project_id' => $projectId,
    ]);

    $recipients = all_user_ids($db);
    publish_event($db, 'project_created', [
        'project_id' => $projectId,
        'title' => $idea['title'],
        'from_idea_id' => $ideaId,
        'actor_id' => (int) $user['id'],
        'recipient_ids' => $recipients,
        'created_at' => $now,
    ], $recipients);

    success([
        'project_id' => $projectId,
        'idea_id' => $ideaId,
    ], 201);
});

$router->get('/activity', static function () use ($db): void {
    $user = require_auth($db);

    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

    $conditions = [];
    $params = [];

    if (isset($_GET['project_id']) && (int) $_GET['project_id'] > 0) {
        $projectId = (int) $_GET['project_id'];
        ensure_project_access($db, $user, $projectId);
        $conditions[] = 'a.project_id = :project_id';
        $params['project_id'] = $projectId;
    } elseif ($user['role'] !== 'admin') {
        $conditions[] = '(a.project_id IS NULL OR EXISTS (SELECT 1 FROM project_members pm WHERE pm.project_id = a.project_id AND pm.user_id = :viewer_id))';
        $params['viewer_id'] = (int) $user['id'];
    }

    $sql = 'SELECT a.*, u.name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_id';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY a.created_at DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $activity = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'actor_id' => (int) $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'meta' => decode_json($row['meta_json'], []),
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    success(['activity' => $activity]);
});

$router->get('/projects/{id}/activity', static function (array $params) use ($db): void {
    $user = require_auth($db);
    $projectId = (int) $params['id'];
    ensure_project_access($db, $user, $projectId);

    $stmt = $db->prepare('SELECT a.*, u.name AS actor_name FROM audit_logs a JOIN users u ON u.id = a.actor_id WHERE a.project_id = :project_id ORDER BY a.created_at DESC LIMIT 100');
    $stmt->execute(['project_id' => $projectId]);

    $activity = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'actor_id' => (int) $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'meta' => decode_json($row['meta_json'], []),
            'created_at' => $row['created_at'],
        ];
    }, $stmt->fetchAll());

    success(['activity' => $activity]);
});

$router->get('/notifications', static function () use ($db): void {
    $user = require_auth($db);
    $userId = (int) $user['id'];

    $limit = max(1, min(300, (int) ($_GET['limit'] ?? 60)));
    $unreadOnly = bool_param($_GET['unread_only'] ?? null, false);
    $scope = clean_text((string) ($_GET['scope'] ?? ''), 20);

    $sql = 'SELECT * FROM notifications WHERE user_id = :user_id';
    if ($unreadOnly) {
        $sql .= ' AND is_read = 0';
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll();

    if ($scope === 'relevant') {
        $rows = array_values(array_filter($rows, static fn(array $row): bool => relevant_notification($row, $userId)));
    }

    $notifications = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'type' => $row['type'],
            'payload' => decode_json($row['payload_json'], []),
            'is_read' => (bool) $row['is_read'],
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    success(['notifications' => $notifications]);
});

$router->patch('/notifications/{id}/read', static function (array $params) use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $notificationId = (int) $params['id'];

    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id' => $notificationId,
        'user_id' => (int) $user['id'],
    ]);

    success(['updated' => true]);
});

$router->post('/notifications/read-all', static function () use ($db): void {
    $user = require_auth($db);
    require_csrf();

    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute(['user_id' => (int) $user['id']]);

    success(['updated' => true]);
});

$router->get('/events/latest', static function () use ($db): void {
    require_auth($db);
    $maxId = (int) ($db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM events')->fetch()['max_id'] ?? 0);
    success(['last_event_id' => $maxId]);
});

$router->get('/events/stream', static function (): void {
    require __DIR__ . '/events_stream.php';
    exit;
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = request_path();

try {
    $router->dispatch($method, $path);
} catch (Throwable $e) {
    error_response('INTERNAL_ERROR', $e->getMessage(), 500);
}
