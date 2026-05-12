# FTS Player — Video Queue

A live YouTube queue system for projector screens. Users scan a QR code, search for a video, and it plays automatically on the big screen.

---

## Requirements

> **HTTPS is required.** Browsers block the YouTube IFrame player on plain `http://` pages. You must serve the app over HTTPS (SSL certificate) or the projector display will show a blank player.
>
> For local testing, use `localhost` — browsers allow the YouTube embed on localhost without HTTPS.

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

> Without a YouTube API key, users can still add videos by pasting a YouTube URL or video ID directly.

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

### 3. Set up SSL

The app must be served over HTTPS. Options:

- **Reverse proxy (recommended):** Put Nginx or Traefik in front of the container and terminate SSL there. Point it to port `8080` (or whichever port you mapped in `docker-compose.yml`).
- **Certbot / Let's Encrypt:** Free certificates for domains you own. Works well with Nginx proxy.
- **Self-signed certificate:** For internal/LAN use. Browsers will show a warning but will still allow the YouTube embed after you accept it.
- **Localhost:** No SSL needed when accessing via `http://localhost` during development.

### 4. Open the pages

| Page | URL | Who |
|------|-----|-----|
| User page | `https://yourserver/index.html` | Audience — scan QR to add videos |
| Projector | `https://yourserver/projector.html` | Open fullscreen (F11) on the projector |
| Admin | `https://yourserver/admin.html` | You |
| Queue view | `https://yourserver/queue.html` | Display-only queue overview |

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
docker build -t robzwet/fts-player:0.3 .   # also tag a version
docker push robzwet/fts-player:latest
docker push robzwet/fts-player:0.3
```

Then on another server you only need `docker-compose.yml` — swap `build: .` for `image: robzwet/fts-player:latest` and run `docker compose up -d`.

---

## Admin panel

Open `https://yourserver/admin.html` and sign in with the credentials from `docker-compose.yml`. The panel is split into four tabs:

### Controls
- **Projector remote** — play, pause, skip, volume, mute, seek
- **Live progress bar** — shows current playback position; drag to seek
- **Add video** — paste a YouTube URL or ID to add a video as admin
- **Current queue** — reorder or remove any video
- **Ticker message** — set the scrolling text shown below the video on the projector
- **Statistics** — queue length, plays today, total plays, most-requested videos

### History
Full play history with timestamps. Can be cleared from here.

### Users
Manage admin accounts — add or remove users with username/password. The first login uses the `ADMIN_PASSWORD` from your environment until you create a named user.

### Settings
Customise the look and feel of the site for your event:
- **Event / Site Title** — sets the name shown in the browser tab and topbar on all pages (e.g. "Friday Night Party")
- **Accent Color** — choose from 7 preset colors or pick a custom color. The selection applies live and is saved to the database so all pages (user, queue, projector) pick it up automatically.

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
6. Make sure your web server is configured with HTTPS

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
| `YT_API_KEY` | _(empty)_ | YouTube Data API v3 key — enables keyword search |
| `MAX_PER_IP` | `3` | Max videos one session can queue at once |
| `COOLDOWN_SECONDS` | `1800` | Seconds before the same video can be re-queued (0 = off) |
| `ALLOWED_ORIGIN` | _(empty)_ | CORS restriction (empty = allow all) |

---

## Project structure

```
├── index.html              User page — search & add videos
├── projector.html          Fullscreen projector display
├── admin.html              Admin panel (tabs: Controls, History, Users, Settings)
├── queue.html              Read-only queue overview
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
