# 05 — Database Schema

> Engine: MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci`. Migrations in `database/migrations` are the executable form of this document. **If they disagree, fix one of them in the same PR.**

> **Phase D notes (2026-11-03):** every `sync_version` column is nullable (the recorder fills it right after the insert) and exists on all Syncable tables (categories, brands, units, tax_rates, variant_options/values, payment_methods too), each with `INDEX(tenant_id, sync_version)`. Every enum column also has a CHECK on its allowed values. `products` has CHECKs `barcode NOT LIKE 'MC%'` and `sale_price/cost_price >= 0`. `document_sequences.type` is a free string (`customer`, `invoice`, `expense`...), `prefix` defaults to `''`, `current_year` is nullable, and the table has no timestamps. The six line tables (`*_items`, `sale_item_batches`, `payment_allocations`) and `legacy_id_maps` have no `tenant_id` and no tenant scope. One active card per customer is a virtual `active_guard` column with a UNIQUE index.

## 0. Conventions (apply to every table unless stated)

| Rule | Detail |
|---|---|
| PK | `id BIGINT UNSIGNED AUTO_INCREMENT` (Laravel `id()`) |
| Public id | Tables exposed in URLs or to the client that need an opaque id get `uuid CHAR(36)` (UUIDv7), unique |
| Timestamps | `created_at`, `updated_at` `TIMESTAMP NULL` (UTC). Ledger tables are append-only: `created_at` only |
| Soft delete | `deleted_at TIMESTAMP NULL` only where marked **SD** |
| Tenant | `tenant_id BIGINT UNSIGNED NOT NULL FK→tenants.id ON DELETE RESTRICT`, indexed. Composite uniques start with `tenant_id` |
| Branch | `branch_id BIGINT UNSIGNED NOT NULL FK→branches.id ON DELETE RESTRICT` where marked **[B]** |
| Money | `BIGINT NOT NULL DEFAULT 0` in **paisa** (suffix-free names, e.g. `total`). Never FLOAT/DOUBLE/DECIMAL for money |
| Quantity | `DECIMAL(14,3) NOT NULL DEFAULT 0` |
| Rates | `*_bp INT UNSIGNED` basis points (1500 = 15.00 %) |
| Enums | `VARCHAR(32)` backed by PHP enums (not MySQL ENUM), CHECK constraint where listed |
| Audit actor | `created_by` / `updated_by BIGINT UNSIGNED NULL FK→users.id ON DELETE SET NULL` |
| Legacy | `legacy_id INT UNSIGNED NULL` on migrated entities + index `(tenant_id, legacy_id)` |
| FK default | `ON DELETE RESTRICT` (business data is never cascaded away) unless noted |

Notation below: `col TYPE [NULL] [DEFAULT x] — note`. Not-null is the default. `FK→t` = foreign key to `t.id`.

---

## 1. Platform, tenancy & auth

```sql
tenants                                    -- one business (customer of the SaaS)
  id, uuid UNIQUE
  name VARCHAR(150), slug VARCHAR(80) UNIQUE
  legal_name VARCHAR(200) NULL
  vat_bin VARCHAR(20) NULL, vat_registered_address VARCHAR(500) NULL      -- BD VAT BIN (13 digits) shown on invoices
  phone VARCHAR(20) NULL, email VARCHAR(150) NULL, address VARCHAR(500) NULL
  currency CHAR(3) DEFAULT 'BDT', timezone VARCHAR(64) DEFAULT 'Asia/Dhaka', locale VARCHAR(5) DEFAULT 'en'
  status VARCHAR(16) DEFAULT 'active'      -- active|suspended  CHECK
  settings JSON                            -- tax_inclusive, backdate_days, require_customer_on_sale (bool, default true: the online register refuses a walk-in sale with 422 `customer_required`; a synced device sale is accepted and flagged `customer_missing`), customer_pin_policy(off|optional|required; default off; legacy always→required, credit_only→optional),
                                           -- offline_credit_cap (paisa, default 0), offline_login_days (7), lapsed_days, card terms text,
                                           -- default_credit_limit (paisa, null = no limit), return_days (null = no return window), default_unit_id (units.id preselected on new products; null or inactive = the active `pcs` unit)
  branding JSON NULL                       -- white-label (PRD Epic J). Live: logo_path (public disk, tenants/{tenant_id}/logo-{random}.{png|jpg|webp}; Tenant::logoUrl()). Later: display_name, brand_hex
  pin_box_public_key VARCHAR(64)           -- X25519 base64 (sealed-box for offline customer PIN)
  pin_box_secret_key TEXT                  -- encrypted cast
  sync_counter BIGINT UNSIGNED DEFAULT 0   -- source of sync_version values (incremented under lock)
  created_at, updated_at

branches  [tenant]  SD
  id, uuid UNIQUE, tenant_id
  code VARCHAR(10)                         -- used in doc numbers, e.g. DHK
  name VARCHAR(150), phone VARCHAR(20) NULL, address VARCHAR(500) NULL
  logo_path VARCHAR(255) NULL
  receipt_header VARCHAR(500) NULL, receipt_footer VARCHAR(500) NULL
  settings JSON                            -- allow_negative_stock, batch_selection(auto|manual), cash_rounding, auto_print ("print after each sale": on = receipt window + done panel, off = cart cleared and scan field focused for the next sale; reprint stays in sales history), paper(80|58|a4)
  is_active BOOL DEFAULT 1
  legacy_id, created_at, updated_at, deleted_at
  UNIQUE(tenant_id, code)

users  SD
  id, uuid UNIQUE
  tenant_id NULL FK→tenants                -- NULL only for platform admins
  name VARCHAR(150)
  username VARCHAR(50) NULL, email VARCHAR(150) NULL, phone VARCHAR(20) NULL
  password VARCHAR(255)                    -- argon2id
  legacy_password_md5 CHAR(32) NULL        -- nulled after first successful login
  must_change_password BOOL DEFAULT 0
  pin_hash VARCHAR(255) NULL, pin_failed_attempts TINYINT DEFAULT 0, pin_locked_until TIMESTAMP NULL
  two_factor_secret TEXT NULL, two_factor_recovery_codes TEXT NULL, two_factor_confirmed_at TIMESTAMP NULL
  is_platform_admin BOOL DEFAULT 0
  is_active BOOL DEFAULT 1
  preferences JSON                         -- theme(light|dark|system), scheme(teal|indigo|violet|rose|ocean|graphite),
                                           -- density(comfortable|compact), locale, bn_digits, last_branch_id, saved table views
  email_verified_at TIMESTAMP NULL, last_login_at TIMESTAMP NULL, remember_token VARCHAR(100) NULL
  legacy_id, created_at, updated_at, deleted_at
  UNIQUE(email), UNIQUE(tenant_id, username), UNIQUE(tenant_id, phone)   -- email is app-wide unique (ADR 0006)

branch_user
  branch_id FK→branches ON DELETE CASCADE, user_id FK→users ON DELETE CASCADE
  is_default BOOL DEFAULT 0
  PRIMARY KEY(branch_id, user_id), INDEX(user_id)

user_invitations [tenant]
  id, tenant_id, email VARCHAR(150) NULL, phone VARCHAR(20) NULL, role_id FK→roles
  branch_ids JSON, token_hash CHAR(64) UNIQUE, expires_at TIMESTAMP, accepted_at TIMESTAMP NULL
  invited_by FK→users, created_at, updated_at

login_activities [tenant NULLABLE]          -- append-only
  id, tenant_id NULL, user_id NULL FK→users ON DELETE SET NULL
  login VARCHAR(150)                        -- what was typed (for failures)
  event VARCHAR(24)                         -- login|logout|failed|locked|pin_switch|2fa_failed
  ip_address VARCHAR(45), user_agent VARCHAR(500) NULL, created_at
  INDEX(tenant_id, created_at), INDEX(user_id, created_at)

-- spatie/laravel-permission, teams=true (team_foreign_key = tenant_id):
roles(id, tenant_id NULL, name, guard_name, max_discount_pct_bp INT UNSIGNED DEFAULT 0, is_system BOOL, timestamps)
  UNIQUE(tenant_id, name, guard_name)
permissions(id, name, guard_name, timestamps)  UNIQUE(name, guard_name)       -- global list (04_TRD §3.8)
model_has_roles(role_id, model_type, model_id, tenant_id)  PK(tenant_id, role_id, model_id, model_type)
model_has_permissions(permission_id, model_type, model_id, tenant_id)
role_has_permissions(permission_id, role_id)

-- Laravel framework tables: sessions, cache, cache_locks, jobs, job_batches, failed_jobs,
-- password_reset_tokens, personal_access_tokens, notifications, activity_log (spatie, + tenant_id column & index)

backups                                    -- GLOBAL (no tenant_id): one archive = the whole installation (ADR 0010)
  id, status ENUM(queued,running,completed,failed), trigger ENUM(manual,scheduled),
  disk, path, size_bytes, started_at, finished_at, error TEXT,
  triggered_by FK→users NULL (NULL = scheduler), timestamps
  INDEX (status, created_at)
  -- read by every Owner with backup.view; written only by App\Actions\Backup\{RequestBackup,RunBackup}
```

## 2. Catalog

```sql
categories [tenant] SD
  id, tenant_id, parent_id NULL FK→categories ON DELETE RESTRICT   -- max depth 2 (app-enforced)
  name VARCHAR(150), name_bn VARCHAR(150) NULL, sort_order INT DEFAULT 0, is_active BOOL DEFAULT 1
  legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, parent_id, name)

brands [tenant] SD
  id, tenant_id, name VARCHAR(150), is_active BOOL DEFAULT 1, legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, name)

units [tenant]
  id, tenant_id, name VARCHAR(50), short_name VARCHAR(10), allows_decimal BOOL DEFAULT 0, is_active BOOL DEFAULT 1, timestamps
  UNIQUE(tenant_id, short_name)            -- seeded: pcs, kg, g, m, ft, ltr, box, dozen

tax_rates [tenant]
  id, tenant_id, name VARCHAR(50), rate_bp INT UNSIGNED, is_default BOOL DEFAULT 0, is_active BOOL DEFAULT 1, timestamps

variant_options [tenant]                    -- e.g. Size, Colour (ADR 0005)
  id, tenant_id, name VARCHAR(50), name_bn VARCHAR(50) NULL, sort_order INT DEFAULT 0, timestamps
  UNIQUE(tenant_id, name)

variant_option_values [tenant]
  id, tenant_id, variant_option_id FK→variant_options
  value VARCHAR(50), value_bn VARCHAR(50) NULL, code VARCHAR(10)   -- code is used in generated SKUs (e.g. M, BLK)
  sort_order INT DEFAULT 0, timestamps
  UNIQUE(variant_option_id, value), UNIQUE(variant_option_id, code)

product_families [tenant] SD                -- a style; groups variant products. Not sellable itself
  id, uuid UNIQUE, tenant_id, code VARCHAR(40)
  name VARCHAR(200), name_bn VARCHAR(200) NULL
  category_id NULL FK→categories, brand_id NULL FK→brands, unit_id FK→units, tax_rate_id NULL FK→tax_rates
  option1_id NULL FK→variant_options, option2_id NULL FK→variant_options   -- max 2 options in v1
  image_path VARCHAR(255) NULL, description TEXT NULL, is_active BOOL DEFAULT 1
  sync_version BIGINT UNSIGNED, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, code), INDEX(tenant_id, sync_version)

products [tenant] SD
  id, uuid UNIQUE, tenant_id
  family_id NULL FK→product_families       -- NULL = standalone product (all migrated legacy products)
  option1_value_id NULL FK→variant_option_values, option2_value_id NULL FK→variant_option_values
  variant_label VARCHAR(100) NULL          -- denormalised "M / Black" for search, receipts, sync
  variant_key VARCHAR(40) GENERATED ALWAYS AS (CONCAT_WS('-', option1_value_id, option2_value_id)) STORED
  type VARCHAR(16) DEFAULT 'standard'      -- standard|service (service: track_stock=0)
  item_type VARCHAR(20) DEFAULT 'finished' -- finished|raw_material (CHECK). A raw material is bought and stocked, never sold: sale_price 0, wholesale/min price NULL, standalone (no family), standard + track_stock=1; never offered by the POS lookup and refused by CompleteSale/CreateExchange (InvalidSaleDocument::rawMaterial). finished → raw_material is refused once the product has a sale_items row
  name VARCHAR(200), name_bn VARCHAR(200) NULL
  sku VARCHAR(64), barcode VARCHAR(64) NULL     -- barcode must not start with 'MC' (reserved: membership cards)
  hs_code VARCHAR(12) NULL                 -- optional, VAT
  category_id NULL FK→categories, brand_id NULL FK→brands, unit_id FK→units
  tax_rate_id NULL FK→tax_rates, default_vendor_id NULL FK→vendors
  sale_price BIGINT                        -- legacy "mrp"
  cost_price BIGINT DEFAULT 0              -- last purchase cost (display/fallback; COGS uses batches)
  min_sale_price BIGINT NULL               -- below → needs pos.sell_below_cost / override
  wholesale_price BIGINT NULL              -- reference wholesale price (product form); pricing does not read it
  min_sale_qty DECIMAL(14,3) DEFAULT 1
  reorder_level DECIMAL(14,3) DEFAULT 0    -- legacy "stock_out_alert"
  track_stock BOOL DEFAULT 1
  image_path VARCHAR(255) NULL, description TEXT NULL
  is_active BOOL DEFAULT 1
  sync_version BIGINT UNSIGNED             -- Syncable (04_TRD §3.9.2)
  legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, sku), UNIQUE(tenant_id, barcode), INDEX(tenant_id, sync_version)
  UNIQUE(family_id, variant_key)          -- one product per combination (NULL family → not enforced)
  -- invariant: a variant's option values belong to its family's option1/option2; family products share unit_id
  INDEX(tenant_id, category_id), INDEX(tenant_id, brand_id), INDEX(tenant_id, is_active), INDEX(tenant_id, item_type)
  FULLTEXT(name, name_bn, sku, barcode)    -- POS search (ngram parser for Bangla)
```

## 3. Parties

```sql
customer_groups [tenant] SD
  id, tenant_id, name VARCHAR(100), discount_bp INT UNSIGNED DEFAULT 0, is_active BOOL DEFAULT 1
  legacy_id, timestamps, deleted_at
  UNIQUE(tenant_id, name)                  -- seeded: Retail, Wholesale

customers [tenant] SD
  id, uuid UNIQUE, tenant_id, customer_group_id NULL FK→customer_groups
  customer_no INT UNSIGNED NULL            -- 8 digits = 7-digit seq + Luhn check; NULL only while an offline-created
                                           -- customer awaits sync; never reused, never changed
  name VARCHAR(150), name_bn VARCHAR(150) NULL, shop_name VARCHAR(150) NULL   -- shop_name: the buyer's business (wholesale)
  phone VARCHAR(20) NULL                   -- E.164-normalised (+8801…)
  alt_phone VARCHAR(20) NULL, email VARCHAR(150) NULL
  gender VARCHAR(8) NULL                   -- male|female|other
  dob DATE NULL, anniversary_date DATE NULL     -- legacy "marriage_date"
  vat_bin VARCHAR(20) NULL                 -- B2B buyer BIN, printed on invoice
  -- structured location (§3a geo tables; all optional except as tenant setting requires)
  -- Phase D: the four geo id columns exist WITHOUT foreign keys; the FKs arrive with the geo tables
  geo_division_id SMALLINT UNSIGNED NULL FK→geo_divisions
  geo_district_id SMALLINT UNSIGNED NULL FK→geo_districts
  geo_upazila_id  SMALLINT UNSIGNED NULL FK→geo_upazilas      -- upazila | metro thana | city corporation
  geo_union_id    INT UNSIGNED NULL FK→geo_unions             -- union | ward | pourashava
  area VARCHAR(150) NULL                   -- free text (market/para/road), autocompleted from tenant's existing values
  post_code CHAR(4) NULL
  address_line VARCHAR(500) NULL           -- house/road/holding; legacy free-text address lands here
  location_verified BOOL DEFAULT 1         -- 0 when auto-parsed from legacy text
  -- membership & PIN
  pin_hash VARCHAR(255) NULL               -- argon2id; NEVER serialised (hidden), never synced
  pin_set_at TIMESTAMP NULL, pin_failed_attempts TINYINT UNSIGNED DEFAULT 0, pin_locked_until TIMESTAMP NULL
  member_since DATE NULL                   -- first card issue date
  credit_limit BIGINT NULL                 -- NULL = the tenant setting default_credit_limit applies (null there = no limit; D2.5)
  balance BIGINT DEFAULT 0                 -- cached receivable (+ = owes us, − = advance); recomputed nightly
  last_purchase_at TIMESTAMP NULL          -- for "lapsed customers"
  notes TEXT NULL, is_walk_in BOOL DEFAULT 0, is_active BOOL DEFAULT 1
  origin_client_uuid CHAR(36) NULL         -- set when created offline by a device (dedupe on sync)
  merged_into_id BIGINT UNSIGNED NULL FK→customers   -- set on duplicate merge (row soft-deleted)
  sync_version BIGINT UNSIGNED
  legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, customer_no), UNIQUE(tenant_id, phone), UNIQUE(tenant_id, origin_client_uuid)
  INDEX(tenant_id, name), INDEX(tenant_id, last_purchase_at), INDEX(tenant_id, balance)
  INDEX(tenant_id, geo_district_id), INDEX(tenant_id, geo_upazila_id), INDEX(tenant_id, sync_version)
  -- exactly one is_walk_in=1 row per tenant (app-enforced, seeded; it has no customer_no requirement but gets one)
  -- app rule: chosen child geo ids must belong to the chosen parents (validated; parents auto-filled from child)

membership_cards [tenant]
  id, tenant_id, customer_id FK→customers
  issue_no TINYINT UNSIGNED                -- 1,2,3… per customer
  card_code VARCHAR(16)                    -- barcode payload: 'MC' + customer_no(8) + issue_no(2), e.g. MC1000001701
  status VARCHAR(12)                       -- active|lost|replaced|revoked
  issued_at TIMESTAMP, issued_by FK→users
  print_count SMALLINT UNSIGNED DEFAULT 0, last_printed_at TIMESTAMP NULL, last_print_layout VARCHAR(8) NULL  -- cr80|a4_10|pdf
  blocked_at TIMESTAMP NULL, blocked_by NULL FK→users, block_reason VARCHAR(255) NULL
  sync_version BIGINT UNSIGNED, created_at, updated_at
  UNIQUE(tenant_id, card_code), UNIQUE(customer_id, issue_no), INDEX(tenant_id, status)
  -- at most one 'active' card per customer (app-enforced + generated-column unique guard)

vendors [tenant] SD
  id, uuid UNIQUE, tenant_id
  name VARCHAR(150), company VARCHAR(200) NULL, phone VARCHAR(20) NULL, email VARCHAR(150) NULL
  address VARCHAR(500) NULL, notes TEXT NULL
  balance BIGINT DEFAULT 0                 -- cached payable (+ = we owe vendor)
  is_active BOOL DEFAULT 1, legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, phone), INDEX(tenant_id, name)
```

## 3a. Bangladesh geo reference (GLOBAL: no tenant_id, read-only, seeded by `geo:import`)

```sql
geo_divisions
  id SMALLINT UNSIGNED PK, code VARCHAR(10) UNIQUE, name_en VARCHAR(60), name_bn VARCHAR(60)        -- 8 rows
  aliases VARCHAR(255) NULL                 -- comma-separated older romanisations (Chittagong …), searched

geo_districts
  id SMALLINT UNSIGNED PK, division_id FK→geo_divisions, code VARCHAR(10) UNIQUE
  name_en VARCHAR(60), name_bn VARCHAR(60), aliases VARCHAR(255) NULL                             -- 64 rows
  INDEX(division_id)

geo_upazilas                                -- "City / Upazila / Thana" level
  id SMALLINT UNSIGNED PK, district_id FK→geo_districts, code VARCHAR(12) UNIQUE
  type VARCHAR(16)                          -- upazila | metro_thana | city_corporation
  name_en VARCHAR(80), name_bn VARCHAR(80)
  city_name_en VARCHAR(80) NULL             -- e.g. 'Dhaka North City Corporation' for metro thanas
  aliases VARCHAR(255) NULL
  INDEX(district_id, type), FULLTEXT(name_en, name_bn)   -- 494 upazilas (ids 1–494) + 65 metro thanas (ids 1001+)

geo_unions                                  -- "Union / Ward / Pourashava" level (optional on forms)
  id INT UNSIGNED PK, upazila_id FK→geo_upazilas, code VARCHAR(14) UNIQUE
  type VARCHAR(16)                          -- union | ward | pourashava
  name_en VARCHAR(80), name_bn VARCHAR(80)
  INDEX(upazila_id), FULLTEXT(name_en, name_bn)

geo_post_codes
  id INT UNSIGNED PK, upazila_id NULL FK→geo_upazilas, district_id FK→geo_districts
  post_office_en VARCHAR(80), post_office_bn VARCHAR(80) NULL, post_code CHAR(4)
  INDEX(post_code), INDEX(upazila_id), UNIQUE(district_id, post_office_en, post_code)
```
Data source (ADR 0008): nuhil/bangladesh-geocode (MIT) for divisions/districts/upazilas/unions EN+BN, ifahimreza/bangladesh-geojson `bd-postcodes.json` (MIT) for post offices, a curated `tools/geo/metro_thanas.json` for the Dhaka (50) and Chattogram (15) metro thanas. Converted by `tools/geo/convert.py` into `database/data/geo/*.json`, loaded by `php artisan geo:import` (idempotent upsert by `code`, stable ids; `GeoSeeder`). Codes are hierarchical (`06`, `06-47`, `06-47-325`, `06-47-M01`, `06-47-325-1234`). `App\Support\Geo\GeoCatalog` caches per data version and serves `/app/api/geo/*` (ETag = version + query, 30-day private cache). The customer geo columns carry FKs (migration `2026_11_03_000200`). Saving a customer location checks the chain (district ∈ division, upazila ∈ district, union ∈ upazila), fills parents from the deepest child, and sets `location_verified = 1`; the legacy import fills ids from a district/upazila name found in the address text with `location_verified = 0` and reports the rest as `unparsed_location`.

## 4. Inventory

```sql
branch_stocks [tenant][B]                  -- cache of the ledger, locked during writes
  id, tenant_id, branch_id, product_id FK→products
  qty_on_hand DECIMAL(14,3) DEFAULT 0
  qty_in_transit DECIMAL(14,3) DEFAULT 0   -- incoming transfers
  updated_at
  UNIQUE(branch_id, product_id), INDEX(tenant_id, product_id)

stock_batches [tenant][B]                  -- FIFO cost layers
  id, tenant_id, branch_id, product_id FK→products
  source_type VARCHAR(32)                  -- purchase|opening|transfer_in|return|adjustment
  source_id BIGINT UNSIGNED NULL           -- polymorphic id (purchase_items.id, transfer_items.id…)
  batch_no VARCHAR(50) NULL                -- legacy stock invoice no / vendor lot
  unit_cost BIGINT
  qty_received DECIMAL(14,3), qty_remaining DECIMAL(14,3)
  received_at TIMESTAMP, expires_at DATE NULL
  legacy_id, created_at, updated_at
  INDEX(branch_id, product_id, qty_remaining, received_at, id)   -- FIFO pick
  CHECK (qty_remaining >= 0 AND qty_remaining <= qty_received)

stock_movements [tenant][B]                -- APPEND-ONLY ledger (no updates, no deletes)
  id, tenant_id, branch_id, product_id FK→products
  stock_batch_id NULL FK→stock_batches
  type VARCHAR(24)   -- opening|purchase|sale|sale_void|sale_return|adjustment_in|adjustment_out|transfer_out|transfer_in|stocktake
  qty DECIMAL(14,3)                        -- signed: + in, − out
  unit_cost BIGINT                         -- cost of this movement
  reference_type VARCHAR(64), reference_id BIGINT UNSIGNED   -- e.g. sale_items / 123
  is_backorder BOOL DEFAULT 0
  note VARCHAR(255) NULL, user_id NULL FK→users
  created_at
  INDEX(tenant_id, product_id, created_at), INDEX(branch_id, created_at), INDEX(reference_type, reference_id)

stock_adjustments [tenant][B]
  id, uuid UNIQUE, tenant_id, branch_id, number VARCHAR(30)
  reason VARCHAR(24)                       -- damage|loss|found|opening|correction|stocktake|production_use|other (CHECK)
  stocktake_id BIGINT UNSIGNED NULL        -- FK→stocktakes once they exist (Phase D: plain column)
  note TEXT NULL, adjusted_at TIMESTAMP
  created_by, created_at, updated_at
  UNIQUE(tenant_id, number)
stock_adjustment_items
  id, stock_adjustment_id FK ON DELETE CASCADE, product_id FK→products
  qty DECIMAL(14,3)                        -- signed
  unit_cost BIGINT                         -- for +: entered/last cost; for −: FIFO computed
  INDEX(product_id)

stocktakes [tenant][B]
  id, uuid UNIQUE, tenant_id, branch_id, number VARCHAR(30)
  status VARCHAR(16)                       -- draft|counting|review|posted|cancelled
  scope JSON                               -- {all:true} | {category_ids:[…]} | {brand_ids:[…]}
  snapshot_at TIMESTAMP NULL, posted_at TIMESTAMP NULL, note TEXT NULL
  created_by, timestamps
  UNIQUE(tenant_id, number), INDEX(branch_id, status)
stocktake_items
  id, stocktake_id FK ON DELETE CASCADE, product_id FK→products
  system_qty DECIMAL(14,3), counted_qty DECIMAL(14,3) NULL, unit_cost BIGINT
  counted_by NULL FK→users, counted_at TIMESTAMP NULL
  UNIQUE(stocktake_id, product_id)

stock_transfers [tenant]
  id, uuid UNIQUE, tenant_id, number VARCHAR(30)
  from_branch_id FK→branches, to_branch_id FK→branches
  status VARCHAR(16)                       -- draft|in_transit|received|cancelled
  sent_at TIMESTAMP NULL, received_at TIMESTAMP NULL, note TEXT NULL
  sent_by NULL FK→users, received_by NULL FK→users, created_by, timestamps
  UNIQUE(tenant_id, number), CHECK(from_branch_id <> to_branch_id)
stock_transfer_items
  id, stock_transfer_id FK ON DELETE CASCADE, product_id FK→products
  qty_sent DECIMAL(14,3), qty_received DECIMAL(14,3) NULL, unit_cost BIGINT, short_reason VARCHAR(255) NULL
  UNIQUE(stock_transfer_id, product_id)
```

> Raw materials (2026-11-04, migration `2026_11_04_000200_add_raw_materials`): adds `products.item_type` (existing rows stay `finished`) with `products_item_type_check` and `INDEX(tenant_id, item_type)`, and widens `stock_adjustments_reason_check` with `production_use` ("Used in production": how raw material consumption is recorded; there is no production module, bill of materials or automatic consumption). Stock valuation and low stock include raw materials; dead stock excludes them. Auto SKUs (`SkuAllocator`, used when a product is created without a SKU, e.g. the GRN's inline "New item"): `RM-000001` for raw materials, `SKU-000001` for finished products, next free number per tenant.

> Transfers (2.19): `CreateStockTransfer` writes the row as `in_transit` with `sent_at`/`sent_by` (no draft step yet), issues `transfer_out` FIFO from `from_branch_id` and stores the weighted FIFO cost on `unit_cost`; the destination's `branch_stocks.qty_in_transit` carries the sent quantity until `ReceiveStockTransfer` (destination branch context only) books `transfer_in` batches for `qty_received` (`source_type=transfer_in`, `source_id=stock_transfer_items.id`) and releases it. `qty_received < qty_sent` needs `short_reason`. Migration `2026_11_04_000100_create_stock_transfers_tables`.

> A restock (sale return / void) puts quantity back onto its original batch only up to that batch's headroom (`qty_received − qty_remaining`); any remainder becomes a new batch with `source_type=return`, the same unit cost and `received_at = now` (so FIFO sells it after older stock). Imported legacy batches (step 8, `qty_received = available_stock`) often have no headroom, so this keeps the CHECK above without refusing the return.

## 5. Purchasing

```sql
purchases [tenant][B] SD(draft only)
  id, uuid UNIQUE, tenant_id, branch_id, vendor_id NULL FK→vendors   -- NULL = vendorless purchase: settled on receipt (paid = total, due 0), no payable, no vendor balance/ledger; due on it is refused
  number VARCHAR(30), vendor_ref VARCHAR(60) NULL     -- vendor's invoice no; app-enforced unique per tenant+vendor among non-cancelled, non-deleted bills (trim, case-insensitive; no index, legacy duplicates stay)
  status VARCHAR(16)                       -- draft|received|cancelled
  purchased_at TIMESTAMP, received_at TIMESTAMP NULL
  subtotal BIGINT, discount BIGINT DEFAULT 0, tax_total BIGINT DEFAULT 0, shipping BIGINT DEFAULT 0, total BIGINT
  paid BIGINT DEFAULT 0, due BIGINT DEFAULT 0          -- due = total − paid (maintained by Action)
  payment_status VARCHAR(16)               -- unpaid|partial|paid
  note TEXT NULL, legacy_id, created_by, updated_by, timestamps, deleted_at
  UNIQUE(tenant_id, number), INDEX(tenant_id, vendor_id, purchased_at), INDEX(branch_id, status, purchased_at)
purchase_items
  id, purchase_id FK ON DELETE CASCADE, product_id FK→products
  qty DECIMAL(14,3), unit_cost BIGINT, discount BIGINT DEFAULT 0, tax_amount BIGINT DEFAULT 0
  line_total BIGINT                        -- landed cost per unit (incl. allocated shipping/discount) stored on the batch
  legacy_id
  INDEX(product_id)
```

## 6. Sales, payments, returns, register

```sql
registers [tenant][B]
  id, tenant_id, branch_id, name VARCHAR(60)
  code VARCHAR(6)                          -- used in device numbering, e.g. R1
  last_number BIGINT UNSIGNED DEFAULT 0    -- high-water mark of device-issued sale numbers (per year prefix)
  number_year SMALLINT UNSIGNED
  is_active BOOL DEFAULT 1, timestamps
  UNIQUE(branch_id, name), UNIQUE(branch_id, code)

devices [tenant][B]                         -- an enrolled browser acting as a register
  id, uuid UNIQUE, tenant_id, branch_id, register_id FK→registers
  name VARCHAR(100), user_agent VARCHAR(500) NULL
  token_id BIGINT UNSIGNED NULL            -- personal_access_tokens.id (ability pos:device)
  status VARCHAR(12)                       -- active|revoked
  enrolled_by FK→users, enrolled_at TIMESTAMP, revoked_by NULL FK→users, revoked_at TIMESTAMP NULL
  last_seen_at TIMESTAMP NULL, last_push_at TIMESTAMP NULL, last_pull_at TIMESTAMP NULL
  app_version VARCHAR(20) NULL, local_db_bytes BIGINT NULL
  timestamps
  INDEX(register_id, status)
  -- at most one active device per register (generated-column unique guard)

device_pairing_codes [tenant][B]            -- one-time codes to enroll a device (ADR 0001 D3)
  id, tenant_id, branch_id, register_id FK→registers
  code_hash CHAR(64)                       -- SHA-256 of the 8-char code; the code itself is never stored
  expires_at TIMESTAMP                     -- issued_at + 10 min
  used_at TIMESTAMP NULL, used_by_device_id NULL FK→devices
  issued_by FK→users, created_at
  UNIQUE(code_hash), INDEX(register_id, expires_at)
  -- single use: EnrollDevice locks the row FOR UPDATE and sets used_at

sync_ops                                   -- processed outbox ops (idempotency + audit)
  id, tenant_id, device_id FK→devices, op_id CHAR(36), type VARCHAR(32)
  result VARCHAR(24)                       -- ok|duplicate|accepted_with_flags|rejected
  result_payload JSON, received_at TIMESTAMP, processed_at TIMESTAMP NULL
  UNIQUE(tenant_id, op_id), INDEX(device_id, received_at)

-- No shifts / register_sessions / cash_movements (decided 2026-10-30: the legacy app has none). Today summary and
-- daily cash summary are computed from sales, payments, sale_returns and cash_transactions.

sales [tenant][B]
  id, uuid UNIQUE, tenant_id, branch_id
  client_uuid CHAR(36)                     -- idempotency (04_TRD §3.7)
  number VARCHAR(30) NULL                  -- assigned on completion (held/draft have none)
  register_id NULL FK→registers            -- set for POS sales (device numbering); NULL for back-office sales
  exchange_return_id NULL FK→sale_returns -- replacement sale of an exchange (ADR 0005)
  customer_id FK→customers                 -- walk-in customer row when anonymous
  status VARCHAR(16)                       -- held|completed|voided
  sold_at TIMESTAMP                        -- may be back-dated (permission)
  subtotal BIGINT                          -- Σ qty×unit_price
  item_discount_total BIGINT DEFAULT 0, order_discount BIGINT DEFAULT 0
  tax_total BIGINT DEFAULT 0, shipping BIGINT DEFAULT 0, rounding BIGINT DEFAULT 0
  total BIGINT                             -- subtotal − discounts + tax + shipping + rounding
  paid BIGINT DEFAULT 0, change_given BIGINT DEFAULT 0
  due BIGINT DEFAULT 0                     -- total − paid − returned_credit (maintained)
  returned_total BIGINT DEFAULT 0
  cogs_total BIGINT DEFAULT 0
  payment_status VARCHAR(16)               -- unpaid|partial|paid
  courier_name VARCHAR(100) NULL, courier_tracking VARCHAR(100) NULL  -- courier picked per sale in the register pay dialog from the active `couriers` (free text, trimmed, cut to 100 for a synced sale, never a rejection); printed as "Courier: X" on A4 + thermal
  public_token CHAR(32) UNIQUE NULL        -- /r/{token}
  buyer_vat_bin VARCHAR(20) NULL           -- snapshot of customer BIN at sale time
  -- offline / device provenance
  origin VARCHAR(8) DEFAULT 'device'       -- device|web|import
  device_id BIGINT UNSIGNED NULL           -- FK→devices once devices exist (Phase D: plain column)
  device_created_at TIMESTAMP NULL         -- device clock at commit (sold_at uses it; server receipt time = created_at)
  synced_at TIMESTAMP NULL
  member_auth VARCHAR(16) DEFAULT 'none'   -- none|pin_online|pin_offline_ok|pin_offline_failed|override|no_pin_policy
                                           -- set by CompleteSale::memberAuth(): walk-in→none; policy off→no_pin_policy; member token→pin_online;
                                           -- approver with pos.customer_pin_override→override; else none (policy required: web 422, device flag member_unverified)
  member_auth_by NULL FK→users             -- manager on override
  member_pin_box TEXT NULL                 -- sealed-box ciphertext; NULLed after verification
  membership_card_id NULL FK→membership_cards   -- card used, if scanned
  sync_flags JSON NULL                     -- ["price_mismatch","oversold","member_unverified","customer_merged","customer_missing"]
  sync_flags_ack_by NULL FK→users, sync_flags_ack_at TIMESTAMP NULL
  client_totals JSON NULL                  -- device-computed totals kept when price_mismatch
  note TEXT NULL
  sold_by FK→users                         -- operator (PIN-switched user)
  voided_by NULL FK→users, voided_at TIMESTAMP NULL, void_reason VARCHAR(255) NULL
  legacy_id, created_at, updated_at
  UNIQUE(tenant_id, client_uuid), UNIQUE(tenant_id, number)
  INDEX(branch_id, status, sold_at), INDEX(tenant_id, customer_id, sold_at), INDEX(tenant_id, payment_status, due)
  INDEX(register_id, sold_at), INDEX(sold_by, sold_at), INDEX(tenant_id, synced_at)
  -- numbering: device-issued '{BR}-{REG}-{YY}{seq6}' (04_TRD §3.6)
  CHECK(total >= 0 AND paid >= 0 AND due >= 0)

sale_items
  id, sale_id FK→sales ON DELETE CASCADE, product_id FK→products
  product_name VARCHAR(200), sku VARCHAR(64)    -- snapshots
  qty DECIMAL(14,3), unit_price BIGINT, discount BIGINT DEFAULT 0   -- discount = line + customer-group discount
  tax_rate_bp INT UNSIGNED DEFAULT 0, tax_amount BIGINT DEFAULT 0  -- tax after the order-discount share
  line_total BIGINT                        -- qty×unit_price − discount (+ tax_amount when VAT is exclusive); the order
                                           -- discount stays on the header, so Σ line_total − order_discount + rounding = total
  cogs BIGINT DEFAULT 0                    -- Σ sale_item_batches
  qty_returned DECIMAL(14,3) DEFAULT 0
  legacy_id
  INDEX(product_id), INDEX(sale_id)
  CHECK(qty > 0 AND qty_returned <= qty)

sale_item_batches                          -- FIFO allocation trace
  id, sale_item_id FK→sale_items ON DELETE CASCADE, stock_batch_id FK→stock_batches
  qty DECIMAL(14,3), unit_cost BIGINT, qty_returned DECIMAL(14,3) DEFAULT 0
  INDEX(stock_batch_id)

payment_methods [tenant]
  id, tenant_id, name VARCHAR(60)
  type VARCHAR(16)                         -- cash|card|mobile_wallet|bank|customer_credit|exchange_credit
  account_id BIGINT UNSIGNED NULL          -- where money lands; FK→accounts once §7 exists (Phase D: plain column)
  requires_reference BOOL DEFAULT 0, sort_order INT, is_active BOOL DEFAULT 1, timestamps
  -- seeded: Cash, Card, bKash, Nagad, Rocket, Bank transfer, Customer credit, Exchange credit (system, no account)

couriers [tenant]                           -- delivery couriers; sales keep the courier as text (sales.courier_name), no FK
  id, tenant_id, name VARCHAR(80), phone VARCHAR(30) NULL, is_default BOOL DEFAULT 0, is_active BOOL DEFAULT 1, timestamps
  default_guard BIGINT UNSIGNED NULL VIRTUAL = IF(is_default=1, tenant_id, NULL) UNIQUE   -- at most one default per tenant
  UNIQUE(tenant_id, name)                  -- not Syncable: the POS never pulls it; SaveCourier/SetDefaultCourier clear the old default under lock

payments [tenant][B]                        -- one money movement (in from customer / out to vendor or refund)
  id, uuid UNIQUE, tenant_id, branch_id, number VARCHAR(30)
  direction VARCHAR(4)                     -- in|out
  party_type VARCHAR(16), party_id BIGINT UNSIGNED   -- customer|vendor
  payment_method_id FK→payment_methods, account_id BIGINT UNSIGNED NULL   -- FK→accounts once §7 exists (Phase D: plain nullable column)
  amount BIGINT                            -- > 0
  reference VARCHAR(100) NULL              -- bKash TrxID, card slip…
  register_id NULL FK→registers            -- POS tender payments
  paid_at TIMESTAMP, received_by FK→users, note VARCHAR(255) NULL
  voided_at TIMESTAMP NULL                 -- voiding a sale voids its POS payments
  legacy_id, created_at, updated_at
  UNIQUE(tenant_id, number), INDEX(tenant_id, party_type, party_id, paid_at), INDEX(register_id, paid_at), INDEX(received_by, paid_at)
  CHECK(amount > 0)

payment_allocations                        -- which documents a payment settles
  id, payment_id FK→payments ON DELETE CASCADE
  allocatable_type VARCHAR(16), allocatable_id BIGINT UNSIGNED   -- sale|purchase|sale_return
  amount BIGINT
  INDEX(allocatable_type, allocatable_id)
  -- invariant: Σ allocations(payment) ≤ payment.amount; unallocated remainder = party advance/credit

sale_returns [tenant][B]
  id, uuid UNIQUE, tenant_id, branch_id, number VARCHAR(30)
  sale_id FK→sales, customer_id FK→customers
  returned_at TIMESTAMP
  subtotal BIGINT, tax_total BIGINT, total BIGINT
  refund_mode VARCHAR(16)                  -- refund|credit|exchange (credit reduces sale due / customer balance)
  exchange_sale_id NULL FK→sales           -- set when refund_mode=exchange (ADR 0005)
  refunded BIGINT DEFAULT 0, credited BIGINT DEFAULT 0     -- refunded + credited = total. credited = the part that
                                           -- settled the sale's open due, stayed on account, or paid the exchange sale
  reason VARCHAR(32), note TEXT NULL
  legacy_id, created_by, timestamps
  UNIQUE(tenant_id, number), INDEX(sale_id), INDEX(branch_id, returned_at)
sale_return_items
  id, sale_return_id FK ON DELETE CASCADE, sale_item_id FK→sale_items, product_id FK→products
  qty DECIMAL(14,3), unit_price BIGINT, tax_amount BIGINT, line_total BIGINT
  restock BOOL DEFAULT 1, cogs_reversed BIGINT DEFAULT 0
  legacy_id
  CHECK(qty > 0)

document_sequences [tenant]
  id, tenant_id, branch_id NULL FK→branches
  type VARCHAR(24), prefix VARCHAR(20), next_number BIGINT UNSIGNED DEFAULT 1, pad TINYINT DEFAULT 6
  reset_yearly BOOL DEFAULT 1, current_year SMALLINT
  UNIQUE(tenant_id, branch_id, type)

idempotency_keys
  id, tenant_id, user_id, key CHAR(64), route VARCHAR(100), response_hash CHAR(64), response JSON, created_at
  UNIQUE(tenant_id, key)                   -- purged after 24 h
```

## 7. Money (cash book)

```sql
accounts [tenant] SD
  id, tenant_id, branch_id NULL FK→branches     -- NULL = business-wide
  name VARCHAR(100), type VARCHAR(16)      -- cash|bank|mobile_wallet
  account_no VARCHAR(50) NULL, opening_balance BIGINT DEFAULT 0
  is_active BOOL DEFAULT 1, timestamps, deleted_at
  UNIQUE(tenant_id, name)

transaction_categories [tenant] SD          -- legacy accounting type → head (2 levels)
  id, tenant_id, parent_id NULL FK→transaction_categories
  name VARCHAR(100), direction VARCHAR(8)  -- income|expense|transfer(owner withdraw/deposit)
  is_system BOOL DEFAULT 0, is_active BOOL DEFAULT 1, legacy_id, timestamps, deleted_at
  UNIQUE(tenant_id, parent_id, name)

cash_transactions [tenant]                  -- ledger of account balance changes
  id, uuid UNIQUE, tenant_id, branch_id NULL FK→branches, account_id FK→accounts
  direction VARCHAR(4)                     -- in|out
  amount BIGINT, transacted_on DATE
  category_id NULL FK→transaction_categories   -- manual income/expense
  source_type VARCHAR(32) NULL, source_id BIGINT UNSIGNED NULL  -- payment|cash_movement|manual
  description VARCHAR(255) NULL, attachment_path VARCHAR(255) NULL
  legacy_id, created_by, updated_by, timestamps
  INDEX(tenant_id, account_id, transacted_on), INDEX(tenant_id, category_id, transacted_on), INDEX(source_type, source_id)
  CHECK(amount > 0)

fixed_assets [tenant] SD
  id, tenant_id, branch_id NULL FK→branches
  name VARCHAR(150), purchased_on DATE, cost BIGINT, description TEXT NULL
  invoice_path VARCHAR(255) NULL, status VARCHAR(16) DEFAULT 'active'   -- active|disposed
  legacy_id, created_by, timestamps, deleted_at
```

## 8. Messaging & misc

```sql
messages [tenant]                          -- SMS + WhatsApp log (un-deferred, ADR 0007)
  id, tenant_id, customer_id NULL FK→customers
  channel VARCHAR(16) DEFAULT 'sms'        -- sms|whatsapp  CHECK; INDEX(tenant_id, channel, created_at)
  phone VARCHAR(20), body TEXT, driver VARCHAR(32)   -- driver: the channel's provider at send time (ssl_wireless|onecodesoft|log|null|click_to_chat|meta_cloud)
  status VARCHAR(32) DEFAULT 'queued'      -- queued|sent|failed|handoff  CHECK (handoff = WhatsApp click-to-chat: opened in the user's WhatsApp, delivery unknown)
  provider_ref VARCHAR(100) NULL, error VARCHAR(500) NULL, sent_at TIMESTAMP NULL
  event VARCHAR(40), related_type VARCHAR(64) NULL, related_id BIGINT UNSIGNED NULL
  created_at, updated_at
  INDEX(tenant_id, status), INDEX(tenant_id, customer_id, created_at), INDEX(related_type, related_id)
  -- a composer broadcast's rows: event 'manual', related = the sms_broadcasts row (customer_id = recipient)

sms_broadcasts [tenant]                    -- one row per ad-hoc SMS or WhatsApp broadcast from the messaging hub composer
  id, tenant_id, user_id NULL FK→users (nullOnDelete)
  channel VARCHAR(16) DEFAULT 'sms'        -- sms|whatsapp  CHECK (whatsapp: cost_paisa 0)
  audience JSON                            -- {target: customer|group|due|all, customer: uuid|null, group_id|null}
  template VARCHAR(40) NULL                -- the template key the text started from, if any
  body TEXT                                -- unfilled text; {name} etc. are filled per recipient at fan-out
  recipient_count INT UNSIGNED             -- reachable customers with a valid mobile number at send time
  cost_paisa BIGINT                        -- estimate: segments(body) × recipients × cost_per_segment
  idempotency_key VARCHAR(64)              -- from the composer; a repeat returns this row, sends nothing
  status VARCHAR(32) DEFAULT 'queued'      -- queued|sending|completed
  queued_count INT UNSIGNED DEFAULT 0, skipped_count INT UNSIGNED DEFAULT 0, completed_at TIMESTAMP NULL
  created_at, updated_at
  UNIQUE(tenant_id, idempotency_key), INDEX(tenant_id, created_at)

-- Laravel `notifications` table (+ tenant_id) powers the top-bar bell; types listed in 02_AppFlow §1.1.

exports [tenant]
  id, tenant_id, user_id FK→users, report VARCHAR(60), format VARCHAR(8), filters JSON
  status VARCHAR(16), path VARCHAR(255) NULL, expires_at TIMESTAMP NULL, timestamps

legacy_id_maps                              -- migration bookkeeping (dropped 180 days after go-live)
  id, entity VARCHAR(40), legacy_store_id INT, legacy_id INT, new_id BIGINT UNSIGNED, created_at
  UNIQUE(entity, legacy_store_id, legacy_id)
```

## 9. Relationship summary (ER)
```
tenants 1─* branches 1─* registers 1─* sales 1─* sale_items 1─* sale_item_batches *─1 stock_batches
tenants 1─* users *─* branches (branch_user)            users *─* roles (model_has_roles, team=tenant)
tenants 1─* products *─1 categories/brands/units/tax_rates      products 1─* branch_stocks / stock_batches / stock_movements
customers 1─* sales 1─* sale_returns 1─* sale_return_items *─1 sale_items
vendors 1─* purchases 1─* purchase_items 1─1 stock_batches(source=purchase)
payments 1─* payment_allocations *─1 (sales | purchases | sale_returns)     payments 1─1 cash_transactions
accounts 1─* cash_transactions *─1 transaction_categories
```

## 10. Invariants (enforced in Actions + verified by the nightly `integrity:check` job + tests)
1. `sales.total = Σ sale_items.line_total − order_discount + shipping + rounding` and `subtotal = Σ sale_items.qty×unit_price`. With exclusive VAT this equals `subtotal − item_discount_total − order_discount + tax_total + shipping + rounding`; with inclusive VAT `tax_total` is contained in `subtotal` (not added).
2. `sales.paid = Σ allocations(non-void payments → sale)`. `sales.due = total − paid`. `due ≥ 0`. Credit that settles a sale (a return settling the sale's due, spending an advance, an exchange) is a payment row of the system method `customer_credit` / `exchange_credit` allocated to the sale (D2.5), so no separate `credited` term is needed.
3. `sale_items.cogs = Σ sale_item_batches.qty × unit_cost`. `Σ sale_item_batches.qty = sale_items.qty` (unless backorder).
4. `branch_stocks.qty_on_hand = Σ stock_movements.qty` per (branch, product).
5. `Σ stock_batches.qty_remaining = qty_on_hand` per (branch, product) when no backorders.
6. `customers.balance = Σ sales.due − advances`, advances = unallocated money received + Σ sale_returns.credited − Σ customer_credit/exchange_credit payments (credit spent). Equivalently `balance = Σ sales.total − Σ sale_returns.total + Σ refunds out − Σ money received` (completed sales, non-void payments). `vendors.balance` is the mirror for purchases.
7. Completed sales are immutable except `due/paid/payment_status/returned_total` (via payments/returns) and void fields.
8. No row in any tenant table references a row of another tenant (FK targets share `tenant_id`, checked by the integrity job).
9. Every non-pending `customers.customer_no` passes the Luhn check, is unique per tenant, and is never changed or reused (even after delete or merge).
10. At most one `active` membership card per customer. `card_code` encodes that customer's `customer_no`.
11. `customers.pin_hash` never leaves the server: it is excluded from resources, sync payloads, exports, logs and the activity log.
12. Sale numbers per register are strictly increasing per year. `registers.last_number ≥` the max synced number.

## 11. Auth flow & data access control

MySQL has no Row-Level Security, so this layered model replaces it:

```
Request
 └─ auth (session, Sanctum stateful)  ── unauthenticated → /login
     └─ EnsureTenantActive            ── tenant.status=suspended → logout + message
         └─ ResolveTenant             ── CurrentTenant::set(user.tenant_id); setPermissionsTeamId(tenant_id)
             └─ EnsureBranchSelected  ── session.branch_id ∈ user.branches (re-checked every request) else /app/branch/select
                 └─ SetLocale
                     └─ Controller → Policy (permission + same tenant + branch ∈ user.branches)
                         └─ Eloquent global scopes: tenant_id = current [, branch_id = current]
                             └─ Model `saving` guard: throws CrossTenantWriteException on mismatch
```
- **Device path** (`/api/pos/v1/*`): `AuthenticateDevice` (Sanctum bearer, ability `pos:device`, `devices.status=active`) sets tenant, branch and register from the device row. Each op also carries the operator `user_id`, which is checked as active and assigned to the branch with `pos.sell` at push time. A revoked device gets `401 {code:"device_revoked"}`, which triggers the client wipe.
- **Platform admins** (`users.tenant_id IS NULL, is_platform_admin=1`) use `/platform/*` only (Gate `platform.admin`; `LoginResponse` sends them there). Impersonation = `Auth::login()` as the tenant's first active Owner with the admin id, tenant id and start time in the session (`App\Support\Platform\Impersonation`); audited on the tenant (`tenant.impersonated`, `tenant.impersonation_ended` with reason exit|expired), ended by the banner's Exit (`POST /app/impersonation/exit`) or by the `ExpireImpersonation` web middleware after 60 min. The first admin is created with `php artisan platform:make-admin email`.
- **Branch scope** applies to: sales, sale_returns, payments, purchases, stock_*, branch_stocks. Catalog, customers, vendors, accounts and categories are **tenant-wide**.
- **Isolation test matrix (required, 06_Plan Phase 4):** for every tenant model, a factory creates rows for tenant A and B, and the tests assert that tenant B's user gets 404 on show/update/delete, sees no rows in lists, reports, exports or search, and cannot write a foreign `*_id` (validation `exists` rule is scoped: `Rule::exists(...)->where('tenant_id', …)`).

## 12. Legacy migration mapping

**Scope (decided 2026-10-29):** only the live customer **DIFFERENT FASHION** is migrated: legacy `store_id` **7** ("DIFFERENT FASHION", ~3,090 sales, 1,310 customers, 56 products) and **10** ("DIFFERENT FASHION-2", small) → **one tenant, two branches**. Other stores in the dump (1–6, 8, 9) are not migrated.

`php artisan legacy:import --connection=legacy --stores=7,10 --tenant=different-fashion [--dry-run]` reads the old DB through a separate `legacy` connection (read-only user). It is idempotent: it uses `legacy_id_maps` and resumes per entity.

| Step | Legacy → New | Transform |
|---|---|---|
| 1 | `store_storelist` 7, 10 → tenant `different-fashion` + 2 `branches` (codes `DF1`, `DF2`; confirm with the owner) | `is_use_stock_invoice` 0→`batch_selection=manual`, 1→`auto`. Logo copied to Spaces. One register `R1` per branch |
| 2 | `store_admin_group` → roles | 1→Owner, 2→Manager, 3→Cashier, 4→Accountant |
| 3 | `store_admin` → `users` + `branch_user` | `passwd` → `legacy_password_md5`, `password` = random unusable hash. Status ACTIVE→active, others→inactive. Placeholder values `null@gmail.com` / `01717` / `No Description` → NULL |
| 4 | `store_brand_list`, `store_category_list` → `brands`, `categories` | per store; dedupe by name within the tenant (case-insensitive trim). Drop `brand_id` on categories |
| 5 | `store_vendor_list` → `vendors` | phone normalised to E.164, invalid → NULL + report |
| 6 | `store_customer_group` → `customer_groups`. `store_customer_list` → `customers` | Duplicate phones within the tenant: merge (keep the oldest, remap sales) and report. `marriage_date`→`anniversary_date`. The legacy `address` text goes to `address_line`, and a best-effort match of district/upazila names (EN/BN) in the text fills the geo ids with `location_verified=0`. **`customer_no` is assigned in ascending legacy id order** (first = 1000001+check). No PIN (customers set one on their next visit). Create one walk-in customer |
| 7 | `store_product_list` → `products` | `mrp`→`sale_price`, `buy_price`→`cost_price`, `stock_out_alert`→`reorder_level`, `product_code`→`sku` (blank → `L{store}-{id}`). Unit = pcs, or kg if the qty has fractions |
| 8 | `store_stock_invoice_list/_details` → `purchases` (status received, vendor = product.vendor_id or "Unknown vendor") + `purchase_items` + `stock_batches` (`qty_remaining = available_stock`, `batch_no = invoice_no`) | FLOAT → paisa via `round(x*100)`. A movement row is written per batch as `type=opening` for the remainder (history is in purchases) |
| 9 | Reconcile stock: `branch_stocks.qty_on_hand = Σ batches.qty_remaining`. Diff vs legacy `product_list.quantity` → `migration_report.csv` (no silent fix) | |
| 10 | `store_sales_invoice_list/_details` → `sales` (completed, `origin=import`, `member_auth=none`) + `sale_items` | **Data anomaly:** the dump contains sales dated in the future (e.g. 2027-03). List them in `migration_report.csv` for owner review, and do not silently re-date. `total_amount`→subtotal, `total_discount`→order/item discount (per line from details), `total_due`→due. `sale_items.cogs = buy_price×qty`. `sale_item_batches` linked via `stock_invoice_no` when resolvable. `courier_service 'NA'`→NULL. Number kept as-is (`S-123`) with the branch code prefix if it would collide |
| 11 | `store_sales_invoice_payment_list` → `payments` (Cash) + `payment_allocations`. For **Paid** invoices without a payment row, synthesise one payment = total − due at `created_at` | |
| 12 | `store_return_invoice_list/_details` → `sale_returns` (`refund_mode=refund`) + `sale_return_items` (restock=1) | link via `sales_invoice_id` |
| 13 | `store_accounting_type` → parent `transaction_categories` (Expenditure/Salary/Rent → expense, Income → income, Withdraw → transfer). `store_accounting_head` → child categories. `store_accounting` → `cash_transactions` on the "Cash" account | |
| 14 | fixed-asset table → `fixed_assets` (copy invoice images) | |
| 15 | `store_login_activity` → `login_activities` (optional flag `--with-logs`) | |
| 16 | Post-import: recompute `customers.balance`, `vendors.balance`, `last_purchase_at`. Run `integrity:check`. Produce `reconciliation.csv`: per store, legacy vs new counts and Σ totals/dues/stock value. **Go-live requires zero unexplained differences** | |

**As implemented (D1.2, 2026-11-03).** `App\Console\Commands\LegacyImport` → `App\Legacy\Importer` → one class per step in `app/Legacy/Steps`; store-specific facts (branch codes, admin group → role) in `config/legacy.php`. Deviations and precisions to the table above:
- The whole run is **one** `Transaction::run()` (not one per chunk), so a failure or `--dry-run` leaves nothing; reports are still written. `--fresh` is not implemented yet: re-runs are idempotent through `legacy_id_maps` (`entity` = legacy table name, `legacy_store_id` 0 for tenant-wide entries) instead.
- Step 1: the logo is not copied (reported as `logo_not_copied`). Step 2: roles via `config/legacy.php`; unmapped groups → Cashier (reported).
- Step 6: the address text is matched against district and upazila/thana names (EN, BN, aliases; `GeoCatalog::matchAddress`): a hit fills the geo ids with `location_verified=0`, a miss is reported as `unparsed_location` and the reconciliation row `customer_locations` counts addresses vs located customers. With empty geo tables every address is unparsed. A duplicate phone (after E.164 normalisation) is merged into the oldest: the newer row stays as a soft-deleted shell with `merged_into_id`, no `customer_no` and no phone; its legacy id maps to the survivor so its sales follow. A blank name becomes the shop name, else `Customer L{id}` (reported). A `customer_group_id` that does not exist in legacy (store 7's group 2, 1,046 customers) leaves the customer ungrouped (reported once per group). The walk-in customer is created **after** the legacy customers, so legacy customer 1 gets 10000016 and the walk-in the next number.
- Step 7: the same product code in different legacy stores becomes **one product** (the first keeps its name, prices and active flag; later legacy ids map to it in `legacy_id_maps`, reported as `merged_product`; stock stays per branch, reconciled per store). A code repeated inside the same store, or already used by a non-legacy product, gets `{code}-{legacy_id}` (reported as `duplicate_sku`); cost 0 is reported; only `Active` products are active.
- Step 8: the remainder of each stock invoice line (`available_stock > 0`) goes through `StockLedger::receive()` as an `opening` batch (`qty_received = qty_remaining = available_stock`, `batch_no` = legacy invoice_no, ref = the `purchase_items` row), which also writes the movement and `branch_stocks`; the legacy received quantity is kept on `purchase_items` only. **Exception:** a sold-out line (`available_stock = 0`) gets a directly inserted batch with `source_type=purchase`, `qty_remaining = 0` and no movement, only so sale lines can link to it. Imported purchases are `paid = total`, `due = 0` (legacy never tracked vendor payables). Purchase and sale numbers that another branch already uses get the branch prefix (`DF2-ST1`, `DF2-S-1`), reported.
- Step 9: writes nothing; it asserts `qty_on_hand = Σ batch remainders = Σ movements` per (branch, product) and reports the legacy `product.quantity` differences.
- Step 10: `sold_at` is the legacy Asia/Dhaka wall-clock time converted to UTC. `item_discount_total = Σ line discounts`, `order_discount = total_discount − Σ line discounts`, `sales.paid = total − due`. `sale_item_batches.unit_cost` = the line's legacy `buy_price`, so `cogs = Σ batch qty × unit_cost` holds; lines without a resolvable stock invoice have no batch row (reported as `unlinked_batch`).
- Step 11: payment rows with an amount ≤ 0 are skipped (reported). Numbers are stable: `L-P{legacy payment id}` and `L-S{legacy sale id}` (synthesised). The synthesis is general: any imported sale whose allocations fall short of `total − due` gets one cash payment for the gap (ADR 0002 M3 balancing payment); "Paid without payment rows" is reported as one summary line with the count.
- Step 12: returns move no stock (the legacy batch remainders already include restocked units).
- Step 16: `php artisan integrity:check [--tenant=slug] [--json]` (D5.3) checks invariants 1–6, 9, 10 and sync_version/document-sequence health; all hold on the real data except invariant 3 for the reported unlinked lines, which show as a warning for imported sales. Invariants 8 (cross-tenant FKs), 11 and 12 (per-register numbers) are not checked yet. `reconciliation.csv` rows are either count rows (`diff` = count difference) or sum rows (`diff` = sum difference).

**Cross-cutting rules** (float money, latin1-holding-UTF-8, 12-hour timestamps, zero dates, duplicate numbers, header-vs-line totals, balancing payments, ambiguous batch refs, CSV columns, orphan placeholders, negative batches) are defined in [ADR 0002 §M3](adr/0002-legacy-migration-strategy.md) and apply to every step above. Every run is a **full reload** into the target tenant (`--fresh`); there is no delta sync.

Not migrated: `store_menu`, `store_admin_access_record` (superseded), customer `username/passwd`, `customer_order_list`.
**Before step 10, record the live DB triggers** (`SHOW TRIGGERS`; the app's own backup omits triggers, so the dump showing none proves nothing; see 00_Legacy_Features §3) so we understand how legacy stock decrement and restock actually worked.

> Opening stock (2026-11-12): creating a simple (non-variant), stock-tracked product with `opening_qty` (milli-units, `stock.adjust` only) receives it into the current branch through `StockLedger::receive` as a `stock_movements.type = opening` movement plus an `opening` FIFO batch at `products.cost_price`, in the same `Transaction::run` as `SaveProduct`. Not allowed on update, for services, untracked products or variant grids. The product form offers only active units (an edited product keeps its own).
