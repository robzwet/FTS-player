<?php
// ═══════════════════════════════════════════════
//  VIDEO QUEUE — DATABASE SETUP
//  Open this once in your browser to create all
//  tables. Safe to run multiple times (IF NOT EXISTS).
//  Delete or restrict access to this file after setup.
// ═══════════════════════════════════════════════

require_once __DIR__ . '/config.php';

$steps  = [];
$errors = [];

function step(string $label, bool $ok, string $detail = ''): void {
    global $steps, $errors;
    $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $errors[] = $label;
}

// ── 1. Connect ──────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    step('Connect to MariaDB', true, DB_HOST . ':' . DB_PORT . ' as ' . DB_USER);
} catch (PDOException $e) {
    step('Connect to MariaDB', false, $e->getMessage());
    $pdo = null;
}

// ── 2. Create / select database ─────────────────
if ($pdo) {
    try {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        step('Create/select database `' . DB_NAME . '`', true);
    } catch (PDOException $e) {
        step('Create/select database `' . DB_NAME . '`', false, $e->getMessage());
        $pdo = null;
    }
}

// ── 3. Create tables ────────────────────────────
$tables = [

    // Active queue — videos waiting to be played
    'queue' => "CREATE TABLE IF NOT EXISTS `queue` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_id`   VARCHAR(11)  NOT NULL,
        `title`      VARCHAR(200) NOT NULL DEFAULT '',
        `channel`    VARCHAR(100) NOT NULL DEFAULT '',
        `duration`   VARCHAR(20)  NOT NULL DEFAULT '',
        `added_by`   VARCHAR(45)  NOT NULL DEFAULT '',  -- submitter IP (hashed)
        `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `position`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_video_id (`video_id`),
        INDEX idx_position (`position`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Played history — permanent log of everything that aired
    'history' => "CREATE TABLE IF NOT EXISTS `history` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_id`   VARCHAR(11)  NOT NULL,
        `title`      VARCHAR(200) NOT NULL DEFAULT '',
        `channel`    VARCHAR(100) NOT NULL DEFAULT '',
        `duration`   VARCHAR(20)  NOT NULL DEFAULT '',
        `added_by`   VARCHAR(45)  NOT NULL DEFAULT '',
        `added_at`   DATETIME     NOT NULL,
        `played_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_video_id (`video_id`),
        INDEX idx_played_at (`played_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Settings — key/value store (ticker message, etc.)
    'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
        `key`        VARCHAR(60)   NOT NULL PRIMARY KEY,
        `value`      TEXT          NOT NULL DEFAULT '',
        `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    if (!$pdo) break;
    try {
        $pdo->exec($sql);
        step("Create table `{$name}`", true);
    } catch (PDOException $e) {
        step("Create table `{$name}`", false, $e->getMessage());
    }
}

// ── 4. Seed default settings ────────────────────
if ($pdo && empty($errors)) {
    try {
        $pdo->exec("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('ticker', '')");
        step('Seed default settings', true);
    } catch (PDOException $e) {
        step('Seed default settings', false, $e->getMessage());
    }
}

// ── 5. Verify row counts ─────────────────────────
if ($pdo && empty($errors)) {
    foreach (['queue', 'history', 'settings'] as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            step("Verify table `{$t}`", true, $count . ' row(s)');
        } catch (PDOException $e) {
            step("Verify table `{$t}`", false, $e->getMessage());
        }
    }
}

$allOk = empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Queue — Setup</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #0a0a0f; color: #f0f0f0; font-family: system-ui, sans-serif; padding: 40px 24px; }
  .wrap { max-width: 600px; margin: 0 auto; }
  h1   { font-size: 1.6rem; margin-bottom: 6px; color: #e8ff47; letter-spacing: .05em; }
  .sub { color: #6666aa; font-size: .85rem; margin-bottom: 32px; }
  .step {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 16px; border-radius: 8px; margin-bottom: 8px;
    background: #14141f; border: 1px solid #1f1f32;
  }
  .icon { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; font-size: .75rem; font-weight: 700; }
  .icon.ok  { background: rgba(71,255,160,.15); color: #47ffa0; border: 1px solid rgba(71,255,160,.3); }
  .icon.err { background: rgba(255,79,71,.15);  color: #ff4f47; border: 1px solid rgba(255,79,71,.3); }
  .step-label  { font-size: .9rem; font-weight: 600; }
  .step-detail { font-size: .75rem; color: #6666aa; margin-top: 3px; font-family: monospace; }
  .result {
    margin-top: 28px; padding: 20px 22px; border-radius: 10px; border: 1px solid;
    font-size: .9rem; line-height: 1.6;
  }
  .result.ok  { background: rgba(71,255,160,.06); border-color: rgba(71,255,160,.25); color: #47ffa0; }
  .result.err { background: rgba(255,79,71,.06);  border-color: rgba(255,79,71,.25);  color: #ff4f47; }
  .result strong { display: block; font-size: 1rem; margin-bottom: 6px; }
  code { background: #1f1f32; padding: 2px 6px; border-radius: 4px; font-size: .82rem; color: #e8ff47; }
  a    { color: #e8ff47; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Video Queue — Setup</h1>
  <p class="sub">Database initialisation for MariaDB</p>

  <?php foreach ($steps as $s): ?>
  <div class="step">
    <div class="icon <?= $s['ok'] ? 'ok' : 'err' ?>"><?= $s['ok'] ? '✓' : '✗' ?></div>
    <div>
      <div class="step-label"><?= htmlspecialchars($s['label']) ?></div>
      <?php if ($s['detail']): ?>
        <div class="step-detail"><?= htmlspecialchars($s['detail']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($allOk): ?>
  <div class="result ok">
    <strong>✓ Setup complete!</strong>
    All tables created successfully. You can now:<br><br>
    • Open <a href="projector.html">projector.html</a> on the projector screen<br>
    • Open <a href="admin.html">admin.html</a> to manage the queue<br>
    • Share <a href="index.html">index.html</a> with your audience<br><br>
    <strong>Important:</strong> Delete or rename <code>setup.php</code> after setup — it's not needed anymore.
  </div>
  <?php else: ?>
  <div class="result err">
    <strong>✗ Setup failed</strong>
    Check the errors above and verify your <code>config.php</code> credentials.<br><br>
    Common fixes:<br>
    • Make sure the database <code><?= htmlspecialchars(DB_NAME) ?></code> exists, or that the user has CREATE privileges<br>
    • Check <code>DB_USER</code> and <code>DB_PASS</code> in config.php<br>
    • Make sure MariaDB is running and accessible from <code><?= htmlspecialchars(DB_HOST) ?></code>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
