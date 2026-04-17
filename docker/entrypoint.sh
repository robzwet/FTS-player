#!/bin/sh
# ═══════════════════════════════════════════════
#  Video Queue — Container entrypoint
#  1. Waits for MySQL to be ready
#  2. Runs database setup (creates tables if not exist)
#  3. Starts Apache
# ═══════════════════════════════════════════════

set -e

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."

# Wait until MySQL accepts connections (max 60 seconds)
i=0
until php -r "
    try {
        \$pdo = new PDO(
            'mysql:host=${DB_HOST};port=${DB_PORT};charset=utf8mb4',
            '${DB_USER}',
            '${DB_PASS}'
        );
        exit(0);
    } catch (Exception \$e) {
        exit(1);
    }
" 2>/dev/null; do
    i=$((i+1))
    if [ $i -ge 30 ]; then
        echo "[entrypoint] ERROR: MySQL did not become ready in time."
        exit 1
    fi
    echo "[entrypoint] MySQL not ready yet, retrying in 2s... (${i}/30)"
    sleep 2
done

echo "[entrypoint] MySQL is ready. Running database setup..."

# Run setup via PHP CLI (creates tables if they don't exist)
php /db-init.php

echo "[entrypoint] Setup complete. Starting Apache..."
exec "$@"
