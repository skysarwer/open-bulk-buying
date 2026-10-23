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

## Order payment and fulfillment

Orders start as **Draft**, then become **Submitted** when committed. Submitted
and **Prepaid** orders reserve stock. Use **Mark prepaid** after confirming that
the full amount due is settled; this does not remove inventory. During a cycle's
**Distribution** stage, **Fulfill order** confirms settlement and handoff of the
listed quantities, deducts stock once, and marks the order **Fulfilled**.
Save edits before using either confirmation. Zero-balance orders are supported.

Prepaid quantities, amounts, and cancellations are locked until payment
adjustments/refunds are supported. Unpaid orders can be cancelled with a reason.
Cancelled orders are retained; only drafts without operational history can be
deleted.

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

Configure whether new signups need staff approval (Applicants) or are
automatically approved as Members. Approval is required by default. This
setting does not change existing members or accounts created by staff.

Membership fees can be **One time** or **Recurring**. Enter an amount in the
co-op's currency; recurring fees also specify the number of orders between fees.
An amount of zero means no membership fee. The signup form shows the fee, but
fees are not charged yet.

## Member signup

Add the `[openbb_signup]` shortcode to a page to show the signup form. It
accepts optional attributes:

- `title`: a heading shown above the form.
- `intro`: a short plain-text introduction.
- `redirect_to`: a URL on this site to continue to after joining (defaults to
  the current page).

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
They receive an email confirming either outcome. If someone signs up with an
email that already has an account, the form behaves the same way, but the
email asks them to log in instead of sending a code.

The signup routes are rate-limited per IP address. Co-ops whose members share
one address can raise the limits with the `openbb_rate_limit` filter. Behind a
reverse proxy, supply the real client address with `openbb_client_ip`. Signup
emails (code, account exists, welcome) can be changed with the
`openbb_signup_email` filter.

Themes and other plugins can show the same view on any member-only screen with
`openbb_render_signup( array( 'title' => ..., 'intro' => ..., 'redirect_to' => ... ) )`.

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
