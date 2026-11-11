# Contributing

Thanks for helping build Open Bulk Buying. This page gets you from a clean
clone to a merged change. The [Developer guide](docs/developer-guide.md)
explains the architecture; [Testing](docs/testing.md) explains the test suite.

## Set up

```sh
git clone https://github.com/skysarwer/my-food-coop.git open-bulk-buying
cd open-bulk-buying
composer install          # PHPUnit, WordPress test library, coding standards
npm ci                    # build tooling and JS tests
npm run build             # the dashboard needs build/ to load
```

Put the directory in a WordPress install's `wp-content/plugins/` (or symlink
it) and activate the plugin. Then create the test database and config:

```powershell
.\bin\install-wp-tests.ps1          # Windows
```

```sh
bin/install-wp-tests.sh             # macOS / Linux
```

Confirm everything works:

```sh
composer test
npm test
```

## Day-to-day

| Task | Command |
|---|---|
| Rebuild the dashboard as you edit | `npm run start` |
| PHP tests for one area | `composer test -- --filter Test_OPENBB_Order_Lifecycle` |
| All PHP tests | `composer test` |
| JS tests | `npm test` |
| PHP coding standards | `composer lint` (auto-fix: `composer lint:fix`) |
| JS/CSS lint | `npm run lint:js`, `npm run lint:css` |
| Translations after changing strings | `npm run i18n:extract && npm run i18n:update`, translate, `npm run i18n:compile` |

Frontend modules in `assets/js/` and all PHP need no build step.

## Before you open a pull request

1. Run the test classes for the areas you touched (see the
   [coverage map](docs/testing.md#which-test-covers-what)), or the whole suite.
2. If you added or changed behaviour, add or update a test. If you fixed a
   bug, add the test that would have caught it.
3. Run `composer lint` on the files you changed. The codebase has a known
   backlog of style findings; you don't have to clear it, just don't add to it.
4. New user-facing strings use the `open-bulk-buying` text domain and get
   extracted with `npm run i18n:extract`. Translating them to French is
   welcome but not required.
5. Update the docs that describe what you changed: `docs/staff-guide.md` for
   behaviour, `docs/hooks.md` for new filters/actions, `docs/developer-guide.md`
   for schema or architecture.
6. If you changed a table in a `schema()` method, bump
   `OPENBB_Installer::DB_VERSION`.

CI runs the PHP suite on PHP 7.4, 8.1 and 8.3, builds the JS and runs the JS
tests. Linters are advisory for now.

## Adding a feature end to end

Most features touch the same five layers, in this order. As an example, say
we're adding a per-order **pickup note** that members write and staff see.

1. **Data.** Add the column to `OPENBB_Orders::schema()`, the property to
   `OPENBB_Order`, and bump `DB_VERSION`. Add the field to `cast()` if it needs
   type coercion. Run `composer test -- --filter Model_Persistence`.

2. **Policy.** If the field has rules about *when* it can change, express them
   in `OPENBB_Order_Policy` (e.g. editable while the order is draft/submitted
   and the cycle isn't complete). Add a case to `test-order-policy.php`.

3. **Service.** Accept the field in `OPENBB_Order_Editor::apply()`: sanitize
   it, run it through the policy, persist it, and include it in the change
   summary so emails and events pick it up. Add a test to
   `test-services.php` or `test-order-emails.php` that saves the field and
   asserts on the saved order and the event.

4. **REST.** Expose it in the admin orders controller
   (`inc/rest/admin/class-openbb-rest-orders.php`) — in the schema, the
   response shape and the update handler — and in the frontend order route
   (`inc/rest/frontend/class-openbb-rest-order.php`) for members. Controllers
   only check capabilities and validate; they call the service for the write.
   Add a request/response assertion to `test-rest-admin.php`.

5. **UI.** Dashboard: add the control to
   `src/admin/screens/orders/editor.js`, disabled when the REST response says
   the field isn't editable. Member form: add the field to the Finalize step
   template (`inc/frontend/templates/order-basket.php`) and wire it in
   `assets/js/order.js`. Run `npm run build`, click through it, and run
   `npm test`.

6. **Docs and strings.** Describe it in `docs/staff-guide.md`, extract the new
   strings, and mention any new hook in `docs/hooks.md`.

Smaller changes skip layers — a new setting is steps 4–6 plus
`OPENBB_Settings`; a new email is `inc/emails/` plus a listener on
`openbb_order_saved`.

## Conventions

- PHP follows the WordPress Coding Standards (`phpcs.xml.dist`). Tabs, Yoda
  conditions, `array()` syntax is fine either way.
- Prefix everything global with `openbb_` / `OPENBB_`.
- Money is integer minor units; quantities are decimals; timestamps are UTC.
- Comments explain *why*. Code explains *what*.
- Keep authorization in REST controllers and transactional writes in services;
  see [Architecture conventions](docs/developer-guide.md#architecture-conventions).

## Reporting bugs

Open an issue with the WordPress and PHP versions, the plugin version, what you
did, what you expected, and what happened. If a test reproduces it, even
better.
