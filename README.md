# Retail POS

A multi-tenant, multi-branch retail POS for Bangladesh with an offline-first register. Built with Laravel 13, Inertia, React 19, TypeScript, Tailwind CSS 4 and MySQL 8.4.

## Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP ^8.3), Fortify (2FA, passkeys, email verification) |
| Frontend | Inertia React 19 + TypeScript, Tailwind CSS 4, Radix UI |
| Database | MySQL 8.4 (utf8mb4), Redis 7 (optional queue/cache) |
| Authz | Spatie Permission (teams = `tenant_id`) + Policies + Gates |
| Misc | Sanctum, Activity Log, Backups, Telescope/Pulse (dev), Sentry (optional) |
| Browser | Hind Siliguri + Poppins; `vp` (vite-plus) builds |

## Project structure

```
app/                   Laravel app
app/Models/            Eloquent models (tenant/branch scoped)
app/Http/Controllers/  Web controllers
app/Actions/           Domain actions (Users, Sales, Tenancy …)
app/Policies/          Authorization policies
app/Support/           Permissions catalogue, tenancy, audit helpers
database/migrations/   20+ migrations
database/seeders/      Roles/permissions, Geo, DifferentFashion, E2e
resources/js/          React frontend (Inertia pages, components, pos/)
routes/app/            Feature routes (sales, stock, messaging …)
tests/                 Pest feature + arch + unit tests, e2e (Playwright)
docs/                  PRD, AppFlow, TRD, Schema, Plan …
```

## Prerequisites

- PHP ^8.3 (`composer` 2), Node 20+ / `npm`, Docker (for MySQL 8.4 + Redis)
- MySQL 8.4 reachable on `127.0.0.1:33084` (or set `DB_*` to your own)

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d          # MySQL 8.4 and Redis
php artisan migrate:fresh --seed
composer dev                  # app server, queue worker and Vite
```

Open http://localhost:8000 (or `php artisan serve --host=127.0.0.1 --port=8000`).

### Seeded logins

| Account | Email / username | Password | Access |
|---|---|---|---|
| Different Fashion Owner | `owner@differentfashion.test` | `abc123AB` | `/app` — both branches, all permissions |
| Platform admin | `admin@differentfashion.test` | `abc123AB` | `/platform` — cross-tenant console |
| Managers | `Different -1` / `Different -2` | `abc123AB` | one branch each |
| Demo store (local/testing only) | `owner@demo.test` | `password` | not seeded on shared/prod servers |
| Demo admin | `admin@demo.test` | `password` | not seeded on shared/prod servers |

> **Never run `grep -R "abc123AB" . --exclude-dir=vendor`** — demo logins are local-only and stripped from shared deploys.

## Configuration

All in `.env` (see `.env.example`):

- `APP_URL` — public URL (used for share links/QR).
- `DB_*` — MySQL (`DB_PORT=33084` matches `compose.yaml`).
- `SESSION_COOKIE=pos_session`, `SESSION_DRIVER=database`.
- `BACKUP_ARCHIVE_PASSWORD` — encrypts installation backups.
- `LEGACY_DB_*` — read-only legacy POS dump for `php artisan legacy:import`.

## Testing

```bash
composer test        # Pest (feature + arch + unit)
composer lint        # Pint + Rector (dry run)
composer analyse     # PHPStan / Larastan
npm run test         # Vitest (unit + js)
npm run e2e          # Playwright (needs `pos_e2e` DB + `E2eSeeder`)
```

Areas only (faster): `php artisan test tests/Feature/Admin/RolesAdminTest.php`.
Run the full suite only when everything is done.

## Scripts

| Script | What it does |
|---|---|
| `composer setup` | `composer install`, `key:generate`, `migrate --force`, `npm install && npm run build` |
| `composer dev` | `php artisan dev` (server + queue + Vite) |
| `composer ci` | lint + analyse + test + frontend lint/typecheck/test/build + audits |
| `npm run build` | production build (`vp build`) |
| `npm run dev` | Vite dev server (`vp dev`) |

## Deployment

1. Clone to the target host (e.g. `/var/www/html/different-pos`).
2. Copy `.env.example` → `.env`, set `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_*`, `BACKUP_ARCHIVE_PASSWORD`.
3. `composer install --no-dev -O` + `npm install && npm run build`.
4. `php artisan migrate --force && php artisan permissions:sync`.
5. `php artisan route:clear && php artisan config:clear`.
6. Point the web server (Apache/Nginx) at `public/`.

Different Fashion is the live tenant (`different-fashion` slug, DF1/DF2 branches from `DatabaseSeeder`). A separate test instance runs on `http://<ip>:8899` with its own database `different_pos_test` (cloned data), its own checkout at `different-pos-test`, and a vhost on 8899. Its `.env` uses the same app key as live on purpose so sessions share crypto; never run `key:generate` there or logins will 419.

> **A4 printing default:** `branches.settings.paper` defaults to `a4` (A4 tax invoice); a branch can choose 80/58 mm in Settings → Branches. Sales History / Returns / Dues print the branch default; explicit buttons and the print-page toolbar still override per-print.

## Docs

| Doc | What it covers |
|---|---|
| [00_Legacy_Features.md](docs/00_Legacy_Features.md) | What the old POS does, parity status, legacy data model, defects |
| [01_PRD.md](docs/01_PRD.md) | Problem, personas, user stories, metrics, non-goals |
| [02_AppFlow.md](docs/02_AppFlow.md) | Shell, keyboard map, context menus, routes, POS/offline state machines |
| [03_UI_Spec.md](docs/03_UI_Spec.md) | Tokens, colour schemes, density, components |
| [04_TRD.md](docs/04_TRD.md) | Stack, architecture, offline sync, security, quality gates |
| [05_Schema.md](docs/05_Schema.md) | DB schema, invariants, auth/device flow, legacy migration |
| [06_Plan.md](docs/06_Plan.md) | Ordered checklist, open questions, progress log |

## License

MIT
