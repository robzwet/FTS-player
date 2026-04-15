# Video Queue — Setup Guide (MariaDB edition)

## Files
```
config.php       ← Your settings: DB credentials, password, API key
setup.php        ← Run once in browser to create DB tables
api.php          ← Backend API — all data goes through here
index.html       ← User page (search & add videos) — QR code target
projector.html   ← Fullscreen display for the projector
admin.html       ← Password-protected admin panel
```

---

## Requirements
- PHP 7.4+ (8.0+ recommended)
- MariaDB 10.3+ (or MySQL 5.7+)
- PHP extensions: `pdo_mysql` (enabled by default on most hosts)

---

## Step-by-step Setup

### 1. Create a database and user

Log into your MariaDB/MySQL server and run:

```sql
CREATE DATABASE videoqueue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'vquser'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL PRIVILEGES ON videoqueue.* TO 'vquser'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Edit config.php

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'videoqueue');
define('DB_USER', 'vquser');
define('DB_PASS', 'a-strong-password');

define('ADMIN_PASSWORD', 'your-admin-password');
define('YT_API_KEY', '');        // optional — see below
define('MAX_PER_IP', 3);         // max videos per user in queue at once
define('COOLDOWN_SECONDS', 1800); // 30 min before same video can be re-queued
```

### 3. Upload all files to your server

All files must be in the same folder.

### 4. Run setup.php

Open `https://yoursite.com/setup.php` in your browser.

You should see all green checkmarks. If not, check your DB credentials.

**Delete or rename `setup.php` after setup** — it's no longer needed.

### 5. You're live!

| Page | Who uses it |
|------|-------------|
| `projector.html` | Open fullscreen (F11) on the projector |
| `index.html` | Users scan the QR code to reach this |
| `admin.html` | You — to manage the queue |

---

## YouTube API Key (optional)

Without a key, users can still add videos by pasting a YouTube URL.
To enable keyword search:

1. Go to https://console.cloud.google.com
2. Create a project → enable **YouTube Data API v3**
3. Create an API key → paste it in `config.php` as `YT_API_KEY`

---

## Database Schema

### `queue` table
Holds videos currently waiting to play.
Automatically cleared as videos are played.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Auto-increment primary key |
| video_id | VARCHAR(11) | YouTube video ID |
| title | VARCHAR(200) | Video title |
| channel | VARCHAR(100) | Channel name |
| duration | VARCHAR(20) | Duration string |
| added_by | VARCHAR(45) | SHA-256 hash of submitter IP |
| added_at | DATETIME | When it was added |
| position | SMALLINT | Order in the queue |

### `history` table
Permanent log of every video that played. Never auto-cleared.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Auto-increment primary key |
| video_id | VARCHAR(11) | YouTube video ID |
| title / channel / duration | — | Copied from queue at play time |
| added_by | VARCHAR(45) | Submitter IP hash |
| added_at | DATETIME | When it was originally queued |
| played_at | DATETIME | When it actually played |

### `settings` table
Simple key/value store. Currently stores the ticker message.

---

## How It Works

```
User (phone)        Admin (laptop)       Projector (TV/screen)
     │                    │                      │
     ▼                    ▼                      ▼
  index.html          admin.html           projector.html
     │                    │                      │
     └────────────────────┴──────────────────────┘
                          │
                       api.php
                          │
                       MariaDB
                    (queue / history / settings)
```

- Projector polls `api.php` every 4 seconds for queue changes
- When a video ends, projector POSTs `action=played` → row moves from `queue` to `history`
- Admin can reorder, remove, set the ticker, and view full play history + stats
- Per-IP limits and cooldowns prevent spamming (configurable in `config.php`)
