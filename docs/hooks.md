# Hooks reference

Every action and filter Open Bulk Buying exposes. All names are prefixed
`openbb_`. Filters return the first argument; actions return nothing.

**Contents**

- [Orders and cycles](#orders-and-cycles)
- [Members and access](#members-and-access)
- [Signup, login and bot protection](#signup-login-and-bot-protection)
- [Email](#email)
- [Localization and currency](#localization-and-currency)
- [Order form](#order-form)

---

## Orders and cycles

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `openbb_order_saved` | `OPENBB_Order $order, ?OPENBB_Order $before` | After any order write (editor, lifecycle, membership fees). `$before` is `null` for new orders. Drives order emails. |
| `openbb_order_submitted` | `OPENBB_Order $order` | When a member submits from the order form (status becomes `submitted`). |
| `openbb_order_corrected` | `OPENBB_Order $order, OPENBB_Order $before, string $reason, int $actor_id` | When staff change a committed order; `$reason` may be empty for drafts. |
| `openbb_order_email_failed` | `OPENBB_Order $order, string $event` | When `wp_mail()` fails for an order email. The order/payment is already saved. |
| `openbb_cycle_status_changed` | `OPENBB_Cycle $cycle, string $old_status, int $actor_id` | After a cycle changes stage. Preparation sync listens here. |

### Filters

| Hook | Signature | Purpose |
|---|---|---|
| `openbb_currency` | `( string $code ) : string` | Override the co-op's ISO 4217 currency (default from the `openbb_currency` option, `CAD`). |

---

## Members and access

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `openbb_member_created` | `int $user_id, string $context, int $actor_id` | New membership; `$context` is `signup` or `staff_create`; `$actor_id` is 0 for self-signup. |
| `openbb_member_status_changed` | `int $user_id, string $status, string $old, int $actor_id` | Membership status change (applicant / active / inactive / declined). Welcome emails listen here. |
| `openbb_member_roles_changed` | `int $user_id, string[] $roles, string[] $old, int $actor_id` | A member's staff roles changed. |
| `openbb_roles_config_changed` | `array $new, array $old, int $actor_id` | Staff-role definitions or permissions saved. |
| `openbb_rules_accepted` | `int $user_id, int $version_id` | A member accepted a rules version. |
| `openbb_profile_fields_saved` | `array $schema, array $old, int $actor_id` | Profile-field definitions saved. |
| `openbb_profile_values_changed` | `int $user_id, array $values, array $old, string $context, int $actor_id` | A member's profile-field answers changed (changed keys only). |

### Filters

| Hook | Signature | Purpose |
|---|---|---|
| `openbb_capabilities` | `( array $registry ) : array` | Add or alter capabilities (`cap => [ group, requires, wp_caps ]`). |
| `openbb_default_roles` | `( array $roles ) : array` | Built-in role definitions (`role => string[] caps`) before custom overrides apply. Unknown caps are dropped. |
| `openbb_capability_labels` | `( array $labels ) : array` | Labels and descriptions shown in Settings → Staff roles. |
| `openbb_default_role_labels` | `( array $labels ) : array` | Display names of the built-in roles. |
| `openbb_profile_fields` | `( array $fields, string $context ) : array` | Alter profile-field definitions per context (signup, member edit, …). |
| `openbb_profile_field_value_valid` | `( bool $valid, array $field, mixed $value, string $context ) : bool` | Extra validation for a sanitized field value. |
| `openbb_can_email_member` | `( bool $can, int $user_id ) : bool` | Suppress plugin emails to a member. |
| `openbb_privacy_providers` | `( OPENBB_Privacy_Provider[] $providers ) : array` | Register additional personal-data exporters/erasers for the WordPress privacy tools. |

---

## Signup, login and bot protection

### Filters

| Hook | Signature | Purpose |
|---|---|---|
| `openbb_login_code_allowed` | `( bool $allowed, WP_User $user ) : bool` | Who may log in with an emailed code. Default: anyone who cannot `edit_others_posts` and is not a super admin. Sites with 2FA requirements must exclude affected accounts here. |
| `openbb_login_link_allowed` | `( bool $allowed, WP_User $user ) : bool` | Deprecated alias feeding into `openbb_login_code_allowed`; use the new name. |
| `openbb_rate_limit` | `( int $limit, string $bucket ) : int` | Raise or lower a public-route rate limit; return 0 to disable that bucket. Useful when members share one IP. |
| `openbb_client_ip` | `( string $ip ) : string` | Supply the real client IP behind a reverse proxy (default `REMOTE_ADDR`). |
| `openbb_captcha_field` | `( string $html, string $action ) : string` | Widget markup for the `signup` or `login` form. The plugin wraps it in `[data-openbb-captcha]`; every named control inside is posted as the `captcha` object, and an `openbb:captcha-reset` event fires on the wrapper after each attempt. |
| `openbb_captcha_verify` | `( ?bool\|WP_Error $result, array $fields, string $action, WP_REST_Request $request )` | Return `true` to accept, `WP_Error` to refuse, `null` to fall through to the built-in Turnstile check. |

Password logins use `wp_signon()`, so core `authenticate` hooks (2FA, other
captchas) still apply. Email-code logins bypass `authenticate`.

---

## Email

### Filters

| Hook | Signature | Purpose |
|---|---|---|
| `openbb_signup_email` | `( array $email, string $type, int $user_id ) : array` | `$email` has `to`, `subject`, `message`, `headers`. `$type` is `code`, `account_exists`, `welcome_applicant` or `welcome_member`. `$user_id` is 0 before the account exists. |
| `openbb_login_email` | `( array $email, WP_User $user, string $code_or_page ) : array` | Login-code email, or the password-required notice (then the third argument is the login page URL). |
| `openbb_email_signature` | `( string $text ) : string` | Plain-text signature appended to every plugin email (default "Thank you!" + site title). Newlines are kept; HTML emails escape it. Return `''` to omit. |

Message bodies live in `inc/emails/*.php`; override wording there or via these
filters. Core `login_redirect` is applied to post-login redirects.

---

## Localization and currency

### Filters

| Hook | Signature | Purpose |
|---|---|---|
| `openbb_languages` | `( string[] $languages ) : string[]` | Languages offered for localized content (product names, rules, labels). |
| `openbb_current_language` | `( string $language ) : string` | Language used to resolve localized content for the current request. |
| `openbb_currency` | see above | |

---

## Order form

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `openbb_order_form_questions` | `OPENBB_Cycle $cycle, ?OPENBB_Order $order` | Inside the order form's Finalize step; print extra questions (polls, pickup preferences). The plugin does not collect or store their values — the extension must submit and persist them itself. |

---

## Template functions

Not hooks, but public PHP entry points for themes:

```php
openbb_render_signup( array( 'title' => '', 'intro' => '', 'login_redirect' => '' ) );
openbb_render_login(  array( 'title' => '', 'intro' => '', 'login_redirect' => '' ) );
```

Constants: `OPENBB_TURNSTILE_SECRET` (in `wp-config.php`) overrides the stored
Turnstile secret key.
