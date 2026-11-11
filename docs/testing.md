# Testing

How the test suite works, how to run it, and how to add to it.

For WordPress PHPUnit fundamentals, see the [WordPress testing docs](https://make.wordpress.org/core/handbook/testing/automated-testing/writing-phpunit-tests/#about-the-wordpress-phpunit-test-suite).

**Contents**

- [Setting up (once per machine)](#setting-up-once-per-machine)
- [Running tests](#running-tests)
- [Writing a test](#writing-a-test)
- [Which test covers what](#which-test-covers-what)
- [JavaScript tests](#javascript-tests)
- [Coding standards](#coding-standards)

---

## Setting up (once per machine)

You need: PHP, Composer, MySQL/MariaDB, and a WordPress installation to borrow
core files from — the one this plugin is installed in is fine. 

```sh
composer install                 # installs PHPUnit and the WordPress test library (vendor/wp-phpunit)
```

Then create the test database and config. Pick your platform:

**Windows (PowerShell)**

```powershell
.\bin\install-wp-tests.ps1
# or with explicit values:
.\bin\install-wp-tests.ps1 -DbName openbb_tests -DbUser root -DbPass '' -DbHost localhost
```

**macOS / Linux**

```sh
bin/install-wp-tests.sh                                   # defaults: openbb_tests root "" localhost
bin/install-wp-tests.sh openbb_tests root secret localhost /path/to/wordpress   # borrow core from an install
bin/install-wp-tests.sh openbb_tests root secret localhost 6.8                  # or download a version
```

Both scripts create the database (if the `mysql` CLI is available — otherwise
they print the SQL) and write `tests/wp-tests-config.php`, which is gitignored.
Edit that file if your credentials change.

> **The test database is dropped and recreated on every run.** Never point it
> at a database you care about.

Nothing else is required: no environment variables, no extra checkouts. If you
already have a `wordpress-develop` checkout you prefer, set `WP_TESTS_DIR` to its
`tests/phpunit` directory and the bootstrap will use it instead.

---

## Running tests

| You want to… | Command |
|---|---|
| Run everything (~1–2 min) | `composer test` |
| Run one class | `composer test -- --filter Test_OPENBB_Order_Lifecycle` |
| Run one method | `composer test -- --filter test_fulfillment_settles_payment_and_deducts_stock_once` |
| Multisite | `composer test:multisite` |
| JavaScript tests | `npm test` |
| Coding standards | `composer lint` / `composer lint:fix` |

The `--` passes everything after it to PHPUnit; any PHPUnit flag works
(`--stop-on-failure`, `--debug`, `--testdox` for readable names).

A failing test prints the assertion that failed and a stack trace. The first
frame inside `tests/` is the line to read.

---

## Writing a test

1. **Find the nearest existing test class** (see the map below) and add a
   method to it. New classes are rare.
2. **Name it as a sentence**: `test_cancelled_orders_release_stock`. The name is
   the documentation.
3. **Arrange → act → assert.** Set up the state (often already done in
   `set_up()`), call the one thing you're testing, assert on the result and any
   side effects (stock, status, emails, events).
4. **Test behaviour, not implementation.** Assert what a staff member would see
   — order status, stock numbers, a `WP_Error` code — not private internals.
5. **Reproduce bugs first.** When you find a bug by hand, write the shortest
   test that fails for the same reason, then fix the code. That's the one time
   you'll write a test from scratch, and it's usually a copy of a neighbour with
   different inputs.

Skeleton:

```php
public function test_prepaid_orders_cannot_be_cancelled(): void {
    $order  = $this->order();                                   // helper in this class
    $result = $this->confirm( $order, 'prepay' );
    $this->assertInstanceOf( OPENBB_Order::class, $result );

    $cancel = OPENBB_Order_Lifecycle::cancel( $result->id, 'Changed mind', $this->actor );
    $this->assertWPError( $cancel );
    $this->assertSame( 'openbb_order_locked', $cancel->get_error_code() );
}
```

Useful assertions: `assertSame` (strict `===`), `assertEquals` (loose, good for
floats from the DB), `assertWPError`, `assertNotWPError`, `assertCount`,
`assertArrayHasKey`, `expectException`.

Useful WordPress helpers: `wp_set_current_user( $id )`,
`self::factory()->…`, `rest_do_request( new WP_REST_Request( 'POST', '/openbb/v1/…' ) )`
for REST routes, `reset_phpmailer_instance()` / `tests_retrieve_phpmailer_instance()`
for sent emails.

Tests extending `OPENBB_TestCase` can use `make_user( 'coordinator' )` for an
active member with a staff role, `make_cycle( 'ordering' )`, and
`make_order( $cycle_id, $user_id )` for a submitted order. The **base class also
maps plugin transactions to MySQL savepoints**, via `nest_transaction` - a workaround that keeps plugin commits and rollbacks inside WordPress's per-test transaction so test data is rolled back afterward.

---

## Which test covers what

| Area | Test class (file) |
|---|---|
| Order eligibility rules | `Test_OPENBB_Order_Policy` (`test-order-policy.php`) |
| Payment, fulfilment, abandonment, stock | `Test_OPENBB_Order_Lifecycle` (`test-order-lifecycle.php`) |
| Order editor, corrections, emails | `test-order-emails.php`, `test-services.php` |
| Member order form and auth | `test-member-order.php`, `test-order-form-auth.php` |
| Preparation (set aside / baskets) | `test-cycle-preparation.php`, `test-order-preparation.php` |
| Financial aid | `test-cycle-aid.php` |
| Order slips | `test-order-slips.php` |
| Membership fees and payment claims | `test-membership-fees.php`, `test-rest-membership-fees.php`, `test-signup-membership-fee-claim.php` |
| Signup, login codes, captcha | `test-signup.php`, `test-signup-self-service.php`, `test-email-code.php`, `test-login.php`, `test-captcha.php` |
| Members, approval emails, profile fields | `test-member.php`, `test-member-approval-email.php`, `test-profile-fields.php`, `test-user-profile.php` |
| Roles, capabilities, meta caps | `test-roles.php`, `test-access.php`, `test-meta-caps.php`, `test-product-caps.php` |
| Co-op rules | `test-rules.php` |
| Settings | `test-general-settings.php` |
| Admin REST routes | `test-rest-admin.php` |
| Persistence / schema | `test-model-persistence.php` |
| Email templates | `test-email-templates.php` |
| Privacy exporters/erasers | `test-privacy.php` |
| Translations (POT/PO/MO/JSON) | `test-translations.php` |

When touching reservation or status logic, run `Order_Lifecycle`,
`Order_Policy`, `Cycle_Preparation` and `Model_Persistence` together — they
assert SQL and PHP agree on what "reserved" means.

---

## JavaScript tests

Two small runners exist because the code has two JS stacks (frontend and admin):

| Files | Runner | Command |
|---|---|---|
| `tests/*.cjs` | Node's built-in `node:test` (no dependencies) | `npm run test:node` |
| `tests/*.test.js` | Jest via `wp-scripts test-unit-js` (supports `jest.mock`, JSX imports) | `npm run test:jest` |

`npm test` runs both. They cover isolated helpers (label resolution, order tab
logic, form state); the React screens themselves are not unit-tested.

---

## Coding standards

`composer lint` runs PHP CodeSniffer with the WordPress Coding Standards, using
[phpcs.xml.dist](../phpcs.xml.dist). `composer lint:fix` applies the automatic
fixes (spacing, line endings, array formatting).

The ruleset turns off column-alignment sniffs and per-method docblock
requirements, registers the plugin's custom capabilities, and allows direct
`$wpdb` queries against the plugin's own tables. The current codebase still has
a backlog of findings; CI runs lint as **advisory** (non-blocking) until that
baseline is cleared. Run `composer lint -- --report=source` to see the summary
by rule.
