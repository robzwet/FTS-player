<?php
// ═══════════════════════════════════════════════
//  VIDEO QUEUE — CONFIG
//  Values are read from environment variables.
//  When running with Docker, set these in docker-compose.yml.
//  When running without Docker, edit the default values here.
// ═══════════════════════════════════════════════

function env(string $key, string $default = ''): string {
    $val = getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
}

// ── Database ─────────────────────────────────────
define('DB_HOST',    env('DB_HOST',    'db'));          // 'db' = docker-compose service name
define('DB_PORT',    (int)env('DB_PORT', '3306'));
define('DB_NAME',    env('DB_NAME',    'videoqueue'));
define('DB_USER',    env('DB_USER',    'vquser'));
define('DB_PASS',    env('DB_PASS',    'changeme'));
define('DB_CHARSET', 'utf8mb4');

// ── Admin ─────────────────────────────────────────
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD', 'changeme'));

// ── YouTube Data API v3 key ───────────────────────
define('YT_API_KEY', env('YT_API_KEY', ''));

// ── Queue rules ───────────────────────────────────
// MAX_PER_IP limits per device/browser session, not per IP.
// Works correctly when all users share the same WiFi/NAT.
define('MAX_PER_IP',       (int)env('MAX_PER_IP',       '3'));
define('COOLDOWN_SECONDS', (int)env('COOLDOWN_SECONDS', '1800'));

// ── CORS ──────────────────────────────────────────
define('ALLOWED_ORIGIN', env('ALLOWED_ORIGIN', ''));
