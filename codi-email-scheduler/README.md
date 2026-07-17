# Codi Email Scheduler

A lightweight WordPress plugin for event + delay + condition email scheduling using Action Scheduler.

## What it does

- Lets code register email events and their WordPress/WooCommerce hook triggers with `ces_register_event()`.
- Lets code register conditions that are checked before queueing and rechecked before delivery with `ces_register_condition()`.
- Lets code register tokens with `ces_register_token()`.
- Lets code fire events with `ces_fire_event()`.
- Includes generic WordPress and WooCommerce events/conditions, including registered-user abandoned basket checks.
- Lets administrators listen to a custom WordPress action hook without writing an event registration.
- Lets admins create, import, enable, disable, and bulk-delete scheduled emails in **Tools > Email Scheduler**.
- Lets one email have multiple delays, including `0 minutes`.
- Supports delay test mode for development/staging, so long delays can be temporarily scheduled sooner without changing saved email configuration.
- Uses Action Scheduler for all queueing and execution.
- Sends through `wp_mail()`, so existing SES integrations continue to handle delivery.
- Stores email configuration and settings in non-autoloaded WordPress options.
- Provides a read-only CES queue view backed directly by Action Scheduler; CES creates no custom database tables.
- Supports explicit current-state retrospective enrollment for user-based events.
- Uses a lightweight class autoloader so admin/WooCommerce classes are loaded only when relevant.
- Creates no custom post types.


## Recipient scope

CES sends one `wp_mail()` call per resolved recipient. Built-in events resolve to one user or customer recipient. Integrations targeting a large audience should fire one event occurrence per recipient so each delivery has its own Action Scheduler action and failure boundary.

## Requirement

Requires WordPress 6.8 or newer and the standalone Action Scheduler plugin version 4.0.0 or newer, installed as `action-scheduler/action-scheduler.php`. CES does not rely on WooCommerce's bundled copy as its dependency source.

If the required plugin is inactive, outdated, unavailable, or not initialized in the current request, the admin UI shows a warning and scheduling returns a dependency error. CES does not defer or replay early events: integrations must call `ces_fire_event()` during or after `action_scheduler_init`. CES schedules actions with Action Scheduler's unique option; Action Scheduler 4.0 is required because uniqueness includes action arguments from that release onward.

## Public API

The intended public API is deliberately small:

```php
ces_register_event(string $key, array $args): true|WP_Error;
ces_register_condition(string $key, array $args): true|WP_Error;
ces_register_token(string $key, array $args): true|WP_Error;
ces_fire_event(string $event_key, array $context = []): array|WP_Error;
```

Event definitions can include trigger bindings and event-owned tokens, so a custom event can be self-contained. Global reusable tokens can also be registered directly with `ces_register_token()`.

Definition keys are stable public API identifiers. Duplicate event, condition, token, and event-filter keys are rejected by default with `WP_Error`. Reusable tokens with identical definitions may be registered for additional events; their `events` lists are merged. To deliberately replace a definition, pass `override => true`. Registration-time definitions are strict: invalid callbacks, malformed event-owned tokens/filters, invalid token escape modes, invalid trigger definitions, and invalid settings fields return `WP_Error` instead of being silently ignored or normalized. Runtime execution remains fail-safe: callback failures, unavailable optional integrations, missing recipients, and failed token rendering prevent delivery and are exposed through runtime actions rather than a parallel CES persistence layer.

## Admin model

The editor validates configuration structure before save and before enablement. Custom SQL is additionally executed once during an explicit administrator save to confirm that the database accepts the query; the returned value does not affect saving. Enabled emails cannot reference unknown events, conditions, settings fields, invalid select values, missing required settings, or an empty delay set. Disabled configurations may retain definitions from an optional integration that is not currently loaded. Each configured scheduled email has:

- Name
- Enabled/disabled
- Event
- Event settings, where the event type supports them
- One or more delays
- Condition mode: ALL or ANY
- One or more selected condition rows, each added from a dropdown with its own required result and settings. The same parameterised condition can be used more than once with different settings.
- Subject
- Body

The execution rule is:

> When this event is processed, evaluate the selected conditions and schedule one action per configured delay only when they pass. When each scheduled action runs, refresh current context and send only if the conditions still pass.

### Condition builder

The condition editor is row-based:

1. Choose a condition from the **Add condition** dropdown.
2. Click **Add condition row**.
3. Choose whether that row **Must be true** or **Must be false**.
4. Fill any settings shown for that condition, such as a user meta key and comparison.

This avoids showing every possible condition at once and allows parameterised conditions, such as `wp_user_meta_matches`, to be used multiple times with different meta keys.


## Retrospective enrollment

Retrospective enrollment applies an enabled user-based email to existing users based on their current state. It is explicit: saving or enabling an email never starts an enrollment automatically.

An event supports retrospective enrollment when it opts in with `retrospective => true` and its context includes `user_id`. WooCommerce cart events and other events that require non-user context do not support it.

### Admin workflow

On an existing enabled email, the **Retrospective enrollment** panel can:

1. count existing users and preview a bounded sample;
2. evaluate every configured condition, recipient rule, `ces_can_send_email` filter, and current user state;
3. start a background enrollment;
4. pause and resume a healthy run, or cancel it; and
5. show aggregate evaluated, eligible, scheduled, excluded, and error counts.

The user set is bounded at run start by the highest existing user ID, so users created later do not enter the active run or move pagination.

### Eligibility and scheduling

Retrospective enrollment does not reconstruct historical events or timestamps.

- Each existing user is evaluated against the email's current send rules.
- Event trigger settings are not evaluated because no historical trigger is being reconstructed.
- Valid negative results exclude the user without failing the run.
- Operational callback, definition, validation, dependency, query, configuration, or scheduling failures mark the run failed. Failed runs are terminal; correct the cause and start a new scan.
- Every eligible user receives one normal Action Scheduler action per unique configured delay.
- Every delay is calculated from the enrollment start time.
- All send rules are checked again immediately before delivery.

This makes mutually exclusive current states behave naturally. For example, `first_name` cannot be both empty and non-empty, so a user cannot qualify simultaneously for incomplete-profile and completed-profile sequences when those rules are configured correctly.

Actions are distributed by scheduled minute, up to the selected maximum of 1–60 actions per minute. Distribution is tracked independently for each configured delay. CES never sleeps inside workers and does not implement a separate delivery retry or throttling subsystem.

### Progress and persistence

CES creates no custom database tables. Action Scheduler stores batch and email actions. WordPress options store only minimal run state:

- one non-autoloaded option per current or latest run containing its next user-ID cursor, frozen maximum user ID, configuration hash, aggregate counters, and bounded distribution cursors;
- one active-run claim per email while that run is running or paused;
- one latest-run reference per email for administration.

Pause and cancel are cooperative: a page already executing may finish before the request takes effect. Only paused healthy runs can resume. Failed runs are terminal, release the active claim, and discard their snapshot and distribution cursors. CES retains one active run and one latest run reference per email. Starting a newer run removes the previous unreferenced run. Completed runs discard their user snapshot and distribution cursors.

The fields affecting eligibility and timing are hashed at run start: enabled state, event key, condition mode, conditions, and delays. If one changes, the run must be cancelled and restarted.

Retrospective enrollment prevents duplicate pending actions by using the exact Action Scheduler payload identity. After earlier actions have completed or been removed, running retrospective enrollment again may schedule the same currently eligible users again.

### Custom events

A custom user-based event opts in with:

```php
'retrospective' => true,
```

The event must include `user_id` in `context_keys`. No retrospective callbacks, historical query providers, occurrence timestamps, or compatibility layer are used.

## Delay test mode

Delay test mode is for development/staging. It lets you test that events schedule and emails send without waiting for production delays such as 24 hours or 3 days.

Settings are shown in **Tools > Email Scheduler > Settings**.

Options:

- Enable/disable delay test mode.
- Set a test delay, such as `2 minutes`.
- Apply to either:
  - only configured delays longer than the test delay; or
  - all non-zero configured delays.

Delay test mode does not change saved email configurations. It only changes the delay stored in newly created Action Scheduler actions. The Queue screen shows the effective delay.

## Built-in generic events

### WordPress

- `custom_wp_hook` — listens to an administrator-configured WordPress action hook. Configure the hook name, accepted argument count, and the one-based argument position containing a valid positive-integer WordPress user ID. Scalar hook arguments are exposed as `hook_arg_1` through `hook_arg_10`. The event is skipped when the selected argument is missing, malformed, zero, negative, non-integral, or does not identify an existing user. It never falls back to the current logged-in user. Immediate processing requires the custom hook to fire during or after `action_scheduler_init`; configure the email for shutdown processing when listening to an earlier hook.
- `wp_user_registered` — fires from WordPress `user_register` when a brand-new user is created.
- `wp_user_added_to_site` — fires from multisite `add_user_to_blog` when a user is added to a specific site.
- `wp_user_available_on_site` — convenience onboarding event that fires from either `user_register` when the user is already available on the current site, or `add_user_to_blog` when the user is added to a site. It uses one stable instance ID per site/user so the same registration flow does not create duplicate pending actions.
- `wp_user_login` — fires from `wp_login`.
- `wp_user_meta_changed` — fires when user meta is added or updated and the configured meta event settings match.

### WooCommerce

These definitions are registered when the WooCommerce plugin is active. If WooCommerce is selectively unloaded in a request, WooCommerce-specific callbacks return `WP_Error('woocommerce_unavailable')` so scheduled actions skip safely instead of silently behaving as false. If enabled CES emails use WooCommerce events, conditions or tokens, WooCommerce must be loaded on Action Scheduler runner requests.

- `wc_cart_item_added` — fires from `woocommerce_add_to_cart` when a user or billing email is known. Includes product/cart context for abandoned basket checks.
- `wc_checkout_viewed` — fires from `woocommerce_before_checkout_form` when a user or billing email is known.
- `wc_order_created` — fires from classic checkout `woocommerce_checkout_order_processed` and Checkout Block/Store API `woocommerce_store_api_checkout_order_processed`.
- `wc_payment_completed` — fires from `woocommerce_payment_complete`.
- `wc_order_status_changed` — fires from `woocommerce_order_status_changed`.


## Built-in generic conditions

### Core / WordPress

- `always`
- `user_exists`
- `wp_user_has_role` — select an installed WordPress role by name; invert the row to require that the role is absent.
- `callable_check` — call a trusted global function or public static class method, optionally passing the evaluated `user_id`. The callable must return `true`, `false`, or `WP_Error`.
- `wp_user_has_email`
- `wp_user_is_site_member`
- `wp_user_meta_matches`

### WooCommerce

These definitions are registered when the WooCommerce plugin is active. If WooCommerce is selectively unloaded in a request, WooCommerce-specific callbacks return `WP_Error('woocommerce_unavailable')` so scheduled actions skip safely instead of silently behaving as false.

- `wc_order_exists`
- `wc_order_is_paid`
- `wc_customer_has_paid_order`
- `wc_cart_has_items`
- `wc_cart_still_contains_added_item`
- `wc_customer_has_paid_order_since_event`
- `wc_customer_has_ordered_added_item_since_event`

The `wp_user_has_role` condition reads current roles at send time. The admin UI lists installed role names with their slugs for clarity. For example, require `Provider (provider) = Must be true` and `Premium member (premium_member) = Must be false` to target providers who do not currently hold premium membership.

The `callable_check` condition accepts either a global function name such as `organisation_has_active_order` or a public static method such as `Organisation_Service::has_active_order`. It never evaluates PHP source. When **Pass user ID** is enabled, the current event or retrospective candidate `user_id` is passed as the only argument. The callable is checked when the definition is saved and executed only as a send rule during retrospective eligibility checks and scheduled delivery. Exceptions, missing callables, and non-boolean results fail closed. Use **Must be false** for checks such as “organisation has no order”.

Use the admin condition operator to invert positive conditions. For example, “customer has paid order since event = Must be false” means no paid order since the event. Only a successfully evaluated boolean result can be inverted: callback errors, missing definitions, and invalid return values fail the complete condition evaluation closed in both ALL and ANY modes.

## Example: profile incomplete reminder

For a site where profile completion is stored as user meta `profile_complete = 1`:

```text
Event: WordPress user added to site
Delays: 24 hours
Condition mode: ALL
Conditions:
- User has email address = Must be true
- User meta matches
  - Meta key: profile_complete
  - Comparison: Is truthy
  - Required result: Must be false
```

## Example: profile completed event

```text
Event: WordPress user meta changed
Event settings:
- Meta key: first_name
- Trigger when: Value becomes truthy

Delays: 24 hours and 4 days
Condition mode: ALL
Conditions:
- User has email address = Must be true
- User meta matches
  - Meta key: first_name
  - Comparison: Is truthy
  - Required result: Must be true
- WooCommerce customer has paid order = Must be false
```

## Example: registered-user abandoned basket email

Because this plugin is intentionally storage-light, the built-in abandoned basket checks are for registered/logged-in WooCommerce users with persistent carts. They are suitable where guest checkout is disabled.

```text
Event: WooCommerce cart item added
Delays: 24 hours
Condition mode: ALL
Conditions:
- User has email address = Must be true
- WooCommerce cart still contains added item = Must be true
- WooCommerce customer has ordered added item since event = Must be false
```

This means: when a registered user adds an item to the cart, schedule the email for 24 hours later. At send time, send only if the original product/variation is still in the user's persistent cart and the customer has not bought that added item since the add-to-cart event.

## Registering a custom event

`ces_register_event()` defines event metadata, hook bindings, context creation, dedupe identity, and any event-owned tokens. This lets a custom event be self-contained.

```php
add_action('plugins_loaded', function () {
    if (!function_exists('ces_register_event')) {
        return;
    }

    ces_register_event('profile_completed', [
        'label'        => 'Profile completed',
        'description'  => 'Fires when a user completes their B2B profile.',
        'context_keys' => ['user_id', 'organisation_id', 'profile_score'],
        'tokens'       => [
            'profile_score' => [
                'label'    => 'Profile score',
                'requires' => ['profile_score'],
                'escape'   => 'text',
            ],
            'profile_organisation_name' => [
                'label'    => 'Profile organisation name',
                'requires' => ['organisation_id'],
                'callback' => function (array $context): string {
                    $organisation_id = isset($context['organisation_id']) ? absint($context['organisation_id']) : 0;
                    return $organisation_id ? my_site_get_organisation_name($organisation_id) : '';
                },
            ],
        ],
        'conditions'   => [
            'user_has_completed_profile',
            'organisation_has_purchase',
            'always',
        ],
        'triggers'     => [
            [
                'hook'             => 'my_profile_completed_hook',
                'priority'         => 10,
                'accepted_args'    => 3,
                'context_callback' => function (int $user_id, int $organisation_id, int $profile_score): array {
                    return [
                        'user_id'           => $user_id,
                        'organisation_id'   => $organisation_id,
                        'profile_score'     => $profile_score,
                        'event_instance_id' => 'profile_completed:' . $user_id,
                    ];
                },
            ],
        ],
    ]);
}, 20);
```

The `conditions` array is used as a relevance/sorting hint in the admin UI. It does not prevent compatible conditions from being selected. The `triggers` array is optional; use it for normal WordPress/WooCommerce hook-backed events. Use `ces_fire_event()` directly only for manual tests, non-hook event sources, or custom integrations that already have complete event context.

Each email configuration has a `processing_mode` of `immediate` (the default) or `shutdown`. Immediate emails evaluate event settings and conditions during the originating WordPress hook. Shutdown emails collect and deduplicate trigger contexts in memory, then evaluate at the end of the current PHP request. This allows emails using the same event to choose different processing modes. Shutdown processing is suitable when related user meta, roles, or organisation data are written consecutively in one request; it does not persist or create a separate Action Scheduler action.

A `recipient_callback`, when present, is authoritative. If it throws, returns `WP_Error`, or returns no valid recipients, CES will not fall back to `context['email']` or `user_id`; the scheduled action will skip with `no_recipients` and fire `ces_recipient_callback_failed`.

## Registering a custom condition

Conditions are checked when the scheduled action runs, not when the event fires.

```php
add_action('plugins_loaded', function () {
    if (!function_exists('ces_register_condition')) {
        return;
    }

    ces_register_condition('user_has_completed_profile', [
        'label'       => 'User has completed profile',
        'description' => 'Passes when profile_completed_at exists on user meta.',
        'requires'    => ['user_id'],
        'callback'    => function (array $context, array $email, array $runtime) {
            $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;
            $passed = $user_id > 0 && (bool) get_user_meta($user_id, 'profile_completed_at', true);

            return [
                'passed' => $passed,
                'reason' => $passed ? '' : 'profile_not_completed',
            ];
        },
    ]);

    ces_register_condition('organisation_has_purchase', [
        'label'       => 'Organisation has purchase',
        'description' => 'Passes when the organisation has purchased membership.',
        'requires'    => ['organisation_id'],
        'callback'    => function (array $context): bool {
            $organisation_id = isset($context['organisation_id']) ? absint($context['organisation_id']) : 0;
            return $organisation_id > 0 && my_site_organisation_has_membership($organisation_id);
        },
    ]);
}, 20);
```

Use “Must be false” in the admin UI to negate a condition. Condition callbacks may return a boolean, `WP_Error`, or a structured result array with a boolean `passed` value plus optional `reason`, `actual`, and `expected`; invalid return types fail safely and are available through `ces_condition_checked` and `ces_condition_callback_failed`.

## Registering reusable tokens

Use `ces_register_token()` for reusable tokens:

```php
ces_register_token('organisation_name', [
    'label'    => 'Organisation name',
    'requires' => ['organisation_id'],
    'escape'   => 'html',
    'callback' => function (array $context): string {
        $organisation_id = isset($context['organisation_id']) ? absint($context['organisation_id']) : 0;
        return $organisation_id ? my_site_get_organisation_name($organisation_id) : '';
    },
]);
```

Token `escape` can be `html`, `text`, `url`, or `raw`. Tokens in subjects are always reduced to plain text, including tokens marked `raw`; `raw` only bypasses escaping in body context. Token callbacks are evaluated only when the token appears in the subject/body. A missing callback, callback exception, `WP_Error`, or invalid non-scalar result fires `ces_token_callback_failed`, stops delivery, and fails the Action Scheduler action so incomplete content is not sent.

## Firing a custom event

Call this when your application event happens.

```php
ces_fire_event('profile_completed', [
    'user_id'           => $user_id,
    'organisation_id'   => $organisation_id,
    'event_instance_id' => 'profile_completed:' . $user_id,
]);
```

`event_instance_id` identifies the event occurrence and is included in Action Scheduler's unique action arguments. For one-off lifecycle events, a deterministic value like `profile_completed:123` is usually right. Repeatable built-in events use a UUID-backed occurrence identity so same-second logins, repeated user-meta changes, and repeated order-status transitions remain distinct. Custom repeatable events should likewise include a unique occurrence, session, cart, order, or equivalent identifier. CES preserves custom context keys as text unless they are known numeric IDs such as `user_id`, `blog_id`, `product_id`, `variation_id`, `quantity`, `order_id`, `event_timestamp`, or `organisation_id`; custom IDs such as CRM or subscription IDs should be declared in `context_keys` and will not be coerced to integers.

The engine automatically adds `event_time_gmt` and `event_timestamp` to event context when missing. Conditions such as `wc_customer_has_paid_order_since_event` use that event time at send time.

## Built-in tokens

Token definitions are global and declare the context keys they require. Available tokens in the admin UI are inferred from the selected event context.

Core token replacements include:

```text
{{user_email}}
{{first_name}}
{{last_name}}
{{display_name}}
{{site_name}}
{{site_url}}
{{account_url}}
{{event_time_gmt}}
{{meta_key}}
{{meta_value}}
{{previous_meta_value}}
{{meta_change_type}}
```

WooCommerce adds:

```text
{{cart_url}}
{{checkout_url}}
{{product_id}}
{{product_name}}
{{product_name_trimmed}}
{{product_sku}}
{{product_type}}
{{product_categories}}
{{product_tags}}
{{order_id}}
{{order_number}}
{{order_status}}
{{order_previous_status}}
{{order_total}}
{{order_pay_url}}
```

Custom tokens are registered with `ces_register_token()` or included directly in `ces_register_event()` using the event `tokens` array. Final token replacement can still be adjusted with `ces_token_replacements`. Token callback failures fire `ces_token_callback_failed`, prevent `wp_mail()`, and fail the Action Scheduler action.

## Hooks and filters

### `ces_can_send_email`

Final send guard.

```php
add_filter('ces_can_send_email', function (bool $allowed, array $context, array $email, array $runtime): bool {
    $user_id = isset($context['user_id']) ? absint($context['user_id']) : 0;

    if ($user_id && get_user_meta($user_id, 'marketing_unsubscribed', true)) {
        return false;
    }

    return $allowed;
}, 10, 4);
```

### `ces_email_recipients`

Override/extend recipients.

```php
add_filter('ces_email_recipients', function (array $recipients, array $email, array $context, array $runtime): array {
    return $recipients;
}, 10, 4);
```

### Runtime actions

- `ces_email_scheduled`
- `ces_email_skipped`
- `ces_email_sent`
- `ces_email_failed`
- `ces_condition_checked`
- `ces_condition_callback_failed`
- `ces_event_settings_callback_failed`
- `ces_recipient_callback_failed`
- `ces_token_callback_failed`
- `ces_instance_id_callback_failed`
- `ces_registry_definition_conflict`
- `ces_event_trigger_observed`
- `ces_event_trigger_skipped`
- `ces_event_trigger_failed`
- `ces_event_trigger_scheduled_zero_actions`
- `ces_event_trigger_fired`
- `ces_email_configuration_invalid`
- `ces_email_schedule_duplicate`
- `ces_email_schedule_failed`
- `ces_scheduled_email_completed`
- `ces_scheduled_email_failed`
- `ces_manual_test_completed`


## Queue and execution history

The **Queue** view in **Tools > Email Scheduler** is a thin, read-only view of Action Scheduler actions for the CES hook and group. It uses Action Scheduler's public query API and does not copy queue records into CES storage.

The Queue view supports status filtering and pagination. It shows:

- Action Scheduler action ID
- Action Scheduler status
- Scheduled time
- CES email definition
- Event
- Event occurrence ID
- Effective delay

The native Action Scheduler screen remains the source for detailed operational logs and manual administration.

Action Scheduler statuses describe callback execution, not confirmed mail delivery. **Completed** can mean either that an email was accepted by `wp_mail()` or that CES deliberately skipped delivery because current settings, conditions, permissions, or recipients did not allow it. Callback exceptions, `WP_Error` results, invalid callback values, recipient callback failures, and token callback failures mark the action failed. Integrations that require business-outcome telemetry should listen to the CES runtime actions.

CES does not automatically retry failures. Immediate or partial `wp_mail()` failure and operational callback failure throw so Action Scheduler marks the action failed. Valid negative settings, filter, condition, permission, or recipient results remain deliberate skips and complete normally.

## Storage

CES stores configuration and minimal retrospective-run state in WordPress options:

- `ces_emails` — scheduled-email definitions.
- `ces_settings` — delay-test settings.
- `ces_backfill_run_{run_id}` — one non-autoloaded operational-state option for the current or latest run.
- `ces_backfill_active_{email_id}` — an atomic claim preventing concurrent runs for the same email definition.
- `ces_backfill_latest_{email_id}` — the latest run reference used by the email administration screen.

These options do not duplicate Action Scheduler actions or keep recipient-level delivery history. Per-run, active-claim, and latest-run options are non-autoloaded. A newer run replaces the previous terminal run retained for that email.

Action Scheduler owns action arguments, timing, worker claims, execution status, retention, and operational logs. CES creates no custom database tables and does not maintain a second queue, delivery reservation system, lifecycle log, retry ledger, migration layer, or retention task. Uninstall cancels CES actions and removes retrospective options by their CES prefixes, including orphaned state no longer reachable from an email definition.

Action payloads contain the sanitized context required to execute the email. CES recursively sorts associative payload keys before scheduling, so equivalent context is not treated as different merely because key order changed. Action Scheduler's unique scheduling option prevents an action with the same hook, group, and canonicalized argument payload from being added while its match is pending or running. This is exact-payload duplicate suppression, not occurrence-level idempotency: materially different context for the same occurrence ID remains a different action. The event occurrence ID should remain stable for a logical one-off event, while repeatable events should use a new occurrence ID. CES does not promise permanent deduplication after Action Scheduler removes its own records, and it cannot guarantee exactly-once email delivery through `wp_mail()`.

Action arguments may contain personal data needed at send time. Custom integrations should include only necessary context, never secrets, and keep payloads compact. Action Scheduler 4.0's database store supports encoded arguments up to 8,000 bytes and uses its extended-arguments field when the indexable representation would exceed 191 bytes. Oversized or otherwise invalid actions fail scheduling and trigger `ces_email_schedule_failed`; CES does not create fallback storage.

## Architecture guardrails

Action Scheduler is the sole queue, worker-state, execution-history, and operational-log store for CES. WordPress options may contain email definitions, plugin settings, and lightweight retrospective-run administration state.

Do not add CES-owned tables or option-based substitutes for jobs, delivery reservations, lifecycle logs, retries, permanent deduplication, batch generations, locks, or per-candidate checkpoints.

Retrospective progress is deliberately page-based and cooperative. Immediate cancellation, exact mid-page resumability, durable per-candidate progress, and permanent delivery idempotency are outside the current architecture. If future requirements demand those guarantees, make a new explicit persistence decision rather than extending the current option state into a workflow engine.

## WordPress role and organisation context

The generic WordPress events populate `role` and `user_roles`. If your site has an organisation model, use `ces_organisation_context` to add values such as `organisation_id` and `organisation_name`. Organisation values then become available to conditions, recipients, and tokens.

## Default scheduled emails

Bundled default emails are stored in `config/default-emails.json`, validated through the same strict schema as uploaded imports, seeded only during activation when no email definitions exist, and restored from **Tools > Email Scheduler > Settings**. Resetting defaults replaces available definitions with those bundled import keys while leaving custom scheduled emails untouched. Defaults that depend on an inactive optional integration are skipped and reported; uploaded imports remain strict and all-or-nothing.

## Admin management actions

The admin interface supports:

- Create, edit, copy, enable, disable, and delete scheduled emails.
- Bulk enable or disable.
- Delay-test settings.
- Immediate test sends through the production validation/rendering pipeline with a recipient override.
- Manual test-event firing through the real scheduling engine.
- Read-only queue inspection backed by Action Scheduler.
- Reset bundled default emails.
- Preview, start, pause, resume, and cancel retrospective enrollment for supported events.

There is no CES log-clear or database-maintenance action because CES owns no queue/history tables.

## Development checks

Run:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
node --check assets/admin.js
```

The regression harness is intentionally small. It protects only failure-sensitive behavior and the architecture boundary:

- fail-closed condition evaluation;
- unique Action Scheduler scheduling with canonicalized action payloads;
- deliberate skips versus immediate mail failures;
- partial-send failure behavior without automatic retry logic; and
- current-state retrospective user scans, stable page cursors, healthy-run pause/resume behavior, terminal failure handling, and distributed scheduling; and
- bundled default JSON loading and reset behavior with optional integrations available and unavailable; and
- absence of CES table-creation code and removed persistence classes.

These tests do not replace integration testing against WordPress, Action Scheduler, WooCommerce, or the configured mail transport.

## Changelog

### 0.50.2

- Fixed the email editor so selecting an event immediately renders that event's settings fields, including Custom WordPress hook options.

### 0.50.1

- Requires custom-hook user ID arguments to be positive integers or digit-only strings before resolving the WordPress user.
- Allows disabled custom-hook email drafts to be saved with incomplete event settings.
- Documents that immediate custom-hook processing requires the hook to fire during or after `action_scheduler_init`; earlier hooks should use shutdown mode.
- Removes duplicated custom-hook regression coverage and adds malformed-ID, shutdown-mode, and shared-binding tests.

### 0.50.0

- Adds the administrator-configurable `custom_wp_hook` event.
- Supports a custom action-hook name, accepted argument count, and explicit one-based user-ID argument position.
- Exposes scalar hook arguments as `hook_arg_1` through `hook_arg_10`.
- Skips custom hook events when the selected argument does not identify an existing WordPress user; no current-user fallback is used.

### 0.49.2

- Adds `{{product_name_trimmed}}`, which removes product-name variation text from the first hyphen, en dash, em dash, or colon onward.
- Uses the trimmed product-name token in the bundled abandoned-basket email.

### 0.49.1

- Makes the public `ces_fire_event()` API process immediate email configurations during the call and buffer shutdown configurations for the WordPress shutdown phase.
- Keeps hook-backed trigger processing on the internal single-mode path so trigger deduplication remains independent per processing mode.

### 0.48

- Stops queue creation when an event-settings callback fails or returns an invalid value.
- Reports the queue-time failure through `ces_email_schedule_eligibility_failed` without creating permanently failing scheduled actions.
- Removes the obsolete `schedule_runtime_failure` action payload path.

### 0.47

- Evaluates configured send conditions when live events are processed, including shutdown-buffered events, before delayed actions are queued.
- Continues to re-evaluate the same conditions immediately before delivery.


### 0.46

- Added generic immediate and shutdown email-processing modes.
- Processing mode is configured per email and defaults to immediate.
- Shutdown-triggered events are deduplicated in memory and use the normal event engine.

### 0.45

- Unifies scheduled delivery for live and retrospective actions.
- Treats event settings as trigger-time matching only; delivery always rechecks current send rules through the standard dispatcher.
- Refreshes current user email, login, and roles before every scheduled user-based send.
- Enforces current target-site membership for every scheduled user email on multisite.
- Prevents bulk enable from persisting definitions that fail validation.

### 0.44

- Added bulk deletion for selected scheduled emails, with confirmation and retrospective-state cleanup.

### 0.43

- Reset recognises reserved pre-import bundled default IDs and upgrades them in place instead of creating duplicate definitions.
- Added an **Import emails** button beside **Add Scheduled Email** that links directly to the importer on the Settings tab.

### 0.42

- Reset now removes all bundled default definitions by their JSON import keys, including defaults whose optional integration is currently unavailable.
- Preserves unrelated custom emails while restoring only currently available bundled defaults.

### 0.41

- Allowed bundled generic defaults to load when optional integrations are inactive.
- Reported integration-dependent defaults skipped during reset.
- Kept uploaded JSON imports strict and all-or-nothing.
- Removed stale seed-marker documentation.

### 0.40

- Moved bundled defaults into `config/default-emails.json` and validated them through the standard JSON importer schema.
- Removed the PHP-defined default set and seed-marker compatibility path.
- Used `first_name` consistently for both incomplete and completed profile defaults.

### 0.39

- Delay rows now use immutable identifiers that survive timing edits, preserving stable retrospective duplicate identity.

### 0.38

- Kept retrospective Action Scheduler identity stable across delay and test-mode changes.
- Prevented pending duplicates from consuming retrospective distribution slots.
- Removed unreachable duplicate exception code.

### 0.37

- Made retrospective action payloads stable across runs so a later scan cannot queue a duplicate while an equivalent action remains pending.
- Rebuilds retrospective user context at delivery time from the current user record.
- Removed the redundant pre-scheduling duplicate lookup; Action Scheduler unique scheduling remains the atomic duplicate guard.

### 0.36

- Removes permanent retrospective enrollment markers from the options table.
- Uses Action Scheduler exact-argument lookup for pending-action duplicate prevention instead of scanning the full queue.
- Allows a later retrospective run to schedule currently eligible users again after prior actions have completed or been removed.
- Redacts custom SQL comparison values from diagnostics and hashes SQL templates in slow-query diagnostics.

### 0.33

- Adds a callable condition for trusted global functions and public static class methods.
- Supports zero arguments or the evaluated user ID as the only argument.
- Requires boolean or `WP_Error` results and fails closed on missing callables, invalid results, or exceptions.

### 0.32

- Import previews now detect edits to both updated and unchanged matched emails before applying.
- Omitting `enabled` leaves an existing email's enabled state unchanged; explicit values are applied only when requested.
- Import application now rechecks existing import-key uniqueness before writing.

### 0.31

- Added strict JSON importer schema validation, apply-time custom SQL re-testing, consistent enabled-state imports, and import-key uniqueness enforcement.

### 0.30

- Changes the bundled profile-complete email to check current profile and purchase state, making retrospective enrollment safe for prior purchasers.
- Makes failed retrospective runs terminal and releases their active claim; only paused healthy runs can resume.
- Clarifies sample-only preview results and permanent once-enrolled marker behavior.

### 0.28

- Restricts multisite retrospective scans to users assigned to the current site.
- Prevents repeat enrollment of the same site/email/user/delay combination across completed runs.
- Keeps login events live-only and rejects historical user-meta event tokens in current-state enrollment.
- Removes the non-authoritative singular role from retrospective context.
- Fails retrospective preview and enrollment cleanly on user-query database errors.

### 0.27

- Replaces historical reconstruction with current-state user enrollment.
- Calculates delays from enrollment start and rechecks all send rules before delivery.

### 0.26

- Requires and validates comparison values for custom SQL operators that need them.
- Catches database exceptions during SQL checks and keeps runtime details private.
- Redacts custom SQL scalar results from condition diagnostics.
- Clarifies that SQL validation is defensive and database permissions remain the security boundary.

### 0.25

- Makes generic email validation side-effect-free; conditions are never executed while validating, loading, enabling, or listing definitions.
- Moves custom SQL execution testing to explicit administrator saves only.
- Evaluates custom SQL normally for retrospective candidates and re-evaluates it before delivery.
- Prevents duplicate custom SQL execution during normal dispatch and retrospective processing.
- Keeps runtime database errors generic and passes unresolved templates to slow-query diagnostics.


### 0.18.0

- Removed the event-filter feature and all related registry, runtime, storage, admin, WooCommerce, documentation, and compatibility code.
- Retained event settings and send-time conditions as the only eligibility mechanisms.


### 0.17.0

- Simplifies retrospective enrollment to page-boundary, cooperative pause and cancellation semantics.
- Removes option locks, batch generations, partial-candidate state, and correctness claims that WordPress options cannot reliably provide.
- Carries the immutable page cursor in each Action Scheduler batch action and ignores stale page actions after the stored cursor advances.
- Treats progress counters as administrative, eventually consistent information rather than execution authority.
- Requires cancellation and a new run after eligibility or timing configuration changes.

### 0.16.0

- Adds explicit current-state retrospective enrollment for user-based events.
- Adds built-in retrospective support for WordPress user-based events.
- Processes candidates in stable keyset-paginated Action Scheduler batches with a frozen upper bound.
- Reuses the normal eligibility pipeline before scheduling and rechecks eligibility before delivery.
- Adds preview, pause, resume, cancel, aggregate progress, configuration-drift detection, isolated candidate errors, and bounded repeated-error pausing.
- Distributes all backfill-generated email actions across scheduled timestamps without worker sleeps or automatic retries.
- Stores only minimal per-run operational state in non-autoloaded WordPress options and creates no custom database tables.

### 0.15.1

- Manual test sends now validate and use the explicit test recipient before resolving the event's production recipients. This allows event-specific recipient callbacks to require context that is intentionally absent from the manual test form.

### 0.15.0

- Distinguishes deliberate negative evaluations from operational callback failures during scheduled execution.
- Fails Action Scheduler actions when settings, conditions, recipients, or token callbacks error or return invalid values.
- Prevents mail from being sent when a referenced token cannot be rendered safely.
- Initializes Action Scheduler during uninstall when its API is loaded but not yet initialized, then cancels CES actions.

### 0.14.0

- Fixes uninstall cleanup to cancel the CES action hook within the CES group.
- Removes deferred event replay; early event calls now fail deterministically.
- Returns evaluation diagnostics directly instead of storing mutable static "last result" state.
- Removes recipient-loop pacing and its settings.
- Canonicalizes associative action payload keys before unique scheduling.
- Clarifies that uniqueness is exact-payload duplicate suppression.
- Removes Composer metadata because CES has no Composer dependencies.
- Keeps the regression harness limited to failure-sensitive lifecycle behavior.

### 0.13.0

- Makes Action Scheduler the sole queue, worker-state, history, and operational-log store.
- Removes CES job and log tables, migrations, retention jobs, reservation state, cleanup state machines, and log-management UI.
- Adds a read-only Queue view using `as_get_scheduled_actions()`.
- Requires WordPress 6.8 and Action Scheduler 4.0.0 for argument-aware unique actions.
- Marks immediate and partial `wp_mail()` failures as failed Action Scheduler actions without automatic retries.
- Documents the no-custom-table architecture decision and adds a focused architecture regression check.

### 0.16.2

- Tracks scheduling distribution independently for each configured delay.
- - Uses each candidate's stable cursor for fallback event identities, including non-scalar candidates.

### 0.16.1

- Fails immediately for systemic eligibility/configuration failures while isolating candidate-data errors.
- - Replaces unbounded minute-bucket state with a compact distribution cursor.


### Custom SQL safety

Custom SQL scalar conditions run through `$wpdb->get_var()` only when conditions are evaluated: during live event queue-building, once per retrospective candidate, and again before scheduled delivery. They also run once during an explicit administrator save solely to confirm that the query executes; the scalar result is ignored for that test. Generic validation, enabling, listing, and loading never execute conditions. Only administrators with `manage_options` can configure these queries. Only a single `SELECT` is accepted. Comments, stacked statements, data-changing operations, file access, locking, delay/benchmark functions, and unknown placeholders are rejected. This validation is defensive rather than a SQL sandbox: the WordPress database account's permissions remain the authoritative security boundary, and custom or stored database functions should not be used. Use indexed join/filter columns. `{user_id}` is substituted everywhere in the SQL template, including inside quoted values. Save-time testing reports a sanitised database error to administrators; runtime failures remain generic and fail closed. Custom SQL scalar values are redacted from condition diagnostics. Slow-query diagnostics receive a SHA-256 hash of the SQL template rather than SQL text.


### Current-state enrollment safeguards

Retrospective enrollment scans only users assigned to the current site on multisite. After queue creation, retrospective and live actions use the same delivery path. Every scheduled user-based send refreshes current user data and requires current membership of the target site on multisite. Duplicate pending actions are skipped, but a later run may schedule the same users again after earlier actions have completed or been removed. User-login events are live-only. For user-meta-change emails, current-state enrollment does not provide historical meta-event tokens (`meta_key`, `meta_value`, `previous_meta_value`, or `meta_change_type`); emails using those tokens cannot be retrospectively enrolled. Role conditions always inspect the user’s complete current role list.

## JSON email importer

Tools → Email Scheduler → Settings includes a JSON file importer with a validation preview. The importer accepts documents in this format:

```json
{
  "format": "codi-email-scheduler",
  "version": 1,
  "emails": [
    {
      "key": "complete-profile-24h",
      "name": "Complete your profile",
      "enabled": false,
      "event": {"key": "wp_user_available_on_site", "settings": {}},
      "condition_mode": "all",
      "conditions": [],
      "delays": [{"value": 24, "unit": "hours"}],
      "subject": "Complete your {{site_name}} profile",
      "body": "<p>...</p>"
    }
  ]
}
```

The stable `key` is stored with the email and is the only value used to match an existing imported definition. Names are never used for matching. Preview classifies each definition as new, update, unchanged, or invalid and shows changed fields. Applying a preview can create and update, create only, or update only. Unrelated emails are not changed or deleted. New imported definitions are disabled by default. Existing matched emails retain their current enabled state unless the administrator chooses to apply the JSON `enabled` values. The importer rejects unknown fields and incorrect JSON types rather than silently normalising them, and custom SQL is tested both during preview and immediately before apply.
