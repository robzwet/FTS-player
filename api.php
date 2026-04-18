<?php
// ═══════════════════════════════════════════════
//  VIDEO QUEUE — API
//
//  GET  ?action=state            → queue + ticker + recent history
//  GET  ?action=search&q=…       → YouTube keyword search (proxied)
//  GET  ?action=oembed&id=…      → single video metadata
//  GET  ?action=history          → full play history
//  GET  ?action=stats            → queue stats
//  GET  ?action=command          → projector polls this for pending command
//
//  POST {action:"add"}           → add video (user)
//  POST {action:"played"}        → mark played (projector)
//  POST {action:"cmd_ack"}       → projector acknowledges command
//
//  POST {action:"remove"}        → remove from queue       [admin]
//  POST {action:"reorder"}       → move item up/down       [admin]
//  POST {action:"ticker"}        → set ticker message      [admin]
//  POST {action:"clear"}         → clear queue             [admin]
//  POST {action:"clear_history"} → clear history           [admin]
//  POST {action:"command"}       → send projector command  [admin]
//
//  POST {action:"login"}         → verify admin credentials
//  POST {action:"add_admin"}     → create admin user       [admin]
//  POST {action:"remove_admin"}  → delete admin user       [admin]
//  GET  ?action=list_admins      → list admin users        [admin]
// ═══════════════════════════════════════════════
 
require_once __DIR__ . '/config.php';
 
// ── Headers ──────────────────────────────────────
$origin = ALLOWED_ORIGIN ?: '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
 
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
 
// ── DB connection ────────────────────────────────
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
 
// ── Sanitisation ─────────────────────────────────
function sanitizeId(string $raw): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', substr($raw, 0, 11));
}
 
function sanitizeVideo(array $v): array {
    return [
        'video_id' => sanitizeId($v['id'] ?? ''),
        'title'    => mb_substr(strip_tags($v['title']    ?? 'Untitled'), 0, 200),
        'channel'  => mb_substr(strip_tags($v['channel']  ?? ''),         0, 100),
        'duration' => mb_substr(strip_tags($v['duration'] ?? ''),         0, 20),
    ];
}
 
function sanitizeToken(string $raw): string {
    // Token is a hex string generated client-side, max 64 chars
    return preg_replace('/[^a-f0-9]/', '', strtolower(substr($raw, 0, 64)));
}
 
function submitterHash(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['HTTP_X_REAL_IP']
       ?? $_SERVER['REMOTE_ADDR']
       ?? 'unknown';
    $ip = explode(',', $ip)[0];
    return hash('sha256', trim($ip) . 'vq_salt_2024');
}
 
// ── Admin auth ───────────────────────────────────
// Supports both the legacy single ADMIN_PASSWORD from config and DB users.
// Returns the username on success, calls fail() on failure.
function requireAdmin(array $body): string {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
 
    // Legacy single-password mode (no username supplied or no users in DB yet)
    if ($username === '' || $username === 'admin') {
        // Check DB users table first
        try {
            $stmt = db()->prepare("SELECT username, password_hash FROM admins WHERE username = 'admin' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row && password_verify($password, $row['password_hash'])) return 'admin';
        } catch (PDOException $e) {}
 
        // Fall back to config password
        if ($password === ADMIN_PASSWORD) return 'admin';
        fail('Unauthorized', 403);
    }
 
    // Named user from DB
    $stmt = db()->prepare("SELECT username, password_hash FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) fail('Unauthorized', 403);
    return $row['username'];
}
 
// ── Queue state ──────────────────────────────────
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
 
function reindex(): void {
    $db = db();
    $rows = $db->query("SELECT id FROM queue ORDER BY position ASC, id ASC")->fetchAll();
    $stmt = $db->prepare("UPDATE queue SET position = ? WHERE id = ?");
    foreach ($rows as $i => $row) $stmt->execute([$i, $row['id']]);
}
 
// ── Projector command helpers ────────────────────
// Commands are stored in the settings table as JSON under key 'projector_command'.
// Projector polls every 2s, ACKs when done. Simple and requires no extra table.
function setCommand(string $cmd, mixed $value = null): void {
    $payload = json_encode(['cmd' => $cmd, 'value' => $value, 'ts' => time(), 'ack' => false]);
    db()->prepare(
        "INSERT INTO settings (`key`, value) VALUES ('projector_command', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([$payload]);
}
 
function getCommand(): ?array {
    $raw = db()->query(
        "SELECT value FROM settings WHERE `key` = 'projector_command'"
    )->fetchColumn();
    if (!$raw) return null;
    $cmd = json_decode($raw, true);
    if (!$cmd || ($cmd['ack'] ?? true)) return null; // already acked
    return $cmd;
}
 
// ── Search result cache ──────────────────────────
// Caches YouTube search results in the DB to avoid burning quota
// on repeated searches for the same query.
// Each search costs 100 quota units — caching saves ~90% of calls at events.
const SEARCH_CACHE_TTL = 600; // seconds (10 minutes)
 
function getCachedSearch(string $q): ?array {
    try {
        $row = db()->prepare(
            "SELECT result, cached_at FROM search_cache WHERE query_hash = ? LIMIT 1"
        );
        $row->execute([md5(strtolower(trim($q)))]);
        $hit = $row->fetch();
        if (!$hit) return null;
        if (time() - strtotime($hit['cached_at']) > SEARCH_CACHE_TTL) return null;
        return json_decode($hit['result'], true);
    } catch (\PDOException $e) {
        return null; // cache table may not exist yet — fail gracefully
    }
}
 
function setCachedSearch(string $q, array $results): void {
    try {
        db()->prepare(
            "INSERT INTO search_cache (query_hash, query_text, result, cached_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE result = VALUES(result), cached_at = NOW()"
        )->execute([md5(strtolower(trim($q))), mb_substr($q, 0, 200), json_encode($results)]);
    } catch (\PDOException $e) {
        // Silently ignore cache write failures
    }
}
 
// ── YouTube helpers ──────────────────────────────
function ytSearch(string $q): array {
    if (!YT_API_KEY) fail('YouTube API key not configured', 501);
 
    // Check cache first — saves 100 quota units per cache hit
    $cached = getCachedSearch($q);
    if ($cached !== null) return $cached;
 
    $qEnc = urlencode($q);
 
    $searchRaw = @file_get_contents(
        "https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&maxResults=8&q={$qEnc}&key=" . YT_API_KEY
    );
    if (!$searchRaw) fail('YouTube search request failed');
    $search = json_decode($searchRaw, true);
    if (!empty($search['error'])) fail($search['error']['message'] ?? 'YouTube API error');
 
    $ids = implode(',', array_column(
        array_map(fn($i) => $i['id'], $search['items'] ?? []), 'videoId'
    ));
    if (!$ids) return [];
 
    $detailRaw = @file_get_contents(
        "https://www.googleapis.com/youtube/v3/videos?part=contentDetails,statistics&id={$ids}&key=" . YT_API_KEY
    );
    $detail    = json_decode($detailRaw ?: '{}', true);
    $detailMap = [];
    foreach ($detail['items'] ?? [] as $item) $detailMap[$item['id']] = $item;
 
    $results = array_map(function($item) use ($detailMap) {
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
 
    // Store in cache so the same query doesn't cost quota again for 10 minutes
    setCachedSearch($q, $results);
 
    return $results;
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
 
    if ($action === 'state') {
        ok(getState());
    }
 
    if ($action === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (!$q) fail('Missing query parameter');
        ok(ytSearch($q));
    }
 
    if ($action === 'oembed') {
        $id = sanitizeId($_GET['id'] ?? '');
        if (!$id) fail('Missing or invalid video id');
        ok(ytOembed($id));
    }
 
    if ($action === 'history') {
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $rows = db()->query(
            "SELECT video_id AS id, title, channel, duration, added_by, added_at, played_at
             FROM history ORDER BY played_at DESC LIMIT {$limit}"
        )->fetchAll();
        ok($rows);
    }
 
    if ($action === 'stats') {
        $db = db();
        ok([
            'queue_length'  => (int)$db->query("SELECT COUNT(*) FROM queue")->fetchColumn(),
            'total_played'  => (int)$db->query("SELECT COUNT(*) FROM history")->fetchColumn(),
            'played_today'  => (int)$db->query("SELECT COUNT(*) FROM history WHERE DATE(played_at) = CURDATE()")->fetchColumn(),
            'unique_videos' => (int)$db->query("SELECT COUNT(DISTINCT video_id) FROM history")->fetchColumn(),
            'top_videos'    => $db->query(
                "SELECT video_id AS id, title, COUNT(*) AS times_played
                 FROM history GROUP BY video_id, title ORDER BY times_played DESC LIMIT 5"
            )->fetchAll(),
        ]);
    }
 
    // Projector polls this for pending commands
    if ($action === 'command') {
        ok(getCommand());
    }
 
    // List admin users [admin]
    if ($action === 'list_admins') {
        $body = [];
        parse_str(file_get_contents('php://input') ?? '', $body);
        // For GET we read from query string
        $body = ['username' => $_GET['username'] ?? '', 'password' => $_GET['password'] ?? ''];
        requireAdmin($body);
        $rows = db()->query(
            "SELECT username, created_at FROM admins ORDER BY created_at ASC"
        )->fetchAll();
        ok($rows);
    }
 
    // Fetch queue entries belonging to a session token (user's own videos)
    if ($action === 'my_queue') {
        $token = sanitizeToken($_GET['token'] ?? '');
        if (!$token) fail('Missing token');
        $rows = db()->prepare(
            "SELECT video_id AS id, title, channel, duration, position, added_at
             FROM queue WHERE session_token = ? ORDER BY position ASC"
        );
        $rows->execute([$token]);
        ok($rows->fetchAll());
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
 
        $exists = $db->prepare("SELECT id FROM queue WHERE video_id = ?");
        $exists->execute([$v['video_id']]);
        if ($exists->fetch()) fail('This video is already in the queue');
 
        if (COOLDOWN_SECONDS > 0) {
            $cooldown = $db->prepare(
                "SELECT played_at FROM history WHERE video_id = ? ORDER BY played_at DESC LIMIT 1"
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
 
        // Limit per session token (per device) rather than per IP,
        // so everyone on the same WiFi/NAT gets their own independent limit.
        $token = sanitizeToken($body['session_token'] ?? '');
 
        if (MAX_PER_IP > 0 && $token !== '') {
            $tokenCount = $db->prepare("SELECT COUNT(*) FROM queue WHERE session_token = ?");
            $tokenCount->execute([$token]);
            if ((int)$tokenCount->fetchColumn() >= MAX_PER_IP) {
                fail('You already have ' . MAX_PER_IP . ' video(s) in the queue. Wait for one to play first.');
            }
        }
 
        $maxPos = (int)$db->query("SELECT COALESCE(MAX(position), -1) FROM queue")->fetchColumn();
 
        $db->prepare(
            "INSERT INTO queue (video_id, title, channel, duration, added_by, session_token, position)
             VALUES (:video_id, :title, :channel, :duration, :added_by, :session_token, :position)"
        )->execute([
            ':video_id'      => $v['video_id'],
            ':title'         => $v['title'],
            ':channel'       => $v['channel'],
            ':duration'      => $v['duration'],
            ':added_by'      => $submitter,
            ':session_token' => $token,
            ':position'      => $maxPos + 1,
        ]);
 
        ok(getState());
    }
 
    // ── Mark as played (projector) ───────────────
    if ($action === 'played') {
        $id = sanitizeId($body['id'] ?? '');
        if (!$id) fail('Missing video id');
 
        $db->beginTransaction();
        try {
            $row = $db->prepare("SELECT * FROM queue WHERE video_id = ? LIMIT 1");
            $row->execute([$id]);
            $v = $row->fetch();
 
            if ($v) {
                $db->prepare(
                    "INSERT INTO history (video_id, title, channel, duration, added_by, added_at)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$v['video_id'], $v['title'], $v['channel'], $v['duration'], $v['added_by'], $v['added_at']]);
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
 
    // ── Projector acknowledges command ───────────
    if ($action === 'cmd_ack') {
        $raw = $db->query("SELECT value FROM settings WHERE `key` = 'projector_command'")->fetchColumn();
        if ($raw) {
            $cmd = json_decode($raw, true);
            if ($cmd) {
                $cmd['ack'] = true;
                $db->prepare("UPDATE settings SET value = ? WHERE `key` = 'projector_command'"
                )->execute([json_encode($cmd)]);
            }
        }
        ok(null);
    }
 
    // ── Remove own video (user — token verified) ────
    if ($action === 'remove_own') {
        $id    = sanitizeId($body['id'] ?? '');
        $token = sanitizeToken($body['session_token'] ?? '');
        if (!$id)    fail('Missing video id');
        if (!$token) fail('Missing session token');
 
        // Only delete if the token matches — user can only remove their own videos
        $stmt = $db->prepare("DELETE FROM queue WHERE video_id = ? AND session_token = ?");
        $stmt->execute([$id, $token]);
 
        if ($stmt->rowCount() === 0) fail('Video not found or not yours', 403);
        reindex();
        ok(getState());
    }
 
    // ── Remove (admin) ───────────────────────────
    if ($action === 'remove') {
        requireAdmin($body);
        $id = sanitizeId($body['id'] ?? '');
        if (!$id) fail('Missing video id');
        $db->prepare("DELETE FROM queue WHERE video_id = ?")->execute([$id]);
        reindex();
        ok(getState());
    }
 
    // ── Reorder (admin) ──────────────────────────
    if ($action === 'reorder') {
        requireAdmin($body);
        $from = (int)($body['from'] ?? -1);
        $to   = (int)($body['to']   ?? -1);
 
        $rows  = $db->query("SELECT id FROM queue ORDER BY position ASC, id ASC")->fetchAll();
        $count = count($rows);
        if ($from < 0 || $to < 0 || $from >= $count || $to >= $count) fail('Invalid indices');
 
        $ids   = array_column($rows, 'id');
        $moved = array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, $moved);
 
        $stmt = $db->prepare("UPDATE queue SET position = ? WHERE id = ?");
        foreach ($ids as $pos => $id) $stmt->execute([$pos, $id]);
 
        ok(getState());
    }
 
    // ── Ticker (admin) ───────────────────────────
    if ($action === 'ticker') {
        requireAdmin($body);
        $msg = mb_substr(strip_tags($body['message'] ?? ''), 0, 500);
        $db->prepare(
            "INSERT INTO settings (`key`, value) VALUES ('ticker', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        )->execute([$msg]);
        ok(getState());
    }
 
    // ── Clear queue (admin) ──────────────────────
    if ($action === 'clear') {
        requireAdmin($body);
        $db->exec("DELETE FROM queue");
        ok(getState());
    }
 
    // ── Clear history (admin) ────────────────────
    if ($action === 'clear_history') {
        requireAdmin($body);
        $db->exec("DELETE FROM history");
        ok(getState());
    }
 
    // ── Send projector command (admin) ───────────
    if ($action === 'command') {
        requireAdmin($body);
        $cmd = mb_substr(strip_tags($body['cmd'] ?? ''), 0, 30);
        $val = $body['value'] ?? null;
        if (!in_array($cmd, ['play', 'pause', 'next', 'volume', 'mute', 'unmute'])) {
            fail('Unknown command');
        }
        setCommand($cmd, $val);
        ok(null);
    }
 
    // ── Login check ──────────────────────────────
    if ($action === 'login') {
        $username = requireAdmin($body);
        ok(['authenticated' => true, 'username' => $username]);
    }
 
    // ── Add admin user ───────────────────────────
    if ($action === 'add_admin') {
        requireAdmin($body); // must be authenticated to add users
        $newUser = mb_substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['new_username'] ?? ''), 0, 40);
        $newPass = $body['new_password'] ?? '';
        if (strlen($newUser) < 2) fail('Username too short (min 2 characters)');
        if (strlen($newPass) < 6) fail('Password too short (min 6 characters)');
 
        $exists = $db->prepare("SELECT id FROM admins WHERE username = ?");
        $exists->execute([$newUser]);
        if ($exists->fetch()) fail('Username already exists');
 
        $db->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)")
           ->execute([$newUser, password_hash($newPass, PASSWORD_BCRYPT)]);
 
        $rows = $db->query("SELECT username, created_at FROM admins ORDER BY created_at ASC")->fetchAll();
        ok($rows);
    }
 
    // ── Remove admin user ────────────────────────
    if ($action === 'remove_admin') {
        $caller = requireAdmin($body);
        $target = $body['target_username'] ?? '';
        if ($target === $caller) fail('You cannot delete your own account');
        if ($target === '') fail('Missing target_username');
        $db->prepare("DELETE FROM admins WHERE username = ?")->execute([$target]);
        $rows = $db->query("SELECT username, created_at FROM admins ORDER BY created_at ASC")->fetchAll();
        ok($rows);
    }
 
    fail('Unknown action');
}
 
fail('Method not allowed', 405);