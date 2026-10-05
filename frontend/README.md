# Sanad Platform — Frontend

React 19 + TypeScript + Vite SPA for a multi-teacher educational platform
(Super Admin, Teacher, Student).

## The backend exists

This directory is the **frontend half** of a two-part repository:

```
sanad-teacher-all/
  backend/     PHP 8.3, no framework. PSR-4 (Sanad\Backend\ -> app/), PDO + prepared
               statements only (no ORM). Repository -> Service -> Controller ->
               Middleware layering. Serves the REST API under /api/v1/*.
  frontend/    this directory.
```

The API this SPA calls is **real and running in this repository** — see
`backend/routes/api.php` for the full route table. Earlier revisions of this
file said the backend was "planned separately and not part of this codebase";
that is no longer true.

### Authentication is sessions + cookies, NOT JWT

There is no JWT anywhere in this project — not in the frontend, not in the
backend. Do not add one, and do not assume a bearer token when reading the code.
The actual scheme is:

1. **PHP session** on the server (`SessionMiddleware`), with the session id in an
   **HttpOnly** cookie (`sanad_session`). JavaScript cannot read it, so an XSS
   payload cannot exfiltrate the session.
2. **CSRF double-submit**: the server also sets a second, *non*-HttpOnly cookie
   (`sanad_csrf`). `src/services/api.ts` reads it and echoes it back on every
   mutating request in the `X-CSRF-Token` header; `CsrfMiddleware` rejects the
   request unless header and cookie match. An attacker's site can *cause* a
   cross-site request but cannot *read* the cookie to set the header.
3. **Role checks** (`RoleMiddleware`) and per-resource ownership/subscription
   checks live in the services on the server. Any client-side role check in this
   SPA is UX only and is re-enforced server-side.

## Requirements

| Tool | Version | Where the requirement comes from |
| --- | --- | --- |
| PHP | **8.3+** | `backend/composer.json` → `"php": "^8.3"` (local dev runs 8.3.30) |
| Composer | 2.x | `backend/composer.lock` is a v2 lockfile |
| MySQL | **8.0+** (dev runs 8.4.3) | Migration `0015` relies on genuinely enforced `CHECK` constraints, which MySQL only honours from 8.0.16 onward |
| Node.js | **20 LTS or 22 LTS** (dev runs 24.x) | Not pinned in `package.json`; Vite 6 requires Node 18+, and CI runs Node 22 (`.github/workflows/ci.yml`) |

## Dev setup — two commands, two terminals

**Terminal 1 — backend** (from `backend/`):

```bash
composer install
cp .env.example .env      # then edit: APP_ENV=local, APP_DEBUG=true, SESSION_SECURE=false, APP_URL=http://localhost:8000
php database/migrate.php  # applies migrations 0001 .. 0015
php -S 127.0.0.1:8000 -t public
```

**Terminal 2 — frontend** (from `frontend/`):

```bash
npm install
npm run dev               # http://localhost:3000
```

Open **http://localhost:3000** (not :8000) — the SPA is served by Vite and the
API calls are proxied through it.

### Why the Vite proxy is required (not optional)

`vite.config.ts` proxies `/api` → `http://localhost:8000`. This mirrors the
reasoning documented in the code comments there:

- `src/services/api.ts` uses a **relative** base URL (`/api/v1`) so that *in
  production* the SPA and the API are served from the same origin with no proxy
  and no CORS at all. In dev, Vite is on :3000 and PHP is on :8000, so without
  the proxy every API call would hit Vite and get `index.html` back — never
  reaching PHP.
- Forwarding through Vite (rather than pointing `api.ts` at
  `http://localhost:8000`) keeps the browser's origin **identical for page and
  API**, which is what makes the cookie scheme above work:
  - no cross-origin preflight on every mutating request;
  - the session cookie (`SESSION_SAMESITE=Lax`) and the readable CSRF cookie are
    both treated as first-party. A cross-origin dev setup would need
    `SameSite=None; Secure`, which does not work over plain `http://`.
- It also means dev and production exercise the exact same code path.

Override the backend location with `VITE_BACKEND_URL` if PHP runs elsewhere.

## Database

- **Migrations**: `php database/migrate.php` from `backend/`. Files are
  `backend/database/migrations/0001_*.sql` … `0015_*.sql`; the runner applies
  pending ones in order.
- **Admin seeder**: `php database/seeders/seed_admin.php` from `backend/` creates
  the initial super-admin account. Run it once after the first migration.

## Environment variables

`backend/.env.example` is a committed **template and contains no real secrets** —
copy it to `backend/.env` (which is git-ignored) and fill in real values there.
Its defaults are the production-safe ones; relax them for local dev.

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | `production` / `local`. Selects environment-dependent behaviour. |
| `APP_DEBUG` | When true, exceptions become HTTP responses with stack traces. Must be false in production. |
| `APP_URL` | Public base URL of the API. |
| `APP_KEY` | Application secret; set to a long random string per environment. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET` | MySQL connection for the PDO handle (`utf8mb4`). |
| `SESSION_NAME` | Name of the HttpOnly session cookie. |
| `SESSION_LIFETIME` | Session lifetime in seconds. |
| `SESSION_SECURE` | Marks the session cookie `Secure`. **Must stay `true` behind HTTPS**; only set false for plain-http local dev. |
| `SESSION_SAMESITE` | `Lax` — correct here because the browser only ever talks to one origin. `None` would undo the CSRF protection. |
| `SESSION_DOMAIN` | Empty = host-only cookie. Setting a parent domain shares the session with every subdomain. |
| `SESSION_SAVE_PATH` | Where PHP writes session files; defaults to `storage/sessions` so sessions are isolated from a shared system `/tmp`. |
| `CORS_ALLOWED_ORIGINS` | Only relevant when a browser talks to PHP directly, bypassing the dev proxy. |
| `CORS_ALLOW_CREDENTIALS` | Whether credentialed cross-origin requests are permitted. |
| `CSRF_COOKIE_NAME`, `CSRF_HEADER_NAME` | The two halves of the double-submit pair (`sanad_csrf` / `X-CSRF-Token`). |
| `UPLOAD_MAX_SIZE_MB` | Per-file upload size cap. |
| `UPLOAD_ALLOWED_MIME` | Upload MIME allow-list (SVG is deliberately excluded). |
| `RATE_LIMIT_ENABLED` | Master switch for the file-based rate limiter; per-endpoint buckets are tuned in `backend/config/ratelimit.php`. |

Log paths are intentionally **not** env-configurable: application and security
logs always go to `backend/storage/logs/app-YYYY-MM-DD.log` and
`security-YYYY-MM-DD.log`.

## Available scripts

Run from this directory.

| Command | What it does |
| --- | --- |
| `npm run dev` | Vite dev server on port 3000 with the `/api` proxy. |
| `npm run build` | Production bundle into `dist/`. Vendor code is split into `react-vendor` / `motion-vendor` / `charts-vendor` chunks (see `vite.config.ts`). |
| `npm run lint` | `tsc --noEmit` type-check. |
| `npm test` | `vitest run` — unit tests (`src/**/*.test.ts`), config in `vitest.config.ts`. |
| `npm run clean` | Removes `dist/`. |

**Do not run `npm run build` while `npm run dev` is running.**

Backend equivalents, from `backend/`: `composer lint` (`php -l` across the
project) and `composer test` (PHPUnit unit suite — see `backend/tests/README.md`).

## Continuous integration

`.github/workflows/ci.yml` runs both halves on every push/PR: Composer install →
`php -l` → PHPUnit → `composer audit` for the backend, and `npm ci` →
`tsc --noEmit` → Vitest → `npm run build` → `npm audit` for the frontend.

**npm audit policy**: HIGH and CRITICAL advisories fail the build; moderate and
low advisories are reported in the job log but do not block, because an advisory
with no published fix in a transitive dev dependency would otherwise block every
CI run indefinitely. The policy is restated in comments in the workflow file.

## Deployment

See **`docs/DEPLOYMENT.md`** for the production runbook: Nginx + PHP-FPM server
block, the mandatory `backend/public/` document root, storage permissions, HTTPS
requirements, log rotation, and backup/restore procedures.
