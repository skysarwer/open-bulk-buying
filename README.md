# Open Bulk Buying

Open Bulk Buying is a WordPress plugin for food co-ops. It gives co-op staff a
place in the WordPress dashboard to manage products, stock, ordering cycles,
members and orders, with co-op access kept separate from WordPress user roles.

## What it does

From the **Co-op** menu in the WordPress dashboard, staff can:

- Maintain a product catalogue and track stock receipts, adjustments, spoilage
  and distribution.
- Create and manage order cycles.
- Maintain co-op member records and review members' order history.
- Create and manage orders, with quantities limited by available stock.
- Configure co-op settings and member profile fields.

Each member has a membership status: Applicant, Active, Inactive or Declined.
Active members can order. Team roles give members access to the co-op's admin
screens: Coordinator, Manager, Aid reviewer, Distribution helper, Rules editor,
or roles you create. Team roles are not WordPress roles, so giving someone a
team role does not change their WordPress role.

Manage team roles and their permissions in **Co-op > Settings > Team roles**.

## Requirements

- WordPress 6.6 or later
- PHP 7.4 or later

## Install the plugin

Use a release ZIP that includes the compiled `build/` directory, or prepare the
plugin from its source:

1. Install Node.js and npm.
2. In the plugin directory, install the JavaScript dependencies and build the
   admin screens:

   ```sh
   npm ci
   npm run build
   ```

3. Copy the plugin directory, including `build/`, into
   `wp-content/plugins/open-bulk-buying`, or create an installable ZIP with:

   ```sh
   npm run plugin-zip
   ```

4. In WordPress, go to **Plugins**, activate **Open Bulk Buying**, and open the
   new **Co-op** menu.

The plugin's dashboard screens need the built files in `build/`. If the build
has not been generated or included in the installed plugin, the Co-op screens
will display a notice explaining how to build them.

## Translations

Canadian French (`fr_CA`) interface translations are bundled in `languages/`,
including the editable PO catalog, compiled PHP MO catalog, and JavaScript JSON
catalog. Select **Français du Canada** in **Settings > General > Site Language**
for the public site. Dashboard users can also select their own language under
**Users > Profile**. Changing the language does not translate stored product
names, rules, or custom field content; enter those translations separately in
the co-op's content editors.

To maintain translations, install [WP-CLI](https://wp-cli.org/) with its i18n
commands, then run from the plugin directory:

```sh
npm run i18n:extract
npm run i18n:update
```

Open `languages/open-bulk-buying-fr_CA.po` in Poedit (or another gettext editor),
translate new messages, and review fuzzy entries. Keep placeholders such as
`%s` and `%1$d`, HTML tags, and both plural forms intact.

After saving the PO catalog:

```sh
npm run i18n:compile
```

The compiler retains JavaScript messages in the PO and maps their source files
to the shipped `build/admin/index.js` bundle. Saving an MO file in Poedit alone
does not update JavaScript translations. Commit the POT, PO, MO and JSON files,
and include `languages/` in releases. Check the member forms, dashboard, emails,
plurals, and English fallback before shipping. Poedit is optional; WP-CLI handles
extraction and compilation.

With the disposable WordPress test database configured (see Development below), run:

```sh
php vendor/phpunit/phpunit/phpunit --filter Test_OPENBB_Translations
```

These tests verify complete POT-to-PO coverage with no empty or fuzzy
translations, placeholders, HTML tags, plural forms, MO/JSON parity, and
WordPress locale switching for PHP, forms, emails and dashboard scripts.
Catalog entry order does not affect coverage; WP-CLI may append new messages
when updating the PO.

## First-time setup

1. **Give trusted staff co-op access.** As a WordPress Administrator, go to
   **Users > All Users**, edit a user's profile and find **Co-op access**.
   Assign a **Manager** to the person who will configure the co-op and manage
   access. Assign **Coordinator** to staff who need day-to-day management.
2. **Add products and stock.** Open **Co-op > Products**, add the products your
   co-op offers, then record the starting quantities in each product's
   inventory.
3. **Set up an order cycle.** Open **Co-op > Cycles** and enter the cycle's
   ordering dates and other details.
4. **Add members.** Open **Co-op > Members** to connect an existing WordPress
   user or create a new member account. Review the member's status, team roles
   and profile information.
5. **Manage orders.** Use **Co-op > Orders** to review, create or edit orders.
   When changing a submitted order or cancelling it, staff are asked to provide
   a reason.

### Order emails

The owning member receives an HTML email when an order is first submitted
(whether by the member or staff), updated, marked as prepaid, cancelled or
fulfilled. Draft creation/autosaves, unchanged saves, internal staff-note edits
and repeated payment/fulfillment confirmations do not send emails.

Update emails contain a Before/After table of changed lines and member-visible
fields only, including changed totals. Other events contain a photo-free table
of product/package snapshots, quantities, unit prices (including container
charges), container credits and net line totals, followed by the order totals.
Internal staff notes and financial-aid explanations are never emailed.
Currency and product descriptions come from the order snapshots; email labels
use the order language when a matching translation is available.

Delivery uses WordPress `wp_mail()`, so configure and monitor your site's mail
transport. A failed send is logged with order ID, revision and event and fires
`openbb_order_email_failed( $order, $event )`; it does not undo the saved order or
payment. There is no automatic retry queue or PDF attachment in this release.

## Cycle management

In **Co-op > Cycles**, click a cycle title for its quick-view/edit panel. The
prominent **Manage cycle** button near the top opens its full-page workspace;
the list's **Manage** action does too. **Details** and **Financial Aid** have
bookmarkable URLs. Creation and duplication remain in the side panel.
Navigation prompts before discarding unsaved edits.

Financial Aid opens as a list. Click a member's name or **View request** to
review the request reason, status, and decision date in a side panel. Approve
and decline actions live in that panel and ask for confirmation. A table layout
is also available. Refreshing requests closes the panel so stale details are
not left visible.

Cycle managers can edit details. Staff with **View aid requests** can access the
workspace and its Financial Aid section without gaining cycle-editing access.
**Decide aid** permits approving or declining another member's request. Decisions
can be revised while the order is submitted and unpaid, before aid allocation,
and before the cycle is complete. Draft requests are not shown; paid, fulfilled,
cancelled, and already allocated requests are read-only. Decisions record the
staff actor and date, reject stale revisions, and leave all order amounts unchanged.

Approval means eligibility, not an allocated discount. Percentage-based allocation
from available funding is not implemented here. Polls, packing, shifts, and a
distribution-screen launcher are intentionally absent until those workflows ship.

## Catalog display order

In **Co-op > Products**, set a product's **Display order** in its details.
This uses WordPress's native `menu_order` column, not product meta. The product
table also supports sorting by Display order; its default name sorting is unchanged.
Use **Categories and suppliers > Edit category order** to update an existing
category, or set its order when adding it. Category order is stored in
`openbb_order` term meta because WordPress has no native taxonomy display-order
field. Lower integers sort first (including negative values); missing values
default to zero. Ties sort by name, then ID. The categories API returns `parent`
along with `order` so a future order form can preserve the category hierarchy.

## Order payment and fulfillment

Orders start as **Draft**, then become **Submitted** when committed. Submitted
and **Prepaid** orders reserve stock. Use **Mark prepaid** after confirming that
the full amount due is settled; this does not remove inventory. During a cycle's
**Distribution** stage, **Fulfill order** confirms settlement and handoff of the
listed quantities, deducts stock once, and marks the order **Fulfilled**.
Save edits before using either confirmation. Zero-balance orders are supported.

Prepaid quantities, amounts, and cancellations are locked until payment
adjustments/refunds are supported. Unpaid orders can be cancelled with a reason.
Members see a read-only cancellation summary rather than a disabled checkout form.
Staff with order-edit permission can use **Reopen as draft** on cancelled orders
in active cycles, with confirmation and a required reason. This preserves the
order's items, price snapshots, submission timestamp and audit history. Drafts
reserve no stock; resubmission checks availability again. Paid, collected,
stock-history-bearing, and aid-allocated orders cannot be reopened.
System-managed membership fee lines are retained when reopening, are not treated
as stock products, and are synchronized again during normal editing/submission.
Members cannot cancel or reopen orders themselves; they must contact the co-op.

**Deleting cycles and orders:** Managers and team roles with **Delete cycles and
orders** can permanently delete any cycle or order, including its order items and
event history. Deleting a cycle also deletes all of its orders. Stock-ledger
entries are retained for inventory accuracy, with references to deleted orders
and cycles removed. Administrators always have this permission. This action
cannot be undone.

Members can order only during **Ordering** and within its ordering dates. Staff
can create and submit orders during Setup, Ordering, Processing and Distribution.
The first commitment or substantive update to a committed order outside Setup
permanently sets the cycle's `cleanup_locked_at` timestamp, including cancellation
of a submitted order outside Setup. Payment/fulfillment
and aid decisions on committed orders outside Setup also lock cleanup. Draft
autosaves, note-only edits, and merely changing cycle stage do not. Returning to
Setup or cancelling/deleting an order cannot clear the lock. Setup is preparation,
not an isolated test environment: submitted orders reserve real stock and normal
order emails still apply.

On upgrade, cycles with existing submission, cancellation or operational history
are conservatively locked because old events do not record the cycle stage.
That timestamp means "protected since migration", not the original order date.
There is no UI override to reclassify historical orders as tests; existing test
data requires separately reviewed cleanup. If the upgrade fails, preparation
cleanup stays disabled, the failure is logged, and the next request retries it.

Marking a cycle **Complete** finalizes distribution, records fixed hold deadlines,
and immediately releases its uncollected orders' reservations. Submitted/prepaid
orders remaining at that deadline are **abandoned**; their payment status remains
unchanged and no stock is deducted for goods not handed over. Closing ordering
alone does not abandon orders. Complete cycles cannot be reopened.

Orders without a valid currency snapshot must be corrected before settlement
can be confirmed; the payment action never guesses their currency.

See [the lifecycle roadmap](ROADMAP.md) for configurable prepaid holds,
cancellation policies, refunds, pickup exceptions, reporting, and privacy work.

An Administrator already has co-op staff permissions. To place orders as a
co-op member, an Administrator also needs an Active membership. Members can
order for themselves; Applicants, Inactive and Declined users cannot order.

## General settings

In **Co-op > Settings > General**, turn on **Allow signups** to let people
join from the website (see [Member signup](#member-signup)). Signups are off
by default.

Turn on **Allow members to log in with an email code** to offer a one-time
email code instead of a password (see [Member login](#member-login)). Email-code
login is off by default. This is passwordless login, not enforced two-factor authentication.

Under **Bot protection**, choose **Cloudflare Turnstile** to make the public
signup and login forms check that visitors are human (see
[Bot protection](#bot-protection)). Bot protection is off by default.

Configure whether new signups need staff approval (Applicants) or are
automatically approved as Members. Approval is required by default. This
setting does not change existing members or accounts created by staff.

Membership fees can be **One time** or **Recurring**. Enter an amount in the
co-op's currency; recurring fees also specify the number of orders between fees.
An amount of zero means no membership fee. When a fee is due, it appears as a
separate, system-managed line on the member's cycle order. Its price is saved
with the order; later settings changes do not rewrite that price. Fees do not
reserve stock, need preparation, or receive container credits. Financial aid
reduces goods only, not membership fees or donations.

The first fee is due before any confirmed membership payment. For recurring
fees, **paid cycle orders count, including prepaid orders awaiting pickup**.
Unpaid, cancelled, and membership-only orders do not advance the count. A fee
paid on a cycle order covers that order and the remaining orders in its
interval. A standalone payment covers the next interval of cycle orders.

### Recording and reviewing membership payments

In **Co-op > Members**, open a member's details to record a membership payment
collected separately, with an optional reference. Staff need both permission to
record payments and permission to edit that member. This creates a cycle-free
order containing a membership-fee line. Confirmed membership-only orders are
stored as **Fulfilled** and displayed as **Paid**, without a physical collection
or inventory movement. The member screen includes the fee status and history.

When a fee is configured, signup first explains that it will be charged on the
first order (and the interval for recurring fees). An optional **I already paid**
checkbox reveals an optional payment reference only when selected.
This is a claim, never proof of payment: after successful signup it
creates a **Submitted** membership-only order awaiting staff verification.
Verification is required whether membership approval is enabled or not.

For an applicant with a claim, authorized staff can select **Confirm claimed
membership payment** (off by default) and approve membership and payment in one
action. Both changes commit together, with separate audit events. Membership
can also be approved separately, leaving the claim pending. Active members
remain active while payment verification is pending.

A pending claim suppresses another membership charge on cycle orders. Confirming
it records payment; rejecting it retains the cancelled claim as history and
makes the fee due again. Recording a separate payment removes an outstanding
fee from unpaid cycle orders, updates their totals and revisions, and records
the reconciliation. Paid orders are not rewritten. If fee eligibility changes
before a cycle order is paid, staff must save and review the updated order
before confirming payment.

Payment confirmations are retry-safe. Paid membership records cannot be
unchecked or erased through a toggle; refunds and payment reversals are not
implemented in this release.

## Member signup

Add the `[openbb_signup]` shortcode to a page to show the signup form. It
accepts optional attributes:

- `title`: a heading shown above the form.
- `intro`: a short plain-text introduction.
- `login_redirect`: a URL on this site to continue to after joining or logging
  in (defaults to the current page). `redirect_to` is still accepted as an
  alias.

Visitors who already have an account can switch to the login form from the
signup form (see [Member login](#member-login)).

```text
[openbb_signup title="Join the co-op" intro="Members order together every month."]
```

Visitors enter their details, any member profile fields, and agree to the
latest co-op rules. They then receive a 6-digit code by email. Their account
is only created, and they are logged in, once they enter the code. Someone who
is already logged in but isn't a member can join without a code. Details
already on their account are shown but can't be changed. People who already
have a membership status see it instead of the form. When signups are off, the page
shows a notice.

New signups become Applicants or Members, depending on the approval setting.
They receive an email confirming either outcome. When staff change an Applicant,
Inactive or Declined member to Active, the member receives the welcome email
with login instructions and confirmation that they can order. Saving an already-active member does not send
it again. Welcome emails respect the member email eligibility filter and use
the `welcome_member` type of the `openbb_signup_email` filter. A mail failure is
logged without undoing the activation; delivery still requires working WordPress
mail configuration.

If someone signs up with an
email that already has an account, the form behaves the same way, but the
email asks them to log in instead of sending a code.

The signup routes are rate-limited per IP address. Co-ops whose members share
one address can raise the limits with the `openbb_rate_limit` filter. Behind a
reverse proxy, supply the real client address with `openbb_client_ip`. Signup
emails (code, account exists, welcome) can be changed with the
`openbb_signup_email` filter.

All plugin-authored email subjects and bodies live in `inc/emails/`: signup
codes, existing-account notices, separate `application-pending.php` and
`welcome.php` messages (including activation), login codes and password-required
notices. Services still handle
recipients, eligibility, sending and the existing `openbb_signup_email` and
`openbb_login_email` filters. Staff-created accounts continue to use WordPress's
standard set-your-password notification, whose template is owned by WordPress.

Themes and other plugins can show the same view on any member-only screen with
`openbb_render_signup( array( 'title' => ..., 'intro' => ..., 'login_redirect' => ... ) )`.

## Member login

Add the `[openbb_login]` shortcode to a page to show a login form. It accepts
the same optional `title`, `intro` and `login_redirect` attributes as the
signup shortcode.

```text
[openbb_login title="Welcome back" login_redirect="/shop/"]
```

People log in with their username or email and password. When **Allow members
to log in with an email code** is on, they can request a six-digit code instead.
They enter or paste it in the same tab, even when checking email on another device.
Codes work once, expire after 15 minutes, and are bound to the requesting browser
by an HttpOnly cookie. Reloading restores the pending code screen. Resending has
a 60-second cooldown, replaces the previous code without extending the expiry,
and is limited to five sends per challenge; five incorrect attempts lock a code.
Turning the setting off also stops pending codes from working.
The login form links to the signup form, and the signup form links
back, on the same page. People who are already logged in see who they are
logged in as, with a link to continue and a log-out link.

Password logins go through `wp_signon()`, so plugins that hook WordPress
authentication (for example two-factor plugins) still apply. Email-code logins skip
that step, so by default editors and administrators (anyone who can edit
others' posts, plus network super admins) can't use them. When one of them
asks for a code, the form responds as usual, but the email tells them to log
in with their password instead, with a password reset link. The
`openbb_login_code_allowed` filter can change who may use codes. Sites requiring
additional authentication for other accounts must exclude those accounts using
this filter, or leave email-code login disabled; it does not enforce 2FA. The core
`login_redirect` filter is
applied to the redirect, and the login email can be changed with the
`openbb_login_email` filter (its third argument is now the code, or the login page
for password-required notices). The login routes are rate-limited per IP address
like the signup routes.

The former `allow_login_links` preference is read as `allow_login_codes` until
settings are next saved, preserving whether passwordless login was enabled.
The link routes, URL tokens and confirmation screen have been removed.
The old `openbb_login_link_allowed` policy filter remains a compatibility input
to `openbb_login_code_allowed` so existing account restrictions aren't silently lost.
New integrations should use `openbb_login_code_allowed`.
Previously emailed login links no longer work; request a code instead.

Themes and other plugins can show the same view with
`openbb_render_login( array( 'title' => ..., 'intro' => ..., 'login_redirect' => ... ) )`.

## Member order form

Add the `[openbb_order_form]` shortcode to a page to let active members order from a
cycle that's taking orders. Logged-out visitors see the login form there, with a
button to switch to signup. Use `auth="signup"` to show the signup form first:

```text
[openbb_order_form auth="signup"]
```

The catalog is never shown to logged-out visitors. After logging in or signing up
on the order form, the page reloads to the form, or to the member's application
status while approval is pending. Signing up there skips the "You're signed up"
screen.

## Bot protection

The signup and login forms can use [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/),
a free check that is usually invisible to people. The site doesn't need to use
Cloudflare otherwise.

1. In the Cloudflare dashboard, open **Turnstile** and add a widget for the
   site's domain. Cloudflare gives a site key and a secret key.
2. In **Co-op > Settings > General > Bot protection**, choose **Cloudflare
   Turnstile** and enter both keys. Both keys are required.

The secret key is never sent back to the browser; leave the field empty to
keep the saved one. To keep it out of the database, define it in
`wp-config.php` instead, which overrides the saved one:

```php
define( 'OPENBB_TURNSTILE_SECRET', 'your-secret-key' );
```

When it's on, the check is required to:

- start a signup (visitors only; logged-in people joining aren't asked),
- log in with a password,
- send or resend a login code.

Entering emailed signup and login codes isn't checked;
verification relies on the browser-bound code and rate limits. If Cloudflare
can't be reached, or rejects the secret key, the forms show an error and the
problem is written to the PHP error log. For local testing, Cloudflare's
test keys (site key `1x00000000000000000000AA`, secret key
`1x0000000000000000000000000000000AA`) always pass.

Other providers (for example reCAPTCHA or hCaptcha) can plug in with two
filters, leaving the built-in provider set to **None**:

- `openbb_captcha_field( $html, $action )` returns the widget markup for the
  `signup` or `login` form. The plugin wraps it, and the browser sends every
  named form control inside it to the REST route as the `captcha` object.
  After each attempt, the browser dispatches an `openbb:captcha-reset` event on
  the wrapper (`[data-openbb-captcha]`), so the widget can reset itself.
- `openbb_captcha_verify( $result, $fields, $action, $request )` returns
  `true` to accept, a `WP_Error` to refuse, or `null` to use the built-in
  check. `$fields` holds the widget's form controls, name => value.

Password logins go through `wp_signon()`, so a separate captcha plugin that
hooks WordPress authentication (`authenticate`) for wp-login.php may also
block these logins, because their form doesn't include its widget. Use this
plugin's bot protection instead, or exclude REST requests in that plugin.

## Co-op rules

In **Co-op > Settings > Co-op Rules**, add rich-text rules and use the up/down
buttons to order them. Each rule has default text and optional translations;
missing translations fall back to that rule's default text. Save the draft
before publishing. Draft edits do not change the rules shown to members.

Published content is stored as an ordered array of localized HTML items and
rendered as an ordered list. Existing single-body content is read as one item
without modifying historical records. **Version history** provides read-only
views of every published version, with its author, date and acceptance policy.
History appears in the side panel; select **View version** to open its details,
then use **Back to Version history** to return to the list. Browsing history
keeps any unsaved draft edits intact. Reopen a closed panel with **Version history**.
Published versions cannot be edited or deleted. The first version requires
acceptance; translation-only changes do not require renewed acceptance.

Managers and users with the **Rules editor** role can manage rules. That role
does not give access to the other settings tabs.

## Data and deactivation

Deactivating the plugin keeps co-op data so it is available if the plugin is
activated again. Uninstalling removes plugin-owned co-op data. Back up your
WordPress site before uninstalling or using any data-removal action in settings.

## Development

The JavaScript and styles for the WordPress dashboard are in `src/admin/` and
are compiled into `build/`, along with the frontend stylesheet
(`src/sass/frontend.scss` to `build/frontend.css`):

```sh
npm ci
npm run build
npm run start
```

`npm run start` watches the source files and rebuilds as you work.

Frontend views such as the signup form are rendered by PHP templates in
`inc/frontend/`. They are made interactive with the WordPress Interactivity API,
using script modules in `assets/js/` that are loaded as they are, with no build
step. REST controllers are split into `inc/rest/admin/` (dashboard screens) and
`inc/rest/frontend/` (routes visitors and members use).

For PHP integration tests, see `tests/` and the PHPUnit configuration files.
Tests use the WordPress test library and must be configured with a separate,
disposable test database. Set `WP_TESTS_DIR` to the library's `tests/phpunit`
directory, then run `vendor/bin/phpunit`.

Order eligibility lives in `OPENBB_Order_Policy`: services enforce it and REST
exposes its decisions to the admin UI. Keep authorization in controllers and
transactional writes in the editor/lifecycle services. Inventory SQL and PHP
checks share committed/active status definitions and the cycle's hold-field
mapping; reservation tests must keep both representations in agreement.
