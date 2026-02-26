<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

date_default_timezone_set('UTC');

$db = db();
$user = require_auth($db);

// Event stream okuma sırasında diğer API çağrıları beklemesin diye
// session kilidini hemen bırakıyoruz.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

while (ob_get_level() > 0) {
    ob_end_flush();
}

$lastEventId = 0;
if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastEventId = (int) $_SERVER['HTTP_LAST_EVENT_ID'];
}
if (!empty($_GET['lastEventId'])) {
    $lastEventId = max($lastEventId, (int) $_GET['lastEventId']);
}

echo "retry: 3000\n\n";
@flush();

$started = time();
$ttlSeconds = 20;

$eventStmt = $db->prepare('SELECT id, type, payload_json, created_at FROM events WHERE id > :id ORDER BY id ASC LIMIT 200');

ignore_user_abort(true);
set_time_limit(0);

while ((time() - $started) < $ttlSeconds) {
    if (connection_aborted()) {
        break;
    }

    $eventStmt->execute(['id' => $lastEventId]);
    $events = $eventStmt->fetchAll();

    if ($events) {
        foreach ($events as $event) {
            $payload = decode_json($event['payload_json'], []);
            $targets = $payload['recipient_ids'] ?? null;

            if (is_array($targets) && !in_array((int) $user['id'], array_map('intval', $targets), true)) {
                $lastEventId = (int) $event['id'];
                continue;
            }

            echo 'id: ' . (int) $event['id'] . "\n";
            echo 'event: ' . $event['type'] . "\n";
            echo 'data: ' . json_encode([
                'id' => (int) $event['id'],
                'type' => $event['type'],
                'payload' => $payload,
                'created_at' => $event['created_at'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

            $lastEventId = (int) $event['id'];
        }
        @flush();
        continue;
    }

    echo ': ping ' . now_utc() . "\n\n";
    @flush();
    usleep(850000);
}
