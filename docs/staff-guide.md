# Staff guide

How to run a co-op with Open Bulk Buying from the **Co-op** menu in the
WordPress dashboard. For installation and site setup see the
[README](../README.md).

**Contents**

- [Access and roles](#access-and-roles)
- [Products and stock](#products-and-stock)
- [Members](#members)
- [Cycles](#cycles)
- [Orders](#orders)
- [Financial aid](#financial-aid)
- [Preparing orders](#preparing-orders)
- [Printing order slips](#printing-order-slips)
- [Membership fees and payments](#membership-fees-and-payments)
- [Co-op rules](#co-op-rules)
- [Settings reference](#settings-reference)
- [Emails the plugin sends](#emails-the-plugin-sends)
- [Deleting data](#deleting-data)

---

## Access and roles

Every person has a **membership status** and may hold one or more **staff
roles**. Both are co-op concepts stored on the WordPress user; they do not
change the user's WordPress role.

| Membership status | Can order? | Typical meaning |
|---|---|---|
| Applicant | No | Signed up, awaiting approval. |
| Active | Yes | Member in good standing. |
| Inactive | No | Lapsed or paused. |
| Declined | No | Application refused. |

Staff roles grant permissions. Edit roles and their permissions in
**Settings → Staff roles**; assign them in **Co-op → Members** or
**Users → Profile → Co-op access**.

| Permission | Lets a staff member |
|---|---|
| Manage products | Create and edit products, categories, suppliers; upload images. |
| Manage inventory | Record stock received, counted and adjusted. |
| Manage cycles | Create cycles and move them through stages. |
| Delete cycles and orders | Permanently delete cycles/orders with their history. |
| View all orders | See every member's orders. |
| Edit orders | Change, cancel or create orders for members. |
| Process orders | Record prepared quantities for the cycle being processed. |
| Distribute orders | Hand out orders for the cycle being distributed. |
| Record payments | Mark orders as paid. |
| View members / Edit members / Add members | See, edit, approve and create members. |
| Export personal data | Download files containing members' personal data. |
| View aid requests / Decide aid | See, approve or decline aid requests. |
| Manage rules | Edit the co-op rules members agree to. |
| Manage settings | Change general settings. |
| Manage profile fields | Add and change the questions members answer. |
| Manage staff roles | Edit roles and give them to members. |

WordPress Administrators hold every permission. To *order*, an Administrator
still needs an Active membership.

---

## Products and stock

**Co-op → Products** holds the catalogue. Each product has a name,
description, brand, unit (and amount per unit), price, optional container
charge, image, categories, one supplier, an **Active** flag (inactive products
are hidden from the order form) and a **Preparation** method (see
[Preparing orders](#preparing-orders)). Names and descriptions can be entered
in each site language.

**Stock** is a ledger: receipts, adjustments, spoilage and distribution are
recorded as moves, and on-hand is their sum. Submitted and prepaid orders
*reserve* stock; it is deducted only when an order is fulfilled. Each product's
inventory panel shows on hand, held for cycles, and available.

**Display order.** Set a product's **Display order** in its details (stored in
WordPress's `menu_order`) and a category's order via **Categories and
suppliers → Edit category order**. Lower numbers sort first; ties sort by name.

---

## Members

**Co-op → Members** lists everyone with a membership status. Open a member to:

- change status (approve, decline, deactivate, reactivate);
- assign staff roles;
- edit phone, language and the co-op's custom profile fields;
- view order history and membership-fee status;
- record a membership payment collected separately.

Connect an existing WordPress user or create a new account; staff-created
accounts get WordPress's standard set-your-password email. Changing someone
from Applicant, Inactive or Declined to Active sends the welcome email once.

Custom profile questions are defined in **Settings → Profile fields**.

---

## Cycles

A cycle is one round of ordering and pickup. In **Co-op → Cycles**, click a
title for the quick-edit panel or **Manage cycle** for the full workspace
(**Details**, **Orders**, **Preparation**, **Financial Aid** tabs, each with a
bookmarkable URL).

| Stage | Members | Staff |
|---|---|---|
| **Setup** | — | Configure dates, pickup location and instructions. Test orders reserve real stock and send normal emails. |
| **Ordering** | Order between opening and closing dates. | Create and edit orders. |
| **Processing** | — | Set products aside or pack baskets; print slips. Preparation methods are frozen for the cycle. |
| **Distribution** | — | Hand out orders; record payment and pickup. No change reason needed. |
| **Complete** | — | Final. Reservations for uncollected orders are released. Cannot be reopened. |

Stages only change when staff change them. When a date has passed, the Cycles
list shows the stage that is due and the cycle page shows a reminder.

**Completing a cycle** records fixed hold deadlines and releases uncollected
reservations. Submitted or prepaid orders still uncollected at the deadline are
*abandoned*: their payment status is unchanged and no stock is deducted.
Closing ordering alone does not abandon orders.

**Preparation cleanup.** Draft and test orders created in Setup can be deleted
while the cycle is still "clean". The first real commitment outside Setup — a
submission, substantive change, cancellation, payment, fulfilment or aid
decision — permanently locks the cycle, after which cleanup is unavailable.
Note-only edits, draft autosaves and stage changes do not lock it.

---

## Orders

**Co-op → Orders** has **All**, **Cycle Orders** and **Membership Payments**
tabs. Cycle orders can also be managed from the cycle workspace.

| Status | Reserves stock | How it gets there |
|---|---|---|
| Draft | No | Created or autosaved; never committed. |
| Submitted | Yes | Committed by the member or staff. |
| Prepaid | Yes | Staff confirm full payment before pickup. Quantities locked. |
| Fulfilled | No (deducted once) | Staff confirm payment and handoff. Terminal. |
| Cancelled | No | Staff cancel an unpaid order with a reason. |

**Editing.** Members edit their own Draft and Submitted orders during
Ordering. Staff with **Edit orders** can create and edit orders in Setup,
Ordering, Processing and Distribution. Changing or cancelling a submitted
order asks for a **reason** (entered above **Save order**) except while the
cycle is in Distribution or inside the pickup window. Order quantities are
checked against available stock on every save.

**Prepaid and Fulfilled** are chosen from the **Status** dropdown and ask for
confirmation. If there are unsaved edits, they are saved first. Both are
available for any submitted/prepaid uncollected order at every stage,
including after the cycle is complete, so late payments and pickups can be
recorded. Fulfilling requires recorded stock to cover the order.
Zero-balance orders can be prepaid and fulfilled. Orders without a valid
currency snapshot must be corrected before settlement.

**Prepaid orders are locked**: quantities, amounts and cancellation cannot be
changed (refunds and adjustments are not yet supported).

**Reopen as draft** (cancelled orders in active cycles, with a reason)
restores items, prices, submission time and history as a draft that reserves
no stock. Orders with payment, collection, stock-ledger or aid history cannot
be reopened. Members cannot cancel or reopen their own orders.

**Notes.** Each order has a member note (visible to the member) and a staff
note (change reasons are appended to it). The staff note is included in order
emails unless **Include staff note in order receipts** is turned off.

---

## Financial aid

Members may request aid on their order and give a reason; any member may add
a **donation**. Both are entered on the order form and can be revised while
the order is submitted and unpaid, before aid is allocated, and before the
cycle is complete.

In the cycle workspace, **Financial Aid** lists requests. Click a member's
name to review the reason, status and decision date, then **Approve** or
**Decline** (requires **Decide aid**; **View aid requests** alone is
read-only). Decisions record who decided and when, and change no amounts.

**Distribute aid** splits the pool evenly as a percentage off goods:

```
percent = min( 100%, pool ÷ total approved goods )
```

- **Pool** = this cycle's submitted-order donations − aid already fixed on
  paid or collected orders.
- **Goods** = line totals after container credits. Membership fees and
  donations are never covered.
- Cents are rounded down per order; leftover cents go to the largest
  remainders so the pool is used exactly.

The percentage is previewed before you confirm. Distribution updates order
totals and can be rerun as donations or approvals change; paid and fulfilled
orders keep the aid they already received. It is unavailable in completed
cycles.

---

## Preparing orders

Each product has a **Preparation** method:

| Method | Meaning |
|---|---|
| Set aside by product | Gather the cycle's total from stock into a shared station. |
| Pack into member baskets | Pack each member's quantity individually. |
| Co-op default | Use the default from **Settings → General**. |

When a cycle enters Processing, each pre-ordered product's method is frozen for
that cycle (editable on the Preparation tab until a basket containing it is
packed).

- **Preparation tab** — pre-ordered products grouped by category with ordered
  quantity, on hand, held for this and other cycles, and available. Rows short
  on stock are red. Tick **Set aside** to record the confirmed quantity; the
  row is flagged if orders change afterwards. **Update stock** and **History**
  open the stock ledger.
- **Orders tab** — for basket products, a **Packed** checkbox marks a product
  packed for that order, sets its prepared quantity and locks the line.

Preparing records no stock moves; stock leaves inventory at fulfilment.
Requires **Process orders** while the cycle is processing or distributing.
Shortage substitutions are not yet implemented.

---

## Printing order slips

While a cycle is processing or distributing, the **Orders** tab has **Print
order slips**. Choose all orders or only those matching the current filters,
and paid, unpaid or both. Slips open in a new tab with the print dialog; use
**Save as PDF** for a file.

Each slip shows the member's name, order number, site title, cycle, pickup time
and place, products and quantities with a **Packed** checkbox, and **PAID** or
the amount due. No unit prices, phone or email. Slips are sorted by last name,
printed in each order's language, run consecutively with a dashed cut line,
and only split across pages when longer than one page.

A single slip prints from an order's row actions or **Print slip** in the order
editor. Slips exist for Submitted, Prepaid and Fulfilled cycle orders, at most
500 per batch. Paper size is set in **Settings → General → Printing**.

---

## Membership fees and payments

Configure in **Settings → General**: **One time** or **Recurring** (every *n*
paid cycle orders), with an amount in the co-op's currency. Zero means no fee.

**How fees are charged**

- When due, the fee is a separate, system-managed line on the member's cycle
  order. Its price is saved with the order; later settings changes do not
  rewrite it.
- Fees reserve no stock, need no preparation and receive no container credit.
  Financial aid never covers them.
- The first fee is due before any confirmed payment. For recurring fees, paid
  cycle orders (including prepaid) advance the count; unpaid, cancelled and
  membership-only orders do not.
- Staff with **Edit orders** can **Remove** the fee line from an editable
  submitted order; this neither records payment nor waives the fee.

**Recording a payment collected separately.** In the member's details, record
a membership payment with an optional reference (needs **Record payments**
and **Edit members**). This creates a cycle-free order containing only the fee
line, stored as Fulfilled and displayed as **Paid**. Recording it removes an
outstanding fee from the member's unpaid cycle orders and updates their totals.

**Payment claims at signup.** When a fee is configured, signup explains it and
offers an optional **I already paid** checkbox with a reference. This creates
a **Submitted** membership-only order awaiting verification — a claim, never
proof. A pending claim suppresses another fee on cycle orders. For an applicant
with a claim, staff can tick **Confirm claimed membership payment** while
approving to do both at once; rejecting a claim keeps it as history and makes
the fee due again.

**Reviewing.** **Co-op → Orders → Membership Payments** lists cycle-free
payments and claims with amount, submitted date, payment date and reference.
Payment confirmations are retry-safe; paid records cannot be unchecked.
Refunds and reversals are not yet implemented.

---

## Co-op rules

**Settings → Co-op Rules** holds the rules members agree to at signup. Add
rich-text items, reorder them, give each a default text and optional
translations (missing translations fall back to the default), then **Publish**.
Draft edits do not change what members see until published.

**Version history** (in the side panel) shows every published version with
author, date and acceptance policy. The first version requires acceptance;
translation-only changes do not require renewed acceptance. Published versions
cannot be edited or deleted.

Managers and **Rules editors** can manage rules; the Rules editor role grants
nothing else.

---

## Settings reference

**Settings → General**

| Setting | Effect | Default |
|---|---|---|
| Allow signups | Public signup form is active. | Off |
| Allow members to log in with an email code | Passwordless one-time codes on the login form. | Off |
| Require approval for new signups | New signups become Applicants rather than Active. | On |
| Bot protection | None or Cloudflare Turnstile for signup/login forms. | None |
| Membership fee | One time / Recurring, amount, orders between fees. | No fee |
| Default preparation method | Set aside by product or Pack into baskets. | Set aside |
| Printing → Paper size | Letter or A4 for order slips. | Letter in US/CA/MX/PH, else A4 |
| Order emails → Include staff note in order receipts | Staff note and change reasons appear in member emails. | On |

Other tabs: **Co-op Rules**, **Profile Fields** (custom member questions),
**Staff roles**, and **Data & Maintenance** (test mode with demo data; a
danger zone to delete co-op data, Administrators only).

---

## Emails the plugin sends

| Event | Recipient | Notes |
|---|---|---|
| Signup code | Visitor | 6-digit code; or an "account exists" notice. |
| Application pending / Welcome | New member | Welcome also sent when staff activate a member (once). |
| Login code | Member | Or a "use your password" notice for privileged accounts. |
| Order submitted / updated / prepaid / cancelled / fulfilled | Order owner | Product table, totals, member-visible fields; staff note if enabled. |
| Membership payment recorded | Member | Fee amount only. |

Not sent: draft autosaves, unchanged saves, staff-note-only edits, repeated
payment/fulfilment confirmations. Internal aid explanations are never emailed.
Emails use the order's language when a translation exists and end with
"Thank you!" and the site title.

Delivery uses WordPress mail; a failed send is logged and never undoes the
saved order or payment. There is no retry queue or PDF attachment.

---

## Deleting data

Staff with **Delete cycles and orders** (and Administrators) can permanently
delete any cycle or order, including order items and event history. Deleting a
cycle deletes all its orders. Stock-ledger entries are kept for inventory
accuracy, with their order/cycle references cleared. This cannot be undone —
back up first.
