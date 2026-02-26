<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $count = (int) $db->query('SELECT COUNT(*) AS c FROM stage_templates')->fetch()['c'];
    if ($count > 0) {
        return;
    }

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
    $stmt = $db->prepare('INSERT INTO stage_templates(name, order_index, created_at) VALUES(:name, :order_index, :created_at)');
    $createdAt = now_utc();

    foreach ($defaults as $index => $name) {
        $stmt->execute([
            'name' => $name,
            'order_index' => $index,
            'created_at' => $createdAt,
        ]);
    }
};
