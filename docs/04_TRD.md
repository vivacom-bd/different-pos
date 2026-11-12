# 04 — Technical Requirements Document (TRD)

> This document **locks** the stack. Do not add, swap or upgrade a dependency without updating this file in the same change. Anything in §1–§2 also needs human approval.
> **Version rule.** The constraints below are the intended lines. At Phase 0, write the **exact resolved versions** into the "Pinned" column, and those pins become law. If a listed package has **no release supporting Laravel 13 / PHP 8.4 / React 19**, STOP and ask. Never substitute silently.

---

## 0. Why React (decision record)
React 19 was chosen over Vue for this product because:
- the official Laravel React starter kit and **shadcn/ui** (first-class on React) cover most of the UI
- **Radix ContextMenu** and **cmdk** give the desktop-style right-click and command palette
- **Dexie `useLiveQuery`** binds IndexedDB reactively for the offline register
- **TanStack Table/Virtual** handle large keyboard-navigable grids

Both frameworks could do the job. This choice minimises custom plumbing for keyboard-first + offline-first.

## 1. Runtime & infrastructure

| Component | Constraint | Pinned | Notes |
|---|---|---|---|
| PHP | `^8.3` (Composer platform pinned to 8.3 because the dev server runs 8.3; owner decision 2026-11-04) | 8.5.12 (dev), 8.3 (dev server) | ext: `bcmath intl mbstring pdo_mysql redis gd zip exif pcntl sodium` |
| Laravel | `^13.0` | 13.39.0 | |
| Composer | `>= 2.8` | 2.11.1 | |
| Node.js | 24 LTS | 24.22.2 (via nvm) | npm, `package-lock.json` committed |
| MySQL | 8.4 LTS | 8.4.13 (dev: Docker, `compose.yaml`) | utf8mb4_0900_ai_ci, strict, UTC |
| Redis | 7.4 (or Valkey 8) | 7.4.12 (dev: Docker). Dev cache/queue use the `database` driver until `ext-redis` is installed | cache, queue, locks, rate limits |
| Web | Nginx + PHP-FPM | | HTTP/2, brotli. The service worker is served with `Cache-Control: no-cache` |
| OS (prod) | Ubuntu 24.04 LTS | | |
| Object storage | S3-compatible (e.g. DigitalOcean Spaces) | | private bucket + signed URLs |
| Supervisor | Horizon + scheduler | | |
| Local dev | native PHP 8.5 + Node 24, with MySQL 8.4 + Redis 7.4 in Docker (`compose.yaml`, ports 33084/63840). Tests run on MySQL (`pos_testing`), never SQLite | | |
| VCS | git (local `git init`). Remote/CI host decided later by the owner | | CI is provider-agnostic via `composer ci` (§6) |

## 2. Application dependencies

> **Re-baselined 2026-10-31 with owner approval:** the Laravel 13 starter kit ships newer majors than first planned. Changes: Inertia 2→**3**, Pest 4→**5**, spatie/laravel-permission 6→**8**, activitylog 4→**5**, backup 9→**10**, brick/money 0.10→**0.15**, brick/math 0.12→**1.0**, chillerlan/php-qrcode 5→**6**, and the front-end toolchain is **Vite+** (`vite-plus`: Vite + Oxlint + Oxfmt + Vitest + type check) instead of separate ESLint/Prettier/Vitest. Pins below are the resolved versions (the lock files are authoritative).

### 2.1 Composer (require)
| Package | Pinned | Purpose |
|---|---|---|
| `laravel/framework` | 13.39.0 | core |
| `inertiajs/inertia-laravel` | 3.5.2 | back-office SPA bridge (ADR 0001) |
| `laravel/fortify` | 1.41.0 | auth backend (starter kit; includes passkeys + 2FA) |
| `laravel/sanctum` | 4.3.3 | **device tokens** for `/api/pos/v1` |
| `laravel/wayfinder` | 0.1.23 | typed TS route helpers |
| `laravel/horizon` | 5.50.1 | queues (prod, needs `ext-redis`) |
| `laravel/pulse` | 1.8.1 | perf monitoring (gated) |
| `spatie/laravel-permission` | 8.3.2 | roles/permissions, teams = tenant |
| `spatie/laravel-activitylog` | 4.12.3 (5.x needs PHP 8.4) | audit |
| `spatie/laravel-backup` | 10.3.3 | backups |
| `spatie/simple-excel` | 3.11.0 | XLSX streaming (`App\Support\Export\XlsxWriter`: typed money/qty/date cells); CSV stays `ReportCsv` |
| `mpdf/mpdf` | 8.3.1 (`^8.3`), installed 2026-11-03 | Bangla-shaped PDFs (`App\Support\Pdf\PdfRenderer`, Hind Siliguri in `resources/fonts/`, ADR 0009). Needs `ext-gd` |
| `brick/money` · `brick/math` | 0.15.2 · 1.0.0 | money / decimal qty |
| `picqer/php-barcode-generator` | 3.3.0 | Code128/EAN-13 in PDFs |
| `chillerlan/php-qrcode` | 6.0.1 | QR codes: the only QR library (`App\Support\Qr\QrSvg`, used by receipts and membership cards). `bacon/bacon-qr-code` arrives transitively through Fortify 2FA and must not be used by app code |
| `league/flysystem-aws-s3-v3` | 3.35.3 | S3 |
| `sentry/sentry-laravel` | 4.29.0 | errors |
| starter-kit extras | `laravel/chisel` 0.1.1, `laravel/tinker` 3.0.2 | kept as shipped |

### 2.2 Composer (dev)
`pestphp/pest` 4.7.8 + `pest-plugin-laravel` 4.1.0 + `pest-plugin-drift` 4.1.0 · `larastan/larastan` 3.13.2 (level 8) · `laravel/pint` 1.32.1 · `rector/rector` 2.8.3 · `laravel/telescope` 5.26.0 (local only, not auto-discovered) · `fakerphp/faker` 1.24.1 · `mockery/mockery` 1.6.15 · `nunomaduro/collision` 8.9.5 · starter-kit: `laravel/pail`, `laravel/pao`, `laravel/sail` (unused; native dev).

### 2.3 npm
| Package | Pinned | Purpose |
|---|---|---|
| `react` · `react-dom` | 19.3.1 | UI |
| `@inertiajs/react` · `@inertiajs/vite` | 3.9.1 | back-office bridge |
| `typescript` | 5.9.3 | strict (`strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`) |
| `vite-plus` (+ `vite` → `@voidzero-dev/vite-plus-core`) | 1.4.0 | build, dev server, **lint (Oxlint), format (Oxfmt), test (Vitest), check** via `vp` |
| `laravel-vite-plugin` · `@vitejs/plugin-react` · `babel-plugin-react-compiler` | 3.2.0 · 6.1.2 · 1.0.0 | build (React Compiler on) |
| `tailwindcss` · `@tailwindcss/vite` | 4.3.3 | styling |
| shadcn/ui + `@radix-ui/*`, `class-variance-authority`, `clsx`, `tailwind-merge`, `tw-animate-css` | starter kit | components |
| `@radix-ui/react-context-menu` · `@radix-ui/react-popover` | 2.4.1 · 1.3.1 | row context menu (D3.1), theme/notification/date-range popovers (added 2026-11-03) |
| `cmdk` | 1.1.1 | command palette |
| `lucide-react` | 0.475.0 | icons |
| `@tanstack/react-table` · `@tanstack/react-virtual` | 9.3.1 · 3.14.24 | data grids, virtualised POS lists |
| `react-hotkeys-hook` | 5.3.3 | scoped shortcuts |
| `zustand` | 5.0.16 | POS UI state |
| `dexie` · `dexie-react-hooks` | 4.4.7 · 4.4.0 | IndexedDB (offline register) |
| `minisearch` | 7.2.0 | local search |
| `vite-plugin-pwa` | 2.0.0 | service worker |
| `libsodium-wrappers` | 0.8.4 | sealed-box customer PIN |
| `react-i18next` · `i18next` | 17.1.9 · 26.5.12 | EN/BN |
| `recharts` | 3.10.1 | charts |
| `jsbarcode` · `qrcode` | 3.12.3 · 1.5.4 | receipt barcode/QR |
| `@zxing/browser` | 0.2.1 | camera scan |
| `uuid` | 14.0.3 | UUIDv7 |
| `sonner` | 2.0.8 | toasts |
| `@fontsource/poppins`, `@fontsource/hind-siliguri`, `@fontsource/jetbrains-mono` | 5.3.0 | self-hosted fonts. Poppins (400/500/600/700) replaces Inter for Latin UI text: Inter made 0 and 8 look alike to users (2026-11-12) |
| `@sentry/react` | 11.10.0 | client errors |
| dev: `@testing-library/react` 16.3.3, `fake-indexeddb` 6.2.7, `jsdom` 30.1.3, `@playwright/test` 1.64.1, `@types/*` | | tests/e2e |

Lint rules required by §5 are Oxlint rules: `react/no-danger` (bans `dangerouslySetInnerHTML`), `react-hooks/*`, `jsx-a11y/*`, and `no-restricted-imports` for `@inertiajs/*` under `resources/js/pos/` (ADR 0001).

**Starting point:** `laravel new --react --pest` → **React starter kit** (Inertia 3 + React 19 + TS + Tailwind 4 + shadcn/ui + Fortify + Vite+). Remove public registration.

## 3. Architecture

### 3.1 Style
- **Modular monolith**: one Laravel app.
- **Back office = classic Inertia (server-driven, online).**
- **POS register = a standalone local-first React SPA inside the same app** (own Vite entry `resources/js/pos/main.tsx`, static Blade shell at `/pos`, **not** an Inertia page). It reads and writes IndexedDB and talks only to `/api/pos/v1` with a device token (§3.9). Devices enroll with a one-time pairing code issued in the back office. Rationale: [ADR 0001](adr/0001-application-architecture.md).
- `/api/pos/v1` is the only versioned API contract. Request/response JSON fixtures in `tests/fixtures/pos-api/` run in both Pest and Vitest. Old clients get `426 Upgrade Required`.
- Arch tests: `Actions\*` must not depend on `Http\*`; the only route exempt from the "every action authorizes" rule is the static `pos.index` shell.
- **Every state-changing business operation is one final Action class** (`handle()`), run in `App\Support\Database\Transaction::run()` with the locking order (§3.5), emitting events after commit. The runner wraps `DB::transaction()` (deadlock retries) and, at the end of the outermost callback, assigns `sync_version` to every Syncable row written (`SyncVersionRecorder`, one `tenants.sync_counter` lock per tenant). Writes outside the runner (factories, seeders, tinker) are versioned immediately.
- Controllers: authorize → FormRequest → Action → Inertia/JSON. Reads go through `app/Queries` / `app/Reports`.

### 3.2 Folder structure
```
pos-rewrite/
├─ CLAUDE.md · .cursorrules · README.md
├─ docs/ (00–06, adr/, mockup/)
├─ tools/mockup/ (mockup build scripts + compiled mockup; not part of the app)
├─ app/
│  ├─ Actions/{Auth,Catalog,Customers,Membership,Inventory,Money,Pos,Purchases,Returns,Sales,Settings,Sync,Tenancy}/
│  ├─ Data/ · Enums/ · Events/ · Listeners/ · Exceptions/Domain/
│  ├─ Http/
│  │  ├─ Controllers/{Module}/            # Inertia pages
│  │  ├─ Controllers/Api/{Module}/        # /app/api (session)
│  │  ├─ Controllers/PosApi/V1/           # /api/pos/v1 (device token)
│  │  ├─ Middleware/ (ResolveTenant, EnsureBranchSelected, EnsureTenantActive, SetLocale, AuthenticateDevice)
│  │  ├─ Requests/{Module}/ · Resources/{Module}/
│  ├─ Jobs/ · Notifications/
│  ├─ Models/ (+ Concerns/{BelongsToTenant,BelongsToBranch,HasUuid,HasLegacyId,Syncable})
│  ├─ Policies/ · Queries/ · Reports/
│  ├─ Pos/Pricing/PriceCalculator.php     # PHP twin of the TS pricing engine (§3.9.4)
│  ├─ Services/{Pdf/PdfRenderer, Numbering/DocumentNumberer, Membership/CustomerNumber (Luhn), Membership/CardRenderer, Crypto/PinSealer, Sync/{PullService,PushService}}
│  ├─ Support/{Money,Quantity,Tenancy,Http}/       # Http/TableQuery: page/sort/filter parsing for DataTable endpoints
│  ├─ Legacy/ (Importer, Steps/* one per 05_Schema §12 step, Transform/*; throwaway, removed after cut-over; reads the read-only `legacy` DB connection, maps in config/legacy.php)
│  └─ Console/Commands/{IntegrityCheck, LegacyImport, Geo/ImportGeoCommand}
├─ database/{migrations, factories, seeders (Roles, Geo, DemoTenant)} · database/data/geo/*.json (BD reference data)
├─ resources/
│  ├─ css/app.css (tokens + schemes)
│  ├─ js/
│  │  ├─ pages/**.tsx (Inertia pages, kebab-case folders)
│  │  ├─ components/{ui (shadcn), app (AppShell, TopBar, StatusBar, CommandPalette, NotificationsPanel, Clock, ThemePopover, DataTable, RowContextMenu, Kbd, MoneyInput, QtyStepper, KpiTile, EmptyState, LocationFields, PinPad…)}
│  │  ├─ engine/ (money.ts, quantity.ts, digits.ts: shared by back office + POS; PHP twins in app/Support)
│  │  ├─ pos/  (standalone SPA; entry main.tsx; never imports @inertiajs/*, enforced by ESLint no-restricted-imports)
│  │  │  ├─ db/ (Dexie schema + migrations)        ├─ sync/ (puller, outbox, pusher, connectivity)
│  │  │  ├─ engine/ (pricing.ts, numbering.ts, stock.ts — pure, deterministic)
│  │  │  ├─ search/ (MiniSearch index builder)     ├─ scanner/ (keyboard-wedge detector)
│  │  │  ├─ store/ (zustand UI state)              └─ components/ (Register, ProductGrid, Cart, PaymentPanel, CustomerAttach, TodaySummary, SyncCenter)
│  │  ├─ hooks/ (useHotkeys wrappers, useMoney, usePermissions, useFilters, useContextMenu, usePrint)
│  │  ├─ i18n/{en,bn}.json · types/
│  │  ├─ hotkeys/ (matcher, registry, dispatch, host, use-hotkeys) · command/ (action registry shared by row buttons, context menu and palette; nav; search; ui-store) · components/{shell,data-table}/ (D3.1). NB the shared action registry lives in `command/`, not `actions/`: `resources/js/actions/` is the Wayfinder output folder and is git-ignored.
│  │  └─ sw.ts (service worker, vite-plugin-pwa injectManifest)
│  └─ views/{app.blade.php (Inertia root), pos.blade.php (static POS shell, no server data), receipts/{thermal,a4,return,payment}.blade.php, cards/cr80.blade.php, print/{statement,report}.blade.php}
├─ routes/{web.php, app.php, api-internal.php, pos-api.php, platform.php, console.php}
├─ tests/{Feature, Unit, Arch, fixtures/pricing/*.json} · e2e/ (Playwright)
└─ lang/{en,bn}/
```

### 3.3 Multi-tenancy
Single DB, shared schema.
- `tenant_id` sits on every tenant table, and `branch_id` on branch tables.
- The `BelongsToTenant`/`BelongsToBranch` traits provide a global scope, auto-fill on create, and a cross-tenant write guard.
- Route binding resolves through the scoped query, so a foreign id gives 404.
- The context lives in the scoped singleton `App\Support\Tenancy\TenantContext` (`run()`, `withoutTenancy()`). Its ids are mirrored into Laravel `Context`, which travels with queued jobs; the `WithTenantContext` job middleware restores them and fails a tenant job dispatched without one.
- Scopes **fail closed**: a tenant/branch-scoped query with no context throws `MissingTenantContext`. Writes to another tenant/branch throw `CrossTenantWrite`.
- Spatie permission teams = tenant.
- **Device tokens are bound to (tenant, branch, register)**, and `AuthenticateDevice` sets the context from the token.
- Global reference tables (`geo_*`) have no `tenant_id` and are read-only.

### 3.4 Money, quantity, time, VAT
- Money = BIGINT **paisa** (`brick/money`), serialised as `{amount, formatted}`. **No floats** in PHP, SQL or TS. TS uses `number` integers within the safe range, and arithmetic only through `engine/money.ts` helpers.
- Quantity = DECIMAL(14,3) (`brick/math`). In TS, quantity is an integer in **milli-units** (`qty_milli`) inside the engine.
- Rounding: half-up to the paisa per line. Optional cash rounding goes to `sales.rounding`.
- VAT: rate in basis points per product. Inclusive or exclusive is a tenant setting. Each line stores its rate and amount. Invoices show a per-rate summary plus the seller and buyer BIN.
- Timestamps are UTC. Display and business-day boundaries use the tenant timezone (default `Asia/Dhaka`).

### 3.5 Inventory engine
- **Source of truth: `stock_movements`** (append-only). `branch_stocks` is a cache updated in the same transaction. The nightly integrity check catches drift.
- **FIFO by batch.** Sales split allocation across batches → `sale_item_batches` → COGS. Branch setting `batch_selection=auto|manual` (legacy `is_use_stock_invoice`).
- **Locking order:** `document_sequences` → `branch_stocks` (ORDER BY product_id) FOR UPDATE → `stock_batches` (ORDER BY received_at,id) FOR UPDATE → party rows (`customers`/`vendors` balance, by id) → the documents being settled (`sales`/`purchases` rows, by id) → **`tenants.sync_counter` last**, immediately before commit. Holding the counter lock until commit makes `sync_version` values visible in commit order, so a pull cursor can never skip a row. Bulk writers reserve one range per chunk (ADR 0001 D6).
- Negative stock: online sales are blocked unless `allow_negative_stock`. **Synced offline sales are always accepted.** A shortfall is costed at `products.cost_price` and the movement is flagged `is_backorder`. The sale is flagged `oversold`.
- `App\Support\Stock\StockLedger` is the only writer of `branch_stocks`/`stock_batches`/`stock_movements` (`lock`, `receive`, `issue`/`issueMany` with optional forced batch for `batch_selection=manual`, `reverse`, and `moveInTransit` for the destination branch's `qty_in_transit`, the one branch_stocks write that may target a branch other than the context branch). A purchase can be cancelled only while all its batches are untouched and nothing is paid on it; batch unit cost = landed line total (net + tax ± prorated shipping/order discount) ÷ qty.

### 3.6 Numbering
- Back-office documents: `DocumentNumberer::next(type, branch)` with FOR UPDATE: `{BR}-{TYPE}-{YY}{seq6}`. Types in use: `purchase`, `payment` (due collections, vendor payments), `adjustment`, `sale` (web/register sales until device numbering arrives), `return`.
- Payments that belong to one sale or return take a derived number instead of a sequence: tenders `{sale number}/{n}`, a return's credit `{return number}/C` and refund `{return number}/R` (D2.5).
- **POS sales are numbered on the device:** `{BR}-{REG}-{YY}{seq6}` (e.g. `DF1-R1-26000123`). Each register has exactly one active device. Enrollment and bootstrap give the device the server's high-water mark, and the device increments it locally inside the commit transaction. The server stores `registers.last_number` = max seen. Unique per register by construction, so there are never collisions.
- **Customer number:** a tenant sequence (`document_sequences` type `customer`, starting 1000001) + 1 Luhn digit = 8 digits, assigned **on the server only**. Offline-created customers show "Pending no." until they sync.

### 3.7 Idempotency
- Every outbox op carries `op_id` (UUIDv7), and sales carry `client_uuid`. The server stores processed op ids (`sync_ops`). A replay returns the original result.
- Back-office payment and return posts require an `Idempotency-Key` header.

### 3.8 Permissions (seeded; names are a contract with 02_AppFlow)
```
dashboard.view
pos.sell · pos.discount_override · pos.price_override · pos.backdate · pos.sell_below_cost · pos.customer_pin_override
devices.manage
sales.view · sales.void · returns.view · returns.create
payments.collect · payments.pay
customers.view · customers.create · customers.update · customers.delete · customers.reset_pin · customers.export
sms.view · sms.send
cards.view · cards.issue
products.view · products.create · products.update · products.delete · products.view_cost
stock.view · stock.adjust · stock.count · stock.transfer
purchases.view · purchases.create · purchases.receive · purchases.cancel
vendors.view · vendors.create · vendors.update · vendors.delete
money.view · money.create · money.manage
reports.view · reports.export · reports.profit · reports.all_branches
branches.switch
users.manage · settings.manage · audit.view
backup.view · backup.run
```
Roles:
- **Owner**: all.
- **Manager**: all except `users.manage`, `settings.manage`, `audit.view`, `branches.switch`, `reports.all_branches`, `backup.view`, `backup.run` (a Manager works in their own branch only; only the Owner switches branch and sees other branches' reports).
- **Cashier**: `dashboard.view pos.sell sales.view returns.view customers.view customers.create cards.view products.view stock.view payments.collect`.
- **Accountant**: `dashboard.view sales.view returns.view customers.view customers.export vendors.view sms.view money.* reports.* payments.*`.
- **Stock Keeper**: `products.* stock.* purchases.* vendors.* reports.view`.

`sms.view` = the WhatsApp & SMS hub and message log (customer phones and texts), `sms.send` = compose / send / resend / preview SMS; SMS provider settings stay `settings.manage`. `customers.export` = the customer CSV (every phone and balance), no longer implied by `customers.view`. Existing tenants get the three new names on their Owner role from `permissions:sync`; Manager/Accountant need `--reset-system-roles` (or a manual grant) to receive them.

`php artisan permissions:sync` (run on deploy; idempotent) creates permission names added to `Permission::ALL` and grants each newly created one to every tenant's Owner role; `--reset-system-roles` re-applies the default sets of the system roles (use it once to take `branches.switch`/`reports.all_branches` away from existing Managers). Without `branches.switch` a user never sees the branch picker: the branch is the pivot default, else `last_branch_id`, else the first active assigned one.

`backup.view` = the Backup & Disaster Recovery page (installation-wide history, read-only), `backup.run` = Backup Database Now + archive download; both Owner-only by default. Platform admins have no permissions at all: the console is guarded by the Gate ability `platform.admin` (`is_platform_admin` and no tenant), which `HandleInertiaRequests` shares as their single "permission" so the nav/palette can gate on it; `impersonating` gates the banner's Exit.

`roles.max_discount_pct_bp` holds the per-role discount cap. Seeded defaults: Owner 10000 (100%), Manager 2000 (20%), Cashier/Accountant/Stock Keeper 0. Source of truth in code: `App\Support\Permissions\{Permission,SystemRoles}`; `App\Actions\Tenancy\SeedTenantRoles` creates the five `is_system` roles per tenant (idempotent; re-running refreshes permission sets but keeps an existing role's discount cap).

### 3.9 Offline-first POS: sync design

**3.9.1 Local database (Dexie, per enrolled device, DB name `pos_{tenant}_{register}`)**
| Store | Content | Source |
|---|---|---|
| `meta` | device id, register, branch, cursors per entity, last pull/push, schema version, number high-water mark | bootstrap |
| `settings` | tenant/branch settings needed by POS (tax mode, rounding, PIN policy, offline credit cap, allow negative, receipt template) | bootstrap/pull |
| `staff` | id, name, role permissions subset, **PIN verifier** (PBKDF2-SHA256 200k iterations, per-device salt; not the server hash), offline login expiry | bootstrap |
| `products` | id, family_id, variant_label, name/name_bn, sku, barcode, price, tax_bp, unit (decimal flag), min qty, category, brand, image url, active | pull |
| `stock` | product_id → qty_on_hand snapshot for this branch | pull + local decrements |
| `customers` | id, uuid, customer_no, name, phone, group, discount_bp, **has_pin**, pin_locked, credit_allowance (offline cap), card numbers + status. **No address/DOB/email** | pull |
| `tenders`, `taxes`, `groups`, `categories`, `families`, `variant_options`, `variant_option_values` | small reference lists (families/options power the variant picker) | pull |
| `sales` | local copy of sales made on this device (pruned 7 days after sync) | local |
| `held` | held carts | local |
| `outbox` | ops: `sale.create`, `customer.create` | local |

**3.9.2 Pull.** `GET /api/pos/v1/pull?entity=products&cursor=…` returns `{changes:[…], tombstones:[ids], next_cursor, has_more}`. The cursor is the per-row `sync_version` (BIGINT, from a tenant counter incremented on every write of a `Syncable` model). Triggers: on boot, every 60 s while online, on `online` event, after each push. Initial load is paged at 1,000 rows. After a pull, the MiniSearch index is rebuilt incrementally.

**3.9.3 Push.** The outbox is flushed in order, batches of ≤ 25 ops, `POST /api/pos/v1/push`. The server processes each op in its own transaction through the **same Actions** as online (e.g. `CompleteSale` with `origin=device`). Per-op result:
- `ok`
- `duplicate` (already processed, treated as ok)
- `accepted_with_flags`
- `rejected`

`rejected` happens only on auth or structural corruption: the op is kept and surfaced in the Sync center. Retries use exponential backoff (1 s → 5 min cap) and Background Sync where available. A sale is **never** rejected for business reasons (stock, price) because it physically happened. Those cases become flags.

**3.9.4 Shared pricing engine.**
- `resources/js/pos/engine/pricing.ts` and `app/Pos/Pricing/PriceCalculator.php` implement the identical algorithm on integer paisa and milli-qty. Inputs: lines, discounts, group discount, tax mode, rounding.
- Golden fixtures in `tests/fixtures/pricing/*.json` are executed by **both** Vitest and Pest. CI fails on any divergence.
- The server recomputes every pushed sale. A difference is accepted as charged and flagged `price_mismatch` (with both values stored).

**3.9.5 Customer PIN offline (sealed box).**
- Each tenant has an X25519 keypair. The public key is in bootstrap, and the private key is encrypted at rest with `APP_KEY` (sodium).
- Offline, the POS encrypts `{customer_id, pin, client_uuid, ts}` with `crypto_box_seal` and stores the ciphertext on the sale. The device cannot decrypt it, and no PIN hash is ever on the device.
- On push, the server decrypts and verifies against the argon2id hash, counting attempts toward the lockout. Failure flags the sale `member_unverified`.
- Online, the PIN goes over TLS to `verify-pin` and is never stored client-side.

**3.9.6 Staff offline auth.**
- Online login on the device stores a staff PIN verifier (3.9.1).
- Offline unlock is allowed only if the last online login on that device was within `offline_login_days` (default 7). 5 failures lock offline unlock for that user until the next online login.
- *Accepted risk:* a 4–6 digit verifier on a stolen device can be brute-forced offline. Mitigations are device revocation (remote wipe on next contact), short-lived offline windows, and limited local data.

**3.9.7 Service worker.**
- `vite-plugin-pwa` (`injectManifest`) precaches the app shell, the POS chunk, fonts and icons.
- Navigation to `/pos` uses NetworkFirst (3 s timeout) and falls back to the cached static shell. The shell contains no server data, so a cached copy is never stale. The service worker never caches Inertia (`/app/*`) responses.
- `/api/pos/v1/*` is never cached by the service worker (the app manages it).
- Product images use a CacheFirst cache of max 2,000 entries over 30 days.
- New versions: the update toast ("New version: reload when cart is empty") **never auto-reloads with a non-empty cart**.

**3.9.8 Storage.**
- Call `navigator.storage.persist()` on enrollment.
- Warn at 80 % quota. Prune synced sales after 7 days.
- Logout of the last staff member does **not** wipe (the register stays enrolled). Revocation wipes.

**3.9.9 Conflict policy (server wins on master data, device wins on facts)**
| Case | Resolution |
|---|---|
| Price changed while offline | The device price is used. Server flags `price_mismatch` if the recompute differs |
| Stock insufficient at sync | Accept. Backorder movement. Flag `oversold` |
| Customer deleted/merged while offline | Attach to the survivor (merge map) or walk-in. Flag `customer_merged` |
| Offline-created customer has a duplicate phone | Merge into the existing customer. The device receives the mapping on the next pull |
| Card reported lost while offline | Sale accepted. Flag `member_unverified` if the PIN also fails |
| Product deleted while offline | Sale accepted with the snapshot name/SKU (FK to the soft-deleted product) |

### 3.10 Keyboard-first & desktop UX implementation rules
- All shortcuts are registered through one `useHotkeys` wrapper with **scopes** (`global`, `page:<name>`, `dialog`, `pos`). There is a central registry used by the `?` overlay and the command palette, so there are never orphan shortcuts.
- Barcode scanner: a keyboard-wedge detector (≥ 4 chars, inter-key gap < 35 ms, terminated by Enter) routes scans globally. A scan never types into a focused text field on a non-POS page unless that field opts in.
- Context menus: `RowContextMenu` wraps rows and tiles, with items from a per-entity action registry (the same registry feeds row action buttons and the palette). `Shift+F10` gives keyboard parity.
- Focus management: after every POS action focus returns to the scan field. Dialogs restore focus on close. Visible focus ring everywhere.

## 4. Third-party services

| Service | Use | MVP |
|---|---|---|
| Email (SMTP/Postmark/SES) | invites, password reset, export ready | yes |
| S3-compatible storage | media, exports, card PDFs, backups | yes (prod) |
| Sentry | PHP + React errors | yes (prod) |
| Uptime monitor | `/up` | yes (prod) |
| BD geo reference data | divisions/districts/upazilas/unions (EN+BN) + post codes + curated metro thanas, imported into `geo_*` by `geo:import` from `database/data/geo/*.json` (nuhil/bangladesh-geocode MIT + ifahimreza/bangladesh-geojson MIT; ADR 0008; converter `tools/geo/convert.py`) | yes (data only) |
| **WhatsApp provider** | `App\WhatsApp\Contracts\WhatsAppGateway` contract (`send(WhatsAppMessage): WhatsAppResult` with status sent / handoff / failed, provider ref, retryable flag, wa.me `link`); adapters in `app/WhatsApp/Drivers`: `ClickToChatGateway` (builds `https://wa.me/<E.164 digits>?text=<url-encoded>`, no account, result `handoff`, one chat at a time so never bulk), `MetaCloudApiGateway` (WhatsApp Business Cloud API `POST graph.facebook.com/v21.0/{phone_number_id}/messages`, Bearer token, free-form `text` or an approved single-parameter `template`; 4xx = rejection, 429/5xx/timeout retryable; Laravel `Http`, no SDK), `LogWhatsAppGateway`, `NullWhatsAppGateway`. `WhatsAppManager` builds the adapter from `tenants.settings.whatsapp` (`driver`, `phone_number_id`, `access_token` Crypt-encrypted and scrubbed from audits, `template_name`, `template_language`); swapping providers = one adapter + one `match` arm. `messages.channel` (sms\|whatsapp) routes the queued `SendSms` job through `App\Messaging\MessageDispatcher` to `SmsSender` or `WhatsAppSender`; click-to-chat is delivered synchronously by `SendDirectMessage` (row status `handoff`, link returned to the UI). Composer broadcasts on WhatsApp reuse `SendSmsBroadcast`/`FanOutSmsBroadcast` (`sms_broadcasts.channel`, cost 0, click-to-chat refused). No provider HTTP API is called outside an adapter | no |
| **SMS provider** | `SmsGateway` contract; drivers `ssl_wireless` and `onecodesoft` (both HTTP via Laravel `Http`; `onecodesoft` = OneCodeSoft Bulk SMS v3, Bearer key + account-specific `base_url` from its Dashboard > API, no default, outcome read from the body `status`), `log`, `null`. No SDK dependency (ADR 0007). Settings live in `tenants.settings.sms` (`driver`, `sender_id`, `sid`, `base_url`, `api_token`, `cost_per_segment` (paisa, estimate only), `templates`); the `api_token` is stored `Crypt`-encrypted inside that JSON, is never sent to a page and is scrubbed from audit rows. Queued `App\Sms\SendSms` (3 tries, backoff 30s/120s, `WithTenantContext`). Manual sends from the messaging hub go through the final Action `App\Actions\Messaging\SendSmsBroadcast` (`settings.manage`): one `sms_broadcasts` row + audit in `Transaction::run`, idempotent per tenant on the composer's key, then one queued `App\Sms\FanOutSmsBroadcast` job (after commit, `WithTenantContext`, 3 tries) that writes the per-recipient `messages` rows and `SendSms` jobs in chunks of 200 and resumes without duplicates on retry. `SendSms` jobs are dispatched after commit. Automatic transactional SMS (auto-discovered listeners on events dispatched with `DB::afterCommit`, each in try/catch + `report()`, named customers with a valid phone only, an unset template counts as enabled): `SaleCompleted`→`QueueSaleSms` (`sale_completed`; `{link}` = `route('receipts.public', sales.public_token)` via `APP_URL`, no request needed; `Templates::SUPERSEDED_DEFAULTS` upgrades a stored old default body), `CustomerPaymentCollected`→`QueuePaymentSms` (`payment_received`), `SaleReturned`→`QueueReturnSms` (`sale_returned`, from `CreateSaleReturn` only: an exchange sends no return SMS), `SaleVoided`→`QueueVoidSms` (`sale_voided`) | no |
| Payment gateways | non-goal | no |

## 5. Security constraints (non-negotiable)
1. **Passwords:** argon2id, min 8 chars, `uncompromised()` when online. Legacy MD5 is verified once, rehashed and nulled. Remove the fallback 180 days after go-live.
2. **Staff PIN:** 4–6 digits, argon2id on the server. Device verifier per §3.9.6. Lockout. Never grants `users.manage`/`settings.manage`.
3. **Customer PIN:** 4–6 digits, argon2id server-side only (`Hash::make`, `HASH_DRIVER=argon2id`). Built 2026-11-04 (online path): `App\Support\Customers\CustomerPin` (rules), Actions `SetCustomerPin` / `VerifyCustomerPin` / `ResetCustomerPin` / `UnlockCustomerPin`, `App\Support\Pos\MemberToken` (verify → 10-min token bound to tenant, customer, cashier and cart; `CompleteSale::memberAuth()` decides `sales.member_auth`). Policy `tenants.settings.customer_pin_policy = off | optional | required` (`App\Enums\CustomerPinPolicy`; default `off`; legacy `always` reads as `required`, `credit_only` as `optional`).
   - Weak-PIN denylist: one repeated digit, ascending/descending runs (wrapping 9→0), repeated 2/3-digit blocks, a list of well-known PINs, and the tail of the customer's phone number.
   - 5 failures → 30 min lock.
   - Never logged, displayed, exported or stored on devices (sealed box only).
   - Rate limit on `verify-pin`: 10/min per device/operator and 5/min per customer (`RateLimiter::for('pos-verify-pin')`).
   - Reset/unlock require `customers.reset_pin` + a reason (audited as `pin.reset` / `pin.unlocked`; `pin.set` and `pin.locked` are audited too; `Audit::FORBIDDEN` strips `pin`/`pin_hash` at any depth). A cashier (`pos.sell`) may help a member set a **first** PIN at the register; replacing one needs `customers.update`.
4. **Device tokens:**
   - Sanctum token with ability `pos:device`, bound to tenant/branch/register, rotated every 30 days. Stored in IndexedDB (not localStorage).
   - Revocable, and revocation wipes local data.
   - Each request also requires an authenticated staff operator id signed into the op.
5. **2FA:** TOTP, enforceable per role.
6. **Rate limits:** login 5/min (login+IP). `/app/api` 120/min per user. `/api/pos/v1/push` 60/min per device. Export 10/h per user.
7. **Authorization:** every controller action authorizes. A Pest arch test fails the build otherwise.
8. **Tenant isolation:** §3.3. Raw queries are allowed only in `app/Reports` and `app/Queries`, with an explicit tenant filter (arch test).
9. Explicit `$fillable`. `Model::shouldBeStrict()` outside production.
10. All input goes through FormRequests. Money is parsed with brick, never `(float)`.
11. React escapes output. **`dangerouslySetInnerHTML` is banned** (lint rule). Blade print views use `{{ }}` only.
12. CSRF on session routes. Device API uses bearer tokens (no cookies). Cookies: Secure, HttpOnly, SameSite=Lax, a neutral cookie name `pos_session` (no brand).
13. Headers: CSP (self, Sentry; `worker-src 'self'`), HSTS, `X-Frame-Options DENY` (print views SAMEORIGIN), `Referrer-Policy strict-origin-when-cross-origin`, `Permissions-Policy camera=(self)`.
14. Secrets only in `.env`. `gitleaks` in CI. Tenant private keys and API keys use encrypted casts.
15. **No DB/admin tools in `public/`.** Telescope is local only. Horizon and Pulse are gated.
16. Uploads: MIME and size validated, private bucket, signed URLs, EXIF stripped.
17. Audit: business records, logins, permissions, voids, price changes, adjustments, **PIN resets/unlocks/overrides, card lost/reissue**, device enroll/revoke, impersonation (`tenant.impersonated` / `tenant.impersonation_ended` on the tenant, caused by the platform admin), tenant provisioning / suspension, backup requests. 2-year retention.
18. Public receipt `/r/{token}`: a random 32-char token, no phone/address, revocable, rate limited.
19. Backups: daily, encrypted, 30-day retention, monthly restore drill. Implemented with spatie/laravel-backup (`config/backup.php`): one zip per run = gzip MySQL dump + `storage/app/public`, encrypted with `BACKUP_ARCHIVE_PASSWORD`, disks `local` (+ `s3` when `AWS_BUCKET` is set), nightly 02:00 Asia/Dhaka through `App\Actions\Backup\RunScheduledBackup` so every run (also manual ones from `/app/settings/backups`) is a row in `backups`; `BackupRunner` is the seam tests fake. The archive is installation-wide (ADR 0010); only local-disk archives are downloadable through the app.
20. Local device data minimisation (§3.9.1). Wipe on revoke.
21. `composer audit` + `npm audit` in CI. High or critical fails the build.

## 6. Quality gates
`composer ci` runs everything, so any CI provider can call it. Currently wired: `composer lint` (pint --test + rector --dry-run) → `composer analyse` → `composer test` (`php artisan test --parallel`) → `npm run lint` (Oxlint via Vite+) → `npm run typecheck` → `npm run test` (Vitest via `vp test run`) → `npm run build` → `composer audit` → `npm audit --omit=dev --audit-level=high`. **Not yet wired (TODO):** `playwright test` (browsers not installed on the dev machine), `gitleaks` (not installed), and Pest `--coverage --min=…` (no xdebug/pcov driver). Formatting is `npm run format` (Oxfmt); ESLint/Prettier are not used. Pint also enforces `declare_strict_types` and `final_class`; `tests/Arch` holds the architecture rules.
Full target pipeline:
`pint --test` · `rector --dry-run` · `phpstan` (level 8) · `pest --parallel --coverage --min=80` (Actions, PriceCalculator, Sync ≥ 95 %) · `npm run lint` · `tsc --noEmit` · `vitest run` (engine ≥ 95 %, **pricing fixtures parity**) · `playwright test` (incl. **offline sale → reconnect → synced** scenario) · `composer audit` · `npm audit --omit=dev` · `gitleaks`.

Performance budgets:
- POS initial JS ≤ 300 KB gzip
- POS local search p95 < 50 ms on 20k products
- local sale commit < 100 ms
- dashboard p95 ≤ 400 ms
- no N+1 (`preventLazyLoading`)

> Tenant setting `default_unit_id` (2026-11-12): `tenants.settings.default_unit_id` (units.id, null = the active `pcs` unit) is saved by `SaveBusinessSettings`, resolved by `Unit::defaultId()` and preselected in the product form. `SaveProduct` takes `opening_qty` (create only; needs `stock.adjust`) and receives it via `StockLedger::receive` (type `opening`).
