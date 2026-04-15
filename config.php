<?php
// ═══════════════════════════════════════════════
//  VIDEO QUEUE — CONFIG
// ═══════════════════════════════════════════════

// ── Database ────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'videoqueue');   // database name you created
define('DB_USER', 'vquser');       // database username
define('DB_PASS', 'changeme');     // database password
define('DB_CHARSET', 'utf8mb4');

// ── Admin ────────────────────────────────────────
// Password to access admin.html
define('ADMIN_PASSWORD', 'changeme');

// ── YouTube Data API v3 key ──────────────────────
// Enables keyword search on the user page.
// Get a free key at https://console.cloud.google.com
// Leave empty to disable keyword search (URL/ID paste still works).
define('YT_API_KEY', '');

// ── Queue rules ──────────────────────────────────
// How many videos one IP can have in the active queue at once.
define('MAX_PER_IP', 3);

// Minimum seconds before the same video ID can be queued again.
// 0 = no restriction.
define('COOLDOWN_SECONDS', 1800);  // 30 minutes

// ── CORS ─────────────────────────────────────────
// Leave empty to allow all origins, or set to your domain.
define('ALLOWED_ORIGIN', '');
