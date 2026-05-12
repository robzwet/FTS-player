# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Project Is

FTS Player is a live YouTube queue system for events/projectors. Audience members scan a QR code, search for videos on their phone, and videos play automatically on a large screen. It has four pages: user queue submission (index.html), fullscreen projector display (projector.html), admin panel (admin.html), and queue viewer (queue.html).

## Tech Stack

- **Backend:** PHP 8.2 with PDO (MySQL 8.0)
- **Frontend:** Vanilla HTML/CSS/JavaScript — no build step, no framework, no npm
- **Deployment:** Docker + Docker Compose (Apache 2 web server)
- **External:** YouTube Data API v3 (optional search), YouTube IFrame API (playback), YouTube oEmbed (metadata)

## Dev Commands

```bash
docker compose up -d              # Start app + MySQL
docker compose down               # Stop (data preserved)
docker compose down -v            # Stop and wipe database
docker compose logs -f app        # Watch PHP/Apache logs
docker compose restart app        # Restart after PHP/HTML changes
docker compose build --no-cache   # Rebuild after Dockerfile changes
```

No test suite or linter is configured. There is no build step — edit HTML/PHP files directly and restart the container.

## Environment Variables

Set in `docker-compose.yml`. Key variables (see `config.php` for all):

| Variable | Default | Notes |
|---|---|---|
| `DB_PASS` | `changeme` | Must be changed for production |
| `ADMIN_PASSWORD` | `changeme` | Fallback before DB admin users exist |
| `YT_API_KEY` | _(empty)_ | Enables YouTube keyword search |
| `MAX_PER_IP` | `3` | Max videos one session can queue |
| `COOLDOWN_SECONDS` | `1800` | Seconds before same video can be requeued |

## Architecture

### Backend (api.php)
Single-file REST API. All requests go to `api.php` with `action` as a GET param or JSON body key. Admin actions require a session token validated against the `admins` table (bcrypt) or the `ADMIN_PASSWORD` env var fallback.

Key patterns:
- All SQL uses PDO prepared statements
- YouTube search results are cached in the `search_cache` table (MD5 key, 10-min TTL) to conserve API quota
- Session tokens (SHA256) are generated client-side to track per-user limits without storing IPs

### Database (5 tables)
- **queue** — current video queue with ordering
- **history** — played videos log
- **settings** — key-value store (ticker text, projector commands, playback progress)
- **admins** — admin accounts with bcrypt passwords
- **search_cache** — cached YouTube API responses

Database is initialized on container startup via `docker/entrypoint.sh` → `docker/db-init.php`.

### Frontend
Each HTML page is self-contained with embedded `<script>` and `<style>` tags. Pages poll `api.php` on intervals for live state updates (no WebSockets).

- **index.html** — search + add to queue; enforces per-session rate limits
- **projector.html** — YouTube IFrame API embed; polls for commands from admin; fullscreen layout
- **admin.html** — queue management, reordering, ticker control, projector remote commands, admin user management
- **queue.html** — read-only queue and history viewer

### CSS Theme
CSS custom properties define the color scheme: `--accent` (lime `#e8ff47`), `--danger` (red `#ff4f47`), `--success` (green `#47ffb2`), dark backgrounds. Fonts: DM Sans, DM Mono, Syne, Bebas Neue (Google Fonts).

## API Reference

| Action | Method | Auth | Purpose |
|---|---|---|---|
| `state` | GET | — | Queue + ticker + recent history |
| `search&q=…` | GET | — | YouTube search (cached) |
| `oembed&id=…` | GET | — | Single video metadata |
| `history` | GET | — | Full play history |
| `command` | GET | — | Projector polls for pending command |
| `add` | POST | — | Add video to queue |
| `played` | POST | — | Mark video played (projector) |
| `cmd_ack` | POST | — | Projector acknowledges command |
| `remove` | POST | admin | Remove from queue |
| `reorder` | POST | admin | Move item up/down |
| `ticker` | POST | admin | Set ticker message |
| `clear` | POST | admin | Clear queue |
| `command` | POST | admin | Send projector command |
| `login` | POST | — | Verify admin credentials |
| `add_admin` | POST | admin | Create admin user |
| `remove_admin` | POST | admin | Delete admin user |
| `list_admins` | GET | admin | List admin accounts |
