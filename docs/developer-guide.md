# Developer guide

Architecture, data model and workflows for working on Open Bulk Buying. For
hooks see [hooks.md](hooks.md); for how the plugin behaves see the
[Staff guide](staff-guide.md).

**Contents**

- [Tech stack](#tech-stack)
- [Repository layout](#repository-layout)
- [Data model](#data-model)
- [Architecture conventions](#architecture-conventions)
- [Access control](#access-control)
- [Internationalization](#internationalization)
- [Building and running](#building-and-running)
- [Testing](#testing)
- [Releasing](#releasing)
- [Not yet implemented](#not-yet-implemented)

---

## Tech stack

| Concern | Choice |
|---|---|
| Platform | WordPress ≥ 6.6, PHP ≥ 7.4, MySQL/MariaDB via `$wpdb` |
| Dashboard UI | React with `@wordpress/components`, built by `@wordpress/scripts` (webpack) from `src/admin/` into `build/admin/` |
| Public forms | Server-rendered PHP templates in `inc/frontend/templates/`, made interactive with the WordPress Interactivity API (`data-wp-*`) and plain ES modules in `assets/js/` — no build step |
| Styles | Sass in `src/sass/` → `build/frontend.css`; dashboard styles bundled with the admin app |
| API | WP REST API, namespace `openbb/v1` |
| Persistence | Custom tables (dbDelta), one custom post type, two taxonomies, per-site user meta, options |
| Documents | HTML print documents (order slips) rendered server-side in `inc/documents/` |
| Email | Plain PHP templates in `inc/emails/`, sent with `wp_mail()` |
| Tests | PHPUnit 9 + Yoast polyfills on the WordPress test library (`wp-phpunit/wp-phpunit` via Composer); Node `node:test` and Jest for a few UI helpers |
| Tooling | `@wordpress/scripts` build/lint/format, PHP CodeSniffer + WordPress Coding Standards, WP-CLI `i18n`, GitHub Actions CI |

---

## Repository layout

```
open-bulk-buying.php        Bootstrap: table names, requires, hook wiring
uninstall.php               Removes plugin-owned data
phpcs.xml.dist              WordPress Coding Standards ruleset
bin/                        install-wp-tests.sh / .ps1 — test database + config
.github/workflows/ci.yml    PHPUnit matrix, JS build and tests
inc/
  class-openbb-installer.php    Schema + versioned upgrades (DB_VERSION)
  class-openbb-settings.php     General settings option and defaults
  class-openbb-captcha.php      Turnstile and the captcha filters
  class-openbb-rate-limit.php   Per-IP buckets for public routes
  class-openbb-maintenance.php  Test mode, demo data, data deletion
  class-openbb-demo-catalog.php Demo fixture definitions
  access/        Roles, capabilities, membership status, user-profile UI
  admin/         Dashboard menu, screens bootstrap, script loading
  documents/     Printable documents (order slips) + templates
  emails/        Email senders + message templates
  frontend/      Shortcodes, views, field rendering + templates
  i18n/          Localized text helpers, currency
  model/
    cycle/       Cycle entity, repository, preparation
    inventory/   Stock ledger
    member/      Member entity, user meta, profile fields, signup, codes, fees
    order/       Order entity, lines, repository, editor, lifecycle, aid, policy
    product/     Product post type, entity, catalogue, units
    rules/       Co-op rules and versions
  privacy/       Personal-data exporter/eraser integration
  rest/
    class-openbb-rest-controller.php   Base: namespace, auth helpers
    admin/       Products, cycles, members, orders, settings, documents
    frontend/    Signup, login, order form
src/
  admin/         React app: router, screens, components, hooks, utils
  sass/          Frontend stylesheet source
assets/js/       Interactivity API modules (auth, signup, login, order, captcha, forms)
languages/       POT, fr_CA PO/MO, JS JSON catalogues
scripts/         Build helpers (source-map cleanup, translation compiler, Node test runner)
tests/           PHPUnit bootstrap, base test case, test-*.php, wp-tests-config-sample.php, JS tests
```

---

## Data model

Money is stored as **integer minor units** (cents) with a `currency` snapshot;
quantities as `decimal(12,3)`. All timestamps are UTC `datetime`. Table names
below omit the `$wpdb->prefix`; each is registered in `$wpdb->tables` so
multisite `switch_to_blog()` re-prefixes them.

### Custom tables

| Table | One row per | Key columns |
|---|---|---|
| `openbb_cycles` | ordering cycle | `status` (setup / ordering / processing / distribution / complete), `opens_at`, `closes_at`, `pickup_opens_at`, `pickup_closes_at`, `pickup_location`, `pickup_instructions`, `finalized_at/by`, `unpaid_hold_until`, `prepaid_hold_until`, `cleanup_locked_at` |
| `openbb_cycle_products` | product pre-ordered in a cycle | `cycle_id`, `product_id` (unique pair), `prep_method` (station / basket), `prepared_qty`, `prepared_at/by` |
| `openbb_orders` | order (cycle or membership-only) | `cycle_id` (NULL for membership-only), `user_id` (unique per cycle), `status` (draft / submitted / prepaid / fulfilled / cancelled), `language`, `currency`, `subtotal`, `container_credit`, `donation`, `aid_status`, `aid_reason`, `aid_requested`, `aid_approved`, `aid_decided_by/at`, `total`, `member_note`, `staff_note`, `submitted_at`, `collected_at/by`, `paid_at/by/amount/currency`, `revision` |
| `openbb_order_lines` | line on an order | `order_id`, `product_id`, `source` (member / staff), `line_type` (product / membership_fee), product snapshot (`product_name`, `brand`, `unit_amount`, `unit_type`, `base_per_unit`, `unit_price`, `container_charge`, `own_container`), `qty_requested`, `qty_prepared`, `qty_final`, `line_total` |
| `openbb_order_events` | audit event on an order | `order_id`, `actor_id`, `event_type`, `from_status`, `to_status`, `reason`, `details` (JSON) |
| `openbb_stock_moves` | stock ledger entry | `product_id`, signed `quantity`, `unit_amount`, `unit_type`, `reason`, optional `cycle_id`, `order_id`, `user_id`, `request_key` (unique, idempotency), `note` |
| `openbb_rules_versions` | published rules version | `content` (JSON: ordered localized HTML items), `content_hash`, `requires_reacceptance`, `published_by/at` |

### WordPress-native storage

| Data | Where |
|---|---|
| Products | Post type `openbb_product` (non-public, no UI). Meta under `openbb_*`: localized `name` and `description`, `brand`, `unit_amount`, `unit_type`, `price`, `container_charge`, `active`, `prep_method`. Image = post thumbnail; display order = `menu_order`. |
| Categories | Hierarchical taxonomy `openbb_product_category`; display order in term meta `openbb_order`. |
| Suppliers | Flat taxonomy `openbb_supplier`, enforced single-term per product. |
| Members | Per-site user meta (keyed with the table prefix like `{prefix}capabilities`): `status`, `roles` (one row per role), `joined_at`, `phone`, `language`, `rules_version`, `rules_accepted_at`, plus custom profile-field values. WordPress owns identity (email, names, password). |
| Settings | Options `openbb_general_settings`, `openbb_roles`, `openbb_profile_fields`, `openbb_rules_draft`, `openbb_currency`, `openbb_turnstile_secret`, `openbb_test_mode`, `openbb_demo_user_id`, `openbb_db_version`. |
| One-time codes | Transients for signup/login codes, bound to a browser cookie. |

### Relationships

```
wp_users ─┬─< openbb_orders >─── openbb_cycles
          │       │                   │
          │       ├─< openbb_order_lines >── openbb_product (post)
          │       │                              │  ├── openbb_product_category (tax)
          │       ├─< openbb_order_events        │  └── openbb_supplier (tax)
          │       │                              │
          │       └─< openbb_stock_moves >───────┘
          │                 (also ─── openbb_cycles)
          │
          └── user meta: status, roles, rules_version ──> openbb_rules_versions

openbb_cycles ─< openbb_cycle_products >─ openbb_product
```

Key invariants:

- **Stock on hand** = `SUM(quantity)` over `openbb_stock_moves` for a product.
  **Reserved** = quantities on orders in committed statuses (submitted,
  prepaid) whose cycle hold has not expired. Inventory SQL and the PHP
  `OPENBB_Order` / `OPENBB_Cycle` helpers share the same status lists and
  hold-field mapping; keep them in sync (reservation tests check both).
- **Fulfilment deducts once**: a stock move with `order_id` is written when an
  order becomes fulfilled; its existence marks the order as having operational
  history.
- Orders keep **price snapshots**; later product edits never change existing
  lines. Membership-fee lines are system-managed (`line_type = membership_fee`).
- `revision` on orders guards against stale writes from the editor and aid
  decisions.
- `cleanup_locked_at` on a cycle, once set, is never cleared.

---

## Architecture conventions

- **Policy** — `OPENBB_Order_Policy` is the single source of order eligibility
  (can edit, cancel, delete, reopen, prepay, fulfil). Services enforce it and
  REST controllers expose its answers so the UI can disable actions instead of
  guessing. Add new eligibility rules here, not in controllers or React.
- **Services write; controllers authorize.** `OPENBB_Order_Editor` (content
  changes, submission, corrections), `OPENBB_Order_Lifecycle` (payment,
  fulfilment, cancellation, reopen), `OPENBB_Order_Aid`,
  `OPENBB_Cycle_Preparation`, `OPENBB_Membership_Fees` and `OPENBB_Signup`
  perform transactional writes and fire actions. REST controllers check
  capabilities and nonces, validate input, call a service, and return its
  result. Keep `current_user_can()` out of services where possible; pass the
  actor ID in.
- **Entities are plain data objects** (`OPENBB_Entity` subclasses) with
  `cast()`; repositories (`OPENBB_Cycles`, `OPENBB_Orders`, …) own SQL.
- **Inventory lock.** Writes that touch stock or reservations run inside
  `OPENBB_Inventory::lock()` / `unlock()` (a MySQL named lock) and return
  `WP_Error( 'openbb_busy' )` with HTTP 503 if it cannot be acquired.
- **Idempotency.** Stock moves and payment confirmations accept a
  `request_key` so retried requests do not double-apply.
- **Events, then side effects.** Email, preparation sync and status-change
  reactions are listeners on `openbb_order_saved`, `openbb_cycle_status_changed`
  and `openbb_member_status_changed` (wired in `open-bulk-buying.php`), not
  inline in services.
- **Frontend.** Views (`OPENBB_*_View`) compute state, templates render it,
  and `assets/js/*.js` Interactivity stores handle behaviour by calling the
  `openbb/v1` frontend routes. Every named control inside the captcha wrapper
  is posted as the `captcha` object.
- **Schema changes.** Edit the owning class's `schema()`, bump
  `OPENBB_Installer::DB_VERSION`, and add any non-dbDelta fix-ups (e.g.
  nullability) to `install()`. `maybe_upgrade()` runs on `plugins_loaded`
  because activation hooks do not fire on updates; the version is recorded only
  after every table and column check passes, so a failed upgrade retries.

---

## Access control

Staff roles are **not** WordPress roles. `OPENBB_Roles` defines the capability
registry (`openbb_manage_products`, `openbb_edit_orders`, …) with groups and
`requires` dependencies, and the built-in roles. The `openbb_roles` option
stores custom roles and permission overrides. Members' roles live in user meta;
`OPENBB_Access::filter_user_has_cap` grants the corresponding capabilities at
runtime and `filter_map_meta_cap` handles per-object checks (e.g. editing a
specific product or member). Administrators (`manage_options`) pass every
`openbb_*` capability.

The product post type uses `capability_type => openbb_product(s)` with
`map_meta_cap`, so core post functions respect co-op permissions.

Membership status (`applicant` / `active` / `inactive` / `declined`) is
separate from roles; only `active` members can order. Status changes go
through `OPENBB_Access` so `openbb_member_status_changed` fires.

---

## Internationalization

Text domain `open-bulk-buying`; catalogues in `languages/`. Canadian French
(`fr_CA`) ships with PO, PHP-format MO and JS JSON files.

```sh
npm run i18n:extract    # wp i18n make-pot → languages/open-bulk-buying.pot
npm run i18n:update     # merge POT into each PO
# translate in Poedit or any gettext editor; keep %s / %1$d, HTML and both plurals
npm run i18n:compile    # node scripts/compile-translations.js → MO + JSON
```

`compile-translations.js` keeps JavaScript messages in the PO and maps their
source references to the shipped `build/admin/index.js` so WordPress loads the
JSON for the right script handle. Saving an MO from Poedit alone does **not**
update JS translations — always run the compile script. Commit POT, PO, MO and
JSON.

**Content translation** (product names, descriptions, rules, profile-field
labels) is separate: values are *localized arrays* (`{ default, fr_CA, … }`)
handled by `inc/i18n/localized-text.php`, with `openbb_languages` /
`openbb_current_language` filters. Emails and order slips render in the order's
stored `language`, switching locale with `switch_to_locale()`.

`Test_OPENBB_Translations` verifies full POT→PO coverage (no empty/fuzzy),
placeholders, HTML, plurals, MO/JSON parity and locale switching.

---

## Building and running

```sh
npm ci
npm run start       # watch src/ → build/, rebuilds on change
npm run build       # production build + strips source maps
npm run lint:js
npm run lint:css
npm run format
```

The dashboard screens require `build/`; without it they show a notice.
Frontend modules in `assets/js/` and PHP need no build.

**Local testing aids:** **Settings → Data & Maintenance → Test mode** enables
**Populate demo data** (41 marked products, 11 categories, 4 suppliers, stock,
a setup cycle, a draft order; idempotent). Cloudflare's test keys
(`1x00000000000000000000AA` / `1x0000000000000000000000000000000AA`) always
pass Turnstile.

---

## Testing

Full guide: [testing.md](testing.md) — mental model, setup, how to read and
write tests, and which class covers what.

```sh
composer install                 # PHPUnit + WordPress test library (vendor/wp-phpunit) + WPCS
.\bin\install-wp-tests.ps1       # Windows: create the test DB, write tests/wp-tests-config.php
bin/install-wp-tests.sh          # macOS / Linux

composer test                                        # all PHP tests
composer test -- --filter Test_OPENBB_Order_Policy   # one class
composer test:multisite
npm test                                             # Node + Jest tests
composer lint                                        # WordPress Coding Standards
```

The test database is **dropped and recreated on every run**. The config
(`tests/wp-tests-config.php`, gitignored) defaults to borrowing core files from
the WordPress install the plugin lives in. Setting `WP_TESTS_DIR` to a
`wordpress-develop` `tests/phpunit` directory overrides the Composer copy.

`tests/class-openbb-testcase.php` provides fixtures for users, cycles and
orders and nests the plugin's transactions as savepoints inside the per-test
transaction. When changing reservation or status logic, run
`Order_Lifecycle`, `Order_Policy`, `Cycle_Preparation` and
`Model_Persistence` together — they assert that SQL and PHP status
definitions agree.

CI (`.github/workflows/ci.yml`) runs the PHP suite on 7.4 / 8.1 / 8.3 against
MySQL 8, builds the JS and runs the JS tests. Linters are advisory until the
existing baseline is clean.

---

## Releasing

1. Bump `Version` in `open-bulk-buying.php` and `OPENBB_VERSION`.
2. If the schema changed, bump `OPENBB_Installer::DB_VERSION`.
3. `npm run i18n:extract && npm run i18n:update && npm run i18n:compile`;
   translate new strings; run the translation tests.
4. `npm run build` and the PHPUnit suite.
5. `npm run plugin-zip` produces an installable ZIP from the `files` allowlist
   in `package.json` (`assets`, `build`, `inc`, `languages`, the bootstrap,
   `uninstall.php`, `readme.txt`).

---

## Not yet implemented

Tracked for future work; the current code intentionally omits these:

- Refunds, payment adjustments and reversals (prepaid orders are locked).
- Shortage substitutions during preparation.
- Configurable prepaid/unpaid hold policies and pickup exceptions.
- Email retry queue and PDF attachments.
- Polls, volunteer shifts and a dedicated distribution-screen launcher.
- Reporting and exports beyond personal-data export.
- Other captcha providers out of the box (use the `openbb_captcha_*` filters).
