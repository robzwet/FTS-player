<?php
// ═══════════════════════════════════════════════
//  VIDEO QUEUE — API  (MariaDB edition)
//
//  GET  ?action=state          → queue + ticker + recent history
//  GET  ?action=search&q=…     → YouTube keyword search (proxied)
//  GET  ?action=oembed&id=…    → single video metadata
//  GET  ?action=history        → full play history
//  GET  ?action=stats          → queue stats
//
//  POST {action:"add"}         → add video (user)
//  POST {action:"played"}      → mark played (projector)
//  POST {action:"remove"}      → remove from queue  [admin]
//  POST {action:"reorder"}     → move item          [admin]
//  POST {action:"ticker"}      → set ticker message [admin]
//  POST {action:"clear"}       → clear queue        [admin]
//  POST {action:"clear_history"} → clear history    [admin]
//  POST {action:"login"}       → verify admin pass
// ═══════════════════════════════════════════════

require_once __DIR__ . '/config.php';

// ── Headers ──────────────────────────────────────
$origin = ALLOWED_ORIGIN ?: '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── DB connection (lazy singleton) ───────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        fail('Database connection failed: ' . $e->getMessage(), 503);
    }
    return $pdo;
}

// ── Response helpers ─────────────────────────────
function ok(mixed $payload = null): void {
    echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ── Input sanitisation ───────────────────────────
function sanitizeId(string $raw): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', substr($raw, 0, 11));
}

function sanitizeVideo(array $v): array {
    return [
        'video_id' => sanitizeId($v['id'] ?? ''),
        'title'    => mb_substr(strip_tags($v['title']   ?? 'Untitled'), 0, 200),
        'channel'  => mb_substr(strip_tags($v['channel'] ?? ''),         0, 100),
        'duration' => mb_substr(strip_tags($v['duration']?? ''),         0, 20),
    ];
}

// Hash the submitter IP so we never store raw IPs
function submitterHash(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['HTTP_X_REAL_IP']
       ?? $_SERVER['REMOTE_ADDR']
       ?? 'unknown';
    $ip = explode(',', $ip)[0];
    return hash('sha256', trim($ip) . 'vq_salt_2024');
}

// ── Admin auth ───────────────────────────────────
function requireAdmin(array $body): void {
    $pass = $body['password'] ?? ($_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '');
    if ($pass !== ADMIN_PASSWORD) fail('Unauthorized', 403);
}

// ── Queue helpers ────────────────────────────────

// Returns full state array for frontend
function getState(): array {
    $db = db();

    $queue = $db->query(
        "SELECT video_id AS id, title, channel, duration, added_by, added_at, position
         FROM queue ORDER BY position ASC, id ASC"
    )->fetchAll();

    $played = $db->query(
        "SELECT video_id AS id FROM history ORDER BY played_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_COLUMN);

    $ticker = $db->query(
        "SELECT value FROM settings WHERE `key` = 'ticker'"
    )->fetchColumn() ?: '';

    return ['queue' => $queue, 'played' => $played, 'ticker' => $ticker];
}

// Recalculate position field after any insert/delete/reorder
function reindex(): void {
    $db = db();
    $rows = $db->query("SELECT id FROM queue ORDER BY position ASC, id ASC")->fetchAll();
    $stmt = $db->prepare("UPDATE queue SET position = ? WHERE id = ?");
    foreach ($rows as $i => $row) {
        $stmt->execute([$i, $row['id']]);
    }
}

// ── YouTube helpers ──────────────────────────────
function ytSearch(string $q): array {
    if (!YT_API_KEY) fail('YouTube API key not configured', 501);
    $q = urlencode($q);

    $searchRaw = @file_get_contents(
        "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&maxResults=8&q={$q}&key=" . YT_API_KEY
    );
    if (!$searchRaw) fail('YouTube search request failed');
    $search = json_decode($searchRaw, true);
    if (!empty($search['error'])) fail($search['error']['message'] ?? 'YouTube API error');

    $ids = implode(',', array_column(array_map(fn($i) => $i['id'], $search['items'] ?? []), 'videoId'));
    if (!$ids) return [];

    $detailRaw = @file_get_contents(
        "https://www.googleapis.com/youtube/v3/videos?part=contentDetails,statistics&id={$ids}&key=" . YT_API_KEY
    );
    $detail    = json_decode($detailRaw ?: '{}', true);
    $detailMap = [];
    foreach ($detail['items'] ?? [] as $item) $detailMap[$item['id']] = $item;

    return array_map(function($item) use ($detailMap) {
        $id  = $item['id']['videoId'];
        $det = $detailMap[$id] ?? [];
        return [
            'id'       => $id,
            'title'    => $item['snippet']['title']        ?? $id,
            'channel'  => $item['snippet']['channelTitle'] ?? '',
            'duration' => formatDuration($det['contentDetails']['duration'] ?? ''),
            'views'    => formatViews($det['statistics']['viewCount']       ?? ''),
        ];
    }, $search['items'] ?? []);
}

function ytOembed(string $id): array {
    $url = "https://www.youtube.com/oembed?url=" . urlencode("https://www.youtube.com/watch?v={$id}") . "&format=json";
    $raw = @file_get_contents($url);
    if (!$raw) return ['id' => $id, 'title' => $id, 'channel' => '', 'duration' => ''];
    $data = json_decode($raw, true);
    return ['id' => $id, 'title' => $data['title'] ?? $id, 'channel' => $data['author_name'] ?? '', 'duration' => ''];
}

function formatDuration(string $iso): string {
    if (!$iso) return '';
    preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m);
    $h = (int)($m[1] ?? 0); $min = (int)($m[2] ?? 0); $sec = (int)($m[3] ?? 0);
    return $h ? sprintf('%d:%02d:%02d', $h, $min, $sec) : sprintf('%d:%02d', $min, $sec);
}

function formatViews(string $n): string {
    $n = (int)$n;
    if ($n >= 1_000_000_000) return round($n / 1_000_000_000, 1) . 'B views';
    if ($n >= 1_000_000)     return round($n / 1_000_000, 1)     . 'M views';
    if ($n >= 1_000)         return round($n / 1_000)            . 'K views';
    return $n ? $n . ' views' : '';
}

// ═══════════════════════════════════════════════
//  ROUTING
// ═══════════════════════════════════════════════
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'state';

    // Full queue state
    if ($action === 'state') {
        ok(getState());
    }

    // YouTube keyword search
    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (!$q) fail('Missing query parameter');
        ok(ytSearch($q));
    }

    // Single video metadata via oEmbed
    if ($action === 'oembed') {
        $id = sanitizeId($_GET['id'] ?? '');
        if (!$id) fail('Missing or invalid video id');
        ok(ytOembed($id));
    }

    // Full play history (for admin)
    if ($action === 'history') {
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $rows = db()->query(
            "SELECT video_id AS id, title, channel, duration, added_by, added_at, played_at
             FROM history ORDER BY played_at DESC LIMIT {$limit}"
        )->fetchAll();
        ok($rows);
    }

    // Queue statistics (for admin dashboard)
    if ($action === 'stats') {
        $db    = db();
        $stats = [
            'queue_length'   => (int)$db->query("SELECT COUNT(*) FROM queue")->fetchColumn(),
            'total_played'   => (int)$db->query("SELECT COUNT(*) FROM history")->fetchColumn(),
            'played_today'   => (int)$db->query("SELECT COUNT(*) FROM history WHERE DATE(played_at) = CURDATE()")->fetchColumn(),
            'unique_videos'  => (int)$db->query("SELECT COUNT(DISTINCT video_id) FROM history")->fetchColumn(),
            'top_videos'     => $db->query(
                "SELECT video_id AS id, title, COUNT(*) AS times_played
                 FROM history GROUP BY video_id, title ORDER BY times_played DESC LIMIT 5"
            )->fetchAll(),
        ];
        ok($stats);
    }

    fail('Unknown action');
}

// ── POST ─────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $db     = db();

    // ── Add video (user) ─────────────────────────
    if ($action === 'add') {
        $v = sanitizeVideo($body['video'] ?? []);
        if (!$v['video_id']) fail('Invalid or missing video ID');

        $submitter = submitterHash();

        // Duplicate check — already in queue?
        $exists = $db->prepare("SELECT id FROM queue WHERE video_id = ?");
        $exists->execute([$v['video_id']]);
        if ($exists->fetch()) fail('This video is already in the queue');

        // Cooldown check — was this video played recently?
        if (COOLDOWN_SECONDS > 0) {
            $cooldown = $db->prepare(
                "SELECT played_at FROM history WHERE video_id = ?
                 ORDER BY played_at DESC LIMIT 1"
            );
            $cooldown->execute([$v['video_id']]);
            $last = $cooldown->fetchColumn();
            if ($last) {
                $elapsed = time() - strtotime($last);
                if ($elapsed < COOLDOWN_SECONDS) {
                    $wait = ceil((COOLDOWN_SECONDS - $elapsed) / 60);
                    fail("This video was played recently. Try again in {$wait} minute(s).");
                }
            }
        }

        // Per-IP queue limit
        if (MAX_PER_IP > 0) {
            $ipCount = $db->prepare("SELECT COUNT(*) FROM queue WHERE added_by = ?");
            $ipCount->execute([$submitter]);
            if ((int)$ipCount->fetchColumn() >= MAX_PER_IP) {
                fail('You already have ' . MAX_PER_IP . ' video(s) in the queue. Wait for one to play first.');
            }
        }

        // Get next position
        $maxPos = (int)$db->query("SELECT COALESCE(MAX(position), -1) FROM queue")->fetchColumn();

        $stmt = $db->prepare(
            "INSERT INTO queue (video_id, title, channel, duration, added_by, position)
             VALUES (:video_id, :title, :channel, :duration, :added_by, :position)"
        );
        $stmt->execute([
            ':video_id' => $v['video_id'],
            ':title'    => $v['title'],
            ':channel'  => $v['channel'],
            ':duration' => $v['duration'],
            ':added_by' => $submitter,
            ':position' => $maxPos + 1,
        ]);

        ok(getState());
    }

    // ── Mark as played (projector) ───────────────
    if ($action === 'played') {
        $id = sanitizeId($body['id'] ?? '');
        if (!$id) fail('Missing video id');

        $db->beginTransaction();
        try {
            // Copy to history
            $row = $db->prepare("SELECT * FROM queue WHERE video_id = ? LIMIT 1");
            $row->execute([$id]);
            $v = $row->fetch();

            if ($v) {
                $db->prepare(
                    "INSERT INTO history (video_id, title, channel, duration, added_by, added_at)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$v['video_id'], $v['title'], $v['channel'], $v['duration'], $v['added_by'], $v['added_at']]);

                // Remove from queue
                $db->prepare("DELETE FROM queue WHERE video_id = ?")->execute([$id]);
                reindex();
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }

        ok(getState());
    }

    // ── Remove from queue (admin) ─────────────────
    if ($action === 'remove') {
        requireAdmin($body);
        $id = sanitizeId($body['id'] ?? '');
        if (!$id) fail('Missing video id');
        $db->prepare("DELETE FROM queue WHERE video_id = ?")->execute([$id]);
        reindex();
        ok(getState());
    }

    // ── Reorder queue (admin) ─────────────────────
    if ($action === 'reorder') {
        requireAdmin($body);
        $from = (int)($body['from'] ?? -1);
        $to   = (int)($body['to']   ?? -1);

        $rows = $db->query("SELECT id FROM queue ORDER BY position ASC, id ASC")->fetchAll();
        $count = count($rows);
        if ($from < 0 || $to < 0 || $from >= $count || $to >= $count) fail('Invalid indices');

        // Splice
        $ids   = array_column($rows, 'id');
        $moved = array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, $moved);

        $stmt = $db->prepare("UPDATE queue SET position = ? WHERE id = ?");
        foreach ($ids as $pos => $id) $stmt->execute([$pos, $id]);

        ok(getState());
    }

    // ── Update ticker (admin) ─────────────────────
    if ($action === 'ticker') {
        requireAdmin($body);
        $msg = mb_substr(strip_tags($body['message'] ?? ''), 0, 500);
        $db->prepare(
            "INSERT INTO settings (`key`, value) VALUES ('ticker', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        )->execute([$msg]);
        ok(getState());
    }

    // ── Clear queue (admin) ───────────────────────
    if ($action === 'clear') {
        requireAdmin($body);
        $db->exec("DELETE FROM queue");
        ok(getState());
    }

    // ── Clear history (admin) ─────────────────────
    if ($action === 'clear_history') {
        requireAdmin($body);
        $db->exec("DELETE FROM history");
        ok(getState());
    }

    // ── Admin login check ─────────────────────────
    if ($action === 'login') {
        $pass = $body['password'] ?? '';
        if ($pass === ADMIN_PASSWORD) ok(['authenticated' => true]);
        else fail('Wrong password', 403);
    }

    fail('Unknown action');
}

fail('Method not allowed', 405);
