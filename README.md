# FTS Player — Video Queue

A live YouTube queue system for projector screens. Users scan a QR code, search for a video, and it plays automatically on the big screen.

---

## Quick Start with Docker

### 1. Edit `docker-compose.yml`

Change every value marked `← change this`:

```yaml
DB_PASS:            your-db-password
MYSQL_PASSWORD:     your-db-password    # must match DB_PASS
MYSQL_ROOT_PASSWORD: something-secure
ADMIN_PASSWORD:     your-admin-password
```

Optionally add a YouTube API key to enable keyword search:
```yaml
YT_API_KEY: "AIzaSy..."
```

### 2. Start

```bash
docker compose up -d
```

Docker will:
- Build the PHP/Apache app container
- Start MySQL
- Wait for MySQL to be healthy
- Automatically create all database tables
- Start Apache

### 3. Open the pages

| Page | URL | Who |
|------|-----|-----|
| User page | `http://yourserver/index.html` | Audience — scan QR to add videos |
| Projector | `http://yourserver/projector.html` | Open fullscreen (F11) on the projector |
| Admin | `http://yourserver/admin.html` | You |

> **No need to run `setup.php`** when using Docker — the database is initialised automatically at container start.

---

## Common commands

```bash
docker compose up -d          # start in background
docker compose down           # stop (data preserved)
docker compose down -v        # stop AND wipe database
docker compose logs -f app    # watch PHP/Apache logs
docker compose logs -f db     # watch MySQL logs
docker compose restart app    # restart app after file changes
```

## Rebuilding after code changes

```bash
docker compose down
docker compose build --no-cache
docker compose up -d
```

## Pushing to Docker Hub

```bash
docker login
docker build -t robzwet/fts-player:latest .
docker build -t robzwet/fts-player:0.2 .   # also tag a version
docker push robzwet/fts-player:latest
docker push robzwet/fts-player:0.2
```

Then on another server you only need `docker-compose.yml` — swap `build: .` for `image: robzwet/fts-player:latest` and run `docker compose up -d`.

---

## Running without Docker (bare metal PHP)

1. Upload all `.php` and `.html` files to your web root
2. Edit `config.php` directly with your DB credentials
3. Create the database in phpMyAdmin or MySQL:
   ```sql
   CREATE DATABASE videoqueue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'vquser'@'localhost' IDENTIFIED BY 'yourpassword';
   GRANT ALL PRIVILEGES ON videoqueue.* TO 'vquser'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Open `setup.php` in your browser to create the tables
5. Delete `setup.php` after setup

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `db` | MySQL host (`db` = Docker service name) |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `videoqueue` | Database name |
| `DB_USER` | `vquser` | Database user |
| `DB_PASS` | `changeme` | Database password |
| `ADMIN_PASSWORD` | `changeme` | Fallback admin password (used before DB users are set up) |
| `YT_API_KEY` | _(empty)_ | YouTube Data API v3 key |
| `MAX_PER_IP` | `3` | Max videos one IP can queue at once |
| `COOLDOWN_SECONDS` | `1800` | Seconds before same video can be re-queued |
| `ALLOWED_ORIGIN` | _(empty)_ | CORS restriction (empty = allow all) |

---

## Project structure

```
├── index.html              User page — search & add videos
├── projector.html          Fullscreen projector display
├── admin.html              Admin panel
├── api.php                 PHP backend API
├── config.php              Reads config from environment variables
├── setup.php               One-time DB setup (not needed with Docker)
├── Dockerfile              PHP/Apache container
├── docker-compose.yml      App + MySQL orchestration
└── docker/
    ├── 000-default.conf    Apache virtual host config
    ├── entrypoint.sh       Waits for DB, runs init, starts Apache
    ├── db-init.php         Creates tables at startup (CLI)
    └── init.sql            SQL reference (not used by Docker directly)
```
