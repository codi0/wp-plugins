# Multisite package

The Multisite package is an orchestration layer for WordPress multisite. Existing Codi packages stay site-local and do not accept `site_id` or perform `switch_to_blog()` discovery.

Network-wide package policy lives in **Network Admin → Codi MCP**. Every bundled package is controlled there with one of three states:

- **Enabled** — force the package available and automatically expose every ability it registers on each site.
- **Disabled** — do not register the package on any site.
- **Delegate to site** — let each site decide whether the package is active and which of its abilities are exposed.

`multisite` is network infrastructure, so it supports only **Enabled** or **Disabled**. Ordinary packages default to **Delegate to site**, with the site decision defaulting to enabled. The Multisite package defaults to disabled. There is no legacy setting migration or fallback.

When `multisite` is enabled, the main site registers and core automatically exposes three network-managed Codi abilities:

- `codi/sites` — list sites the authenticated user may access.
- `codi/site-abilities` — retrieve the target site's live, Codi-exposed ability catalogue and schemas.
- `codi/site-call` — execute one exposed target-site ability.

These routing abilities are infrastructure, not Site 1 exposure choices. They are excluded from the main site's ordinary site MCP catalogue while the package is enabled and are published only by the dedicated network server. Ordinary packages set to **Enabled** are automatically exposed on each site's site-local server and are also eligible for the network server catalogue. Only delegated packages use each target site's editable exposure choices, and delegated main-site abilities never leak into the network server catalogue.

For an ordinary package whose network policy is **Delegate to site**, that site's Codi MCP page shows a package availability control above ability exposure. Disabling a delegated package prevents its runtime, categories, and abilities from registering on that site. Existing site-level ability exposure choices are retained while a package is unavailable and become effective again if the package is re-enabled.

For a remote target, the gateway sends a short-lived HMAC-signed assertion to `/wp-json/codi-mcp/v1/federation` on that target site. The target request boots WordPress normally, so site-active plugins register their own abilities before discovery or execution. The target site then applies effective network/site package and exposure policy, followed by the selected ability's normal `permission_callback` through `WP_Ability::execute()`.

The assertion is bound to network ID, target site ID, originating WordPress user ID, operation, request body hash, issue/expiry times, and a nonce. Used nonces are retained for the assertion window to reject replay. The ordinary site OAuth bearer token is never made valid on another site.

The federation endpoint requires HTTPS by default. `codi_mcp_multisite_allow_insecure_federation` exists only for deliberately configured development environments.

The canonical external network MCP endpoint is the main site's dedicated `/wp-json/mcp/codi-network` resource. Its OAuth issuer is `/wp-json/codi-mcp/v1/network`; its OAuth clients, credentials, throttling state, and mutexes are stored in network options and its bearer key binds to the current network ID. The main site's ordinary `/wp-json/mcp/codi` endpoint remains site-local with separate site OAuth state. There is no compatibility alias between the two resources. Codi MCP should normally be network-active for this mode so the federation receiver can load on every target site.
