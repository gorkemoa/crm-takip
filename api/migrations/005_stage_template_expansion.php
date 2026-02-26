<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $defaults = [
        'Brief ve Hedef',
        'Araştırma',
        'Üzerinde Tartışma',
        'Tasarım',
        'Geliştirme',
        'İç Test',
        'Müşteri Onayı',
        'Yayın',
        'Bakım',
    ];
    $legacyDefaults = [
        'Araştırma',
        'Tasarım',
        'Geliştirme',
        'Test',
        'Yayın',
        'Bakım',
    ];

    $rows = $db->query('SELECT name FROM stage_templates ORDER BY order_index ASC')->fetchAll();
    $existing = [];
    foreach ($rows as $row) {
        $existing[mb_strtolower(trim((string) $row['name']), 'UTF-8')] = true;
    }

    $legacyKeys = array_map(static fn(string $name): string => mb_strtolower(trim($name), 'UTF-8'), $legacyDefaults);
    $hasOnlyLegacy = count($existing) === count($legacyKeys);
    if ($hasOnlyLegacy) {
        foreach ($legacyKeys as $legacyKey) {
            if (!isset($existing[$legacyKey])) {
                $hasOnlyLegacy = false;
                break;
            }
        }
    }

    $now = now_utc();
    $insertStmt = $db->prepare('INSERT INTO stage_templates(name, order_index, created_at) VALUES(:name, :order_index, :created_at)');

    if ($hasOnlyLegacy) {
        $db->exec('DELETE FROM stage_templates');
        foreach ($defaults as $orderIndex => $name) {
            $insertStmt->execute([
                'name' => $name,
                'order_index' => $orderIndex,
                'created_at' => $now,
            ]);
        }
        return;
    }

    $maxOrderStmt = $db->query('SELECT COALESCE(MAX(order_index), -1) AS max_order FROM stage_templates');
    $maxOrder = (int) $maxOrderStmt->fetch()['max_order'];
    $nextOrder = $maxOrder + 1;

    foreach ($defaults as $name) {
        $key = mb_strtolower(trim($name), 'UTF-8');
        if (isset($existing[$key])) {
            continue;
        }

        $insertStmt->execute([
            'name' => $name,
            'order_index' => $nextOrder,
            'created_at' => $now,
        ]);

        $existing[$key] = true;
        $nextOrder++;
    }
};
