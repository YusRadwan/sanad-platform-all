# SANAD Teacher — Deployment runbook

Target platform for everything below is **Linux** (paths, users, `chown`,
`logrotate`, `cron`). Local development for this project has been on Windows +
Laragon; none of the shell snippets here are meant to run there.

**This document describes the Laravel 12 backend** (`backend/composer.json` →
`"name": "sanad/backend-v2"`, `"laravel/framework": "^12.0"`). An earlier
version of this file was written against a pre-rewrite, non-Laravel backend
(hand-rolled `App\Core\Logger`, a `database/migrate.php` script, a
`RateLimitMiddleware` that swept its own JSON cache files) and had drifted
completely out of sync with the code — `composer test`/`composer lint` did
not exist, `backend/public/nginx.conf.example` did not exist, and every path
and class name below the surface had changed. Everything in this file has
been re-verified against the actual `backend/` tree as it stands today.

Scope note, stated up front so nothing in this document is mistaken for a claim
about something already running:

- **No backup system is installed.** Section 8 is *how to run* backup and
  restore — `backend/scripts/backup.sh` plus a crontab line — not a
  description of a scheduled job that already exists anywhere.
- **No log compression is configured.** Laravel's own `daily` log driver
  already handles rotation-by-date and deletion-by-age (see section 7); the
  one thing it does not do is compress old files, which is what
  `backend/scripts/sanad.logrotate` adds if you install it.
- **No monitoring/alerting is wired up.** `backend/scripts/health-check.sh`
  plus a cron line or systemd timer is the smallest thing that makes
  `GET /api/v1/health` (section 9) actually get polled and actually alert
  someone; today nothing calls it.
- **No queue worker, cron scheduler or background daemon exists in this
  codebase.** Section 6 explains what was checked.

---

## 1. Requirements

| Component | Version |
| --- | --- |
| PHP | 8.3+ (`backend/composer.json` → `"php": "^8.3"`), with `pdo_mysql`, `json`, `mbstring`, `fileinfo` |
| PHP-FPM | matching the PHP version (`php8.3-fpm`) |
| MySQL | 8.0+ — several migrations rely on `CHECK` constraints and `ENUM` being genuinely enforced, which older MySQL/MariaDB forks parse and silently ignore. |
| Nginx | any current stable |
| Node.js | 20 or 22 LTS — build machine only; production serves the compiled `frontend/dist` |
| Composer | 2.x |

## 2. Build and install

```bash
# Backend — note --no-dev: PHPUnit and its tree must not ship to production.
cd backend
composer install --no-dev --optimize-autoloader --no-interaction

# Frontend — build on a build machine/CI, ship the resulting dist/.
cd ../frontend
npm ci
npm run build      # -> frontend/dist
```

Create `backend/.env` from **`backend/.env.production.example`** (not
`.env.example`, which is the local-dev template — `APP_ENV=local`,
`APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false`). Fill in the blanks: a real
`APP_KEY` (`php artisan key:generate` after copying the file), real DB
credentials, and the real frontend origin in `CORS_ALLOWED_ORIGINS`. The two
values that matter most, because getting either wrong is a security
regression rather than a misconfiguration: `APP_DEBUG=false` (a true value
turns exception stack traces into HTTP responses) and
`SESSION_SECURE_COOKIE=true` (section 4).

Apply migrations and seed the first admin:

```bash
cd backend
php artisan migrate --force        # --force: required outside APP_ENV=local
php artisan db:seed --class=AdminUserSeeder --force
```

`AdminUserSeeder` (`database/seeders/AdminUserSeeder.php`) reads
`SEED_ADMIN_USERNAME` / `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` /
`SEED_ADMIN_FULL_NAME` from `.env` and refuses to run with a blank password —
set it in the real, uncommitted `.env`, never in a template file.

## 3. Document root — this is a security boundary, not a preference

**The Nginx `root` MUST be `backend/public/` and nothing above it.**

The entire secure file-serving design depends on it. `backend/storage/uploads`
sits *outside* `backend/public`, so the web server physically cannot reach an
uploaded file. Every uploaded byte is read back through PHP instead, after an
access decision runs (`backend/app/Http/Controllers/FileController.php`):

- `GET /api/v1/files/{category}/{id}` — public imagery only (avatars, banners,
  course banners, question images, video thumbnails), category checked against
  a `visibility='public'` allow-list.
- `GET /api/v1/content-items/{id}/file` — private educational files. Requires
  auth **and** an active subscription / course ownership / admin.

Point the root one level higher (at `backend/`) and every one of those checks
is bypassable by requesting `/storage/uploads/...` directly. That is a full
regression of the upload security design.

Note also that the public file URL carries a **bare UUID with no extension**.
That is deliberate: a `.png` suffix would be claimed by static-file handling
(`try_files`) ahead of the front controller, reintroducing exactly the direct
serving this design removes. See `App\Actions\Upload\StoreUpload::urlFor()`
for the full reasoning.

### Nginx + PHP-FPM server block

**`docs/deployment/nginx.conf.example`** is the canonical, current example —
copy it rather than re-deriving one. It already contains: the correct `root`,
`try_files` to the front controller, `fastcgi_pass` to
`unix:/run/php/php8.3-fpm.sock`, the plain-HTTP→HTTPS redirect, the TLS server
block, the six baseline security headers (including HSTS) repeated at the
web-server layer so Nginx-generated error pages carry them too, the dotfile
deny rule, `fastcgi_param HTTPS on;` (what actually makes `Request::isSecure()`
and therefore HSTS/secure-cookies work correctly — see section 4), and a
defence-in-depth `location ~ ^/(storage|uploads)/ { deny all; return 404; }`
in case a future deployment misplaces the root.

Remember the Nginx inheritance rule already flagged in the example file:
`add_header` directives are **not** merged — a `location` that declares its
own `add_header` discards every one from the enclosing block. If you add
headers inside a location, repeat the baseline six there too.

## 4. HTTPS and HSTS are mandatory

`backend/.env.production.example` ships `SESSION_SECURE_COOKIE=true`. **Keep
it true in production.** With `Secure` set, the browser refuses to send the
session cookie over plain HTTP, which is the point.

`App\Http\Middleware\SecurityHeaders` also emits
`Strict-Transport-Security: max-age=31536000; includeSubDomains` on every
response the request-level check (`$request->isSecure()`) sees as HTTPS.
`preload` is deliberately not set — submitting the real domain to the HSTS
preload list is a one-way, hard-to-reverse operation and must be a conscious,
separate step, not a default baked into application code.

Both of those depend on Nginx actually setting `fastcgi_param HTTPS on;`
inside the TLS server block (already present in `nginx.conf.example`) —
without it, PHP has no way to know the connection was HTTPS (this app does
not configure Laravel's `TrustProxies` to trust any proxy header, which is
the correct default for a PHP-FPM deployment: no client-controlled header can
forge "this request was secure").

Running production over plain HTTP would be a real security regression, not a
configuration convenience: the session id and the CSRF cookie would travel in
clear text on every request, making session hijacking a passive-network
attack.

Related, and also load-bearing: `SESSION_SAME_SITE=lax` must stay `lax`.
`none` re-enables the cross-site cookie sending that the CSRF double-submit
scheme (`App\Http\Middleware\VerifyCsrfToken`) defends against.

## 5. Storage permissions

Directories that must be writable by the PHP-FPM user (`www-data` on
Debian/Ubuntu):

| Directory | Written by |
| --- | --- |
| `backend/storage/logs` | Laravel's `daily` log driver (`app-YYYY-MM-DD.log`, `security-YYYY-MM-DD.log`) |
| `backend/storage/framework/cache` | the `file` cache driver (rate-limit counters, general cache) |
| `backend/storage/framework/sessions` | the `file` session driver |
| `backend/storage/uploads` | `App\Actions\Upload\StoreUpload` |
| `backend/storage/framework/views` | Blade view compilation cache |
| `backend/bootstrap/cache` | config/route/event cache (`artisan config:cache` etc.) |

```bash
cd /var/www/sanad-teacher-all/backend

# Application code owned by a deploy user, readable (not writable) by the web server.
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;

# Only storage/ and bootstrap/cache are writable by the web server user.
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 770 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 660 {} \;

# .env holds DB credentials: readable by the web server, by nobody else.
sudo chown deploy:www-data .env
sudo chmod 640 .env
```

Uploads must never be executable and must never be interpreted as PHP — both
`nginx.conf.example`'s `location ~ ^/storage/.*\.php$ { deny all; }` and the
`chmod` values above (no `x` bit on any stored file) enforce this.

For a production-grade Laravel install, also run the framework's own caches
once after every deploy (regenerate them, don't skip on subsequent deploys —
a stale route cache serves the previous release's routes):

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 6. Scheduled jobs — what actually exists

**Checked and confirmed: this codebase has no queue worker, no
`php artisan schedule:run` entry in `routes/console.php`, and no background
daemon.** Every operation runs synchronously inside the HTTP request that
triggered it. Nothing needs to be added to `crontab` for the application
itself to function.

Two housekeeping mechanisms are **already self-cleaning, no cron needed**:

- **Session files** (`SESSION_DRIVER=file`, `storage/framework/sessions`).
  `config/session.php`'s `'lottery' => [2, 100]` means Laravel's own
  `FileSessionHandler::gc()` runs on ~2% of requests and deletes files past
  `SESSION_LIFETIME`.
- **Log retention.** Laravel's `daily` driver (`config/logging.php`) deletes
  files older than `LOG_DAILY_DAYS` / `LOG_SECURITY_DAILY_DAYS` itself — see
  section 7 for the one thing it still leaves for logrotate.

The two things that genuinely need external scheduling — because they are
operational concerns Laravel has no opinion on, not because anything is
broken without them — are backup (section 8) and the health-check probe
(section 9), both wired up via `backend/scripts/crontab.example`.

## 7. Log rotation — what Laravel already does, and what's left

`config/logging.php` writes two channels, both `daily`:
`storage/logs/app-YYYY-MM-DD.log` and `storage/logs/security-YYYY-MM-DD.log`
(180-day retention recommended for `security-*` in
`.env.production.example` — keep it longer than `app-*` if your retention
policy has an audit requirement, since it is auth/authorization events).
Rotation-by-date and deletion-by-age are both handled by Laravel itself; no
cron or logrotate entry is required for either.

The one thing left undone is **compression** — each day's file sits
uncompressed until Laravel deletes it. `backend/scripts/sanad.logrotate` adds
`compress`/`delaycompress` on top of the existing daily files. Install it:

```bash
sudo cp backend/scripts/sanad.logrotate /etc/logrotate.d/sanad
sudo logrotate -d /etc/logrotate.d/sanad   # dry run — test before trusting it
```

## 8. Backup and restore

`backend/scripts/backup.sh` dumps the database and archives `storage/uploads`
together — they must be restored together, since a `content_items` row
referencing an upload missing from disk is a broken content item.

```bash
# One-off run:
BACKUP_DIR=/var/backups/sanad DB_NAME=sanad_platform_v2_production \
  backend/scripts/backup.sh

# Scheduled: see backend/scripts/crontab.example for the full crontab line
# (nightly at 03:00, 30-day local retention by default).
```

It expects DB credentials in `~/.my.cnf` (`chmod 600`), never on the command
line — that would be visible in `ps` and shell history.

### Restore

```bash
# Restore into a FRESH database. Never restore over a live one you have not
# dumped first.
mysql --defaults-extra-file=~/.my.cnf -e \
  "CREATE DATABASE sanad_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip < /var/backups/sanad/db-2026-08-31-0300.sql.gz | \
  mysql --defaults-extra-file=~/.my.cnf sanad_restore

tar -xzf /var/backups/sanad/uploads-2026-08-31-0300.tar.gz \
  -C /var/www/sanad-teacher-all/backend/storage
sudo chown -R www-data:www-data /var/www/sanad-teacher-all/backend/storage/uploads
```

Do **not** back up `storage/framework/sessions` (restoring it resurrects dead
sessions) or `storage/framework/cache`/`storage/framework/views` (regenerated
on demand). `.env` should be handled by your secret management, not by a file
backup that lands in the same archive as the database.

**Untested restores are not backups.** Periodically restore into a scratch
database and run `php artisan migrate --database=<scratch>` against it to
confirm the dump is usable.

Off-host copies (rsync to a backup host, S3, etc.) are deliberately left to
the deployer — `backup.sh` only handles the local dump/archive/prune step.

## 9. Health check and monitoring

`GET /api/v1/health` exists and is live
(`backend/app/Http/Controllers/HealthController.php`, registered in
`backend/routes/api.php`). It is unauthenticated by design — an external
monitor or a load-balancer probe has no session — and returns no diagnostic
detail whatsoever: no version, no hostname, no driver information, and never
an exception message. The check itself is a `SELECT 1`, which touches no
application table and takes no lock.

```bash
$ curl -s http://127.0.0.1:8000/api/v1/health
{"success":true,"message":"OK","data":{"status":"ok","database":true}}
```

If the database is unreachable it responds **HTTP 503** with
`{"success":false,"message":"الخدمة غير متاحة حالياً","data":{"status":"degraded","database":false}}`,
so a probe can distinguish "PHP is up but the DB is gone" from a total outage.

`nginx.conf.example` already routes it with `access_log off` so a monitor
polling every few seconds doesn't fill the access log with noise.

`backend/scripts/health-check.sh` is the smallest thing that turns this
endpoint into actual monitoring: it curls the endpoint, and on anything other
than a 200 posts to `ALERT_WEBHOOK_URL` (works as-is with a Slack/Discord/
Mattermost incoming webhook) and exits non-zero. Wire it up with the cron line
in `backend/scripts/crontab.example` (every 5 minutes by default), a systemd
timer, or point an external uptime service (UptimeRobot, Better Uptime, etc.)
directly at the URL instead — the script exists for setups that would rather
not depend on a third-party service.

## 10. Post-deploy smoke test

```bash
curl -s https://api.example.com/api/v1/health          # {"success":true,...,"database":true}
curl -si https://api.example.com/api/v1/settings | head -20
#   -> 200, and the six security headers must be present:
#      Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options,
#      Referrer-Policy, Permissions-Policy, Content-Security-Policy
curl -si https://api.example.com/storage/uploads/anything    # -> 404, never a file
curl -si https://api.example.com/.env                        # -> 403/404, never contents
```

The third and fourth checks are the ones worth failing a deploy over: either
one returning content means the document root is wrong (section 3).

## 11. CI

`.github/workflows/ci.yml` runs two independent jobs (backend has no Node
dependency, frontend has no PHP dependency) on every push/PR:

- **Backend**: `composer install`, `composer lint` (a plain `php -l` syntax
  sweep over every tracked `.php` file — `backend/scripts/lint.php`),
  `composer test` (the `Unit` PHPUnit suite only — no MySQL service in CI;
  `backend/tests/Feature` needs a real database and is intentionally not run
  there), `composer audit`.
- **Frontend**: `npm ci`, `npm run lint` (`tsc --noEmit`), `npm test`
  (`vitest run`), `npm run build`, `npm audit --audit-level=high` (hard gate)
  then `npm audit --audit-level=low` (report only, never fails the build).

Everything CI runs is runnable locally with the exact same commands — nothing
here is CI-only. To run the full suite including `Feature` (needs a real
`sanad_platform_v2_test` MySQL database — see `backend/phpunit.xml`):

```bash
cd backend
composer test:all      # Unit + Feature
composer test:feature  # Feature only
```
