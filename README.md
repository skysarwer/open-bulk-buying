# Open Bulk Buying

A WordPress plugin that runs a food co-op's bulk-buying club: a product
catalogue with stock tracking, ordering cycles, member accounts, financial aid,
staff roles, access controls and membership rules - all managed from a 
**Co-op** menu in the WordPress dashboard. Members join, log in and order from 
pages on the public site.

Requires WordPress 6.6+ and PHP 7.4+. Licensed GPL-2.0-or-later.

**Contents**
[For co-op members](#for-co-op-members) ·
[For co-op staff](#for-co-op-staff) ·
[For site administrators](#for-site-administrators) ·
[For developers](#for-developers)

Detailed guides: [Staff guide](docs/staff-guide.md) ·
[Developer guide](docs/developer-guide.md) · [Testing](docs/testing.md) ·
[Hooks reference](docs/hooks.md) · [Contributing](CONTRIBUTING.md)

---

## For co-op members

**Joining.** On the co-op's signup page, enter your details, answer any
profile questions and agree to the co-op rules. You'll receive a 6-digit code
by email; your account is created when you enter it. Depending on the co-op's
settings you become a member right away or an *applicant* awaiting staff
approval. Either way you get an email, and a welcome email once you're active.

**Logging in.** Use your username or email and password. If the co-op has
enabled it, you can instead request a one-time 6-digit code by email. Codes
expire after 15 minutes and work only in the browser that requested them.

**Ordering.** While a cycle is open for ordering, the order form shows the
catalogue grouped by category. Quantities are limited by stock on hand. Your
order is a **draft** until you submit it; you can change a submitted order
until ordering closes. Submitting reserves the stock for you.

**Membership fee.** If the co-op charges one, it appears as a separate line
on your first order (and every *n*th order for recurring fees). Staff can
also record a fee paid separately.

**Financial aid.** You may request aid on an order and, optionally, donate.
Approved aid is applied as a percentage discount on goods when staff
distribute the cycle's donation pool.

**Order statuses you'll see**

| Status | Meaning |
|---|---|
| Draft | Saved but not committed; reserves no stock. |
| Submitted | Committed and reserving stock. Pay at pickup. |
| Prepaid | Paid in full; awaiting pickup. |
| Fulfilled | Paid and collected. |
| Cancelled | Cancelled by staff. Contact the co-op to cancel or reopen an order. |

You get an email when your order is submitted, updated, prepaid, cancelled or
fulfilled. Emails show products, quantities, prices and totals, in your language
when a translation exists.

---

## For co-op staff

Everything lives under **Co-op** in the dashboard: **Products**, **Cycles**,
**Members**, **Orders** and **Settings**. What you can see and do depends on
your *staff role*, assigned in **Users → Profile → Co-op access** or
**Co-op → Members**. Staff roles are separate from WordPress roles.

| Built-in role | Can |
|---|---|
| Manager | Everything, including settings and staff roles. |
| Coordinator | Day-to-day: products, stock, cycles, orders, payments, members, data export. |
| Aid reviewer | View and decide financial-aid requests. |
| Distribution helper | Hand out orders and record payments. |
| Rules editor | Edit co-op rules only. |

Create your own roles and fine-tune permissions in **Settings → Staff roles**.
WordPress Administrators always have every staff permission.

### Quick start

1. Give one trusted person the **Manager** role.
2. **Products** — add products, categories and suppliers; record starting stock.
3. **Cycles** — create a cycle with ordering and pickup dates.
4. **Members** — connect existing WordPress users or add new members.
5. Move the cycle to **Ordering**; members order from the site.
6. Move to **Processing** to prepare orders, **Distribution** to hand them out,
   then **Complete**.

### The order cycle

Stages change only when staff change them; the Cycles list flags when a stage
is due.

| Stage | What happens |
|---|---|
| Setup | Configure dates and catalogue. Staff can create test orders, but they reserve real stock. |
| Ordering | Members order between the opening and closing dates. |
| Processing | Set products aside or pack baskets; print order slips. |
| Distribution | Hand out orders; record payment and pickup. |
| Complete | Final. Uncollected reservations are released; cannot be reopened. |

### Orders

Members can only edit **Draft** and **Submitted** orders. Staff can create and
edit orders in any active stage; changing a submitted order requires a reason,
except during pickup. Mark an order **Prepaid** when paid in full, or
**Fulfilled** when paid and handed over (this deducts stock once). Paid orders
are locked; unpaid orders can be cancelled or reopened with a reason.

The full detail — cycle workspace, financial aid, preparation and slips,
payment rules, membership fees, settings, emails — is in the
**[Staff guide](docs/staff-guide.md)**.

---

## For site administrators

### Install

Use a release ZIP (it includes the compiled `build/` directory), or build from
source:

```sh
npm ci
npm run build          # or: npm run plugin-zip
```

Copy the plugin into `wp-content/plugins/open-bulk-buying`, activate it under
**Plugins**, and open **Co-op**. Without `build/` the dashboard screens show a
notice explaining how to build them.

### Shortcodes

| Shortcode | Shows | Attributes |
|---|---|---|
| `[openbb_signup]` | Signup form (links to login) | `title`, `intro`, `login_redirect` |
| `[openbb_login]` | Login form (links to signup) | `title`, `intro`, `login_redirect` |
| `[openbb_order_form]` | Member order form; login/signup when logged out | `auth="signup"` to show signup first |

Example: `[openbb_signup title="Join the co-op" intro="We order together every month."]`

The catalogue is never shown to logged-out visitors. Themes can render the same
views with `openbb_render_signup( $args )` and `openbb_render_login( $args )`.

### Settings that affect the public site

In **Co-op → Settings → General**: **Allow signups** (off by default), **Allow
members to log in with an email code** (off), **Require approval** for new
signups (on), and **Bot protection** via
[Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) (off).
For Turnstile, add a widget in the Cloudflare dashboard and paste both keys;
to keep the secret out of the database, define
`OPENBB_TURNSTILE_SECRET` in `wp-config.php` instead.

### Email

All notifications go through `wp_mail()`; configure a reliable mail transport.
Failed sends are logged but never undo a saved order or payment.

### Language

Canadian French (`fr_CA`) is bundled. Set **Settings → General → Site
Language**, or let dashboard users choose under **Users → Profile**. Product
names, rules and custom field text are translated in their own editors.

### Data and uninstall

Deactivating keeps all co-op data. Uninstalling removes plugin-owned data.
**Settings → Data & Maintenance** offers a test mode with demo data and a
danger zone for wiping co-op data. Back up first.

---

## For developers

| Layer | Technology |
|---|---|
| Dashboard | React via `@wordpress/scripts`, `src/admin/` → `build/` |
| Public forms | PHP templates in `inc/frontend/templates/` + Interactivity API modules in `assets/js/` (no build step) |
| API | REST under `openbb/v1`; `inc/rest/admin/` and `inc/rest/frontend/` |
| Data | Custom tables (cycles, orders, lines, events, stock moves, rules versions), `openbb_product` post type, per-site user meta for members, options for settings |
| Access | Capabilities computed from staff roles via `user_has_cap` / `map_meta_cap` |
| Tests | PHPUnit with the WordPress test library (`composer test`); Node and Jest tests for a few UI helpers (`npm test`) |
| i18n | WP-CLI `i18n` + `scripts/compile-translations.js`; catalogues in `languages/` |

```sh
npm run start        # watch and rebuild the dashboard
npm run build
composer test        # PHPUnit; first run .\bin\install-wp-tests.ps1 (or .sh) once
npm test             # JS tests
composer lint        # WordPress Coding Standards
npm run i18n:extract && npm run i18n:update && npm run i18n:compile
```

Start with **[CONTRIBUTING.md](CONTRIBUTING.md)** for setup and an
end-to-end feature walkthrough, and **[Testing](docs/testing.md)** for how the
test suite works. The **[Developer guide](docs/developer-guide.md)** covers
repository layout, the data model and relationships, architecture conventions
(order policy, services, controllers), the i18n workflow and releasing. Every
`openbb_*` action and filter is listed in the
**[Hooks reference](docs/hooks.md)**.
