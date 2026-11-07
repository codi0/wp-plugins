# Codi MCP architecture

## Purpose

Codi MCP is the shared WordPress MCP host. It owns authentication, MCP exposure/governance, operator controls, and a set of independently bounded ability packages.

It does **not** implement a custom MCP protocol or transport. The official WordPress MCP Adapter remains the protocol/transport implementation, while Codi MCP registers a dedicated governed server through the Adapter's public `create_server()` API:

`/wp-json/mcp/codi`

## Responsibilities

### Core

Core owns cross-package infrastructure only:

- package discovery/bootstrap
- optional package runtime registration for package-owned infrastructure that is not itself an Ability
- OAuth 2.0 authorization-code flow with PKCE and refresh tokens
- bearer-token authentication into the normal WordPress user/capability model
- dedicated MCP server registration through the official Adapter
- ability discovery and enable/disable policy
- per-site admin UI for ability exposure
- small shared bounded artifact-transfer primitives used by package-owned upload/export workflows
- shared source/callback attribution used by diagnostic and background-job evidence

Core must not contain plugin-deployment policy or presentation/editing domain logic.

Recommended core layout as those pieces are implemented:

```text
src/Core/        composition and small shared contracts
src/Auth/        OAuth server, bearer authentication, token/client state
src/Exposure/    registered-ability discovery and enabled-ability policy
src/Adapter/     dedicated-server registration using the official Adapter transport
src/Admin/       per-site operator UI for ability exposure
```

The directories should be introduced only when their implementation exists; they are not empty framework layers.

Codi MCP uses one lightweight autoload mapping only: `CodiMcp\\` → `src/`. Bundled packages live beneath `src/Packages/`, so no package-specific or legacy namespace mappings are required.

Bundled package discovery is convention-based rather than hard-coded in `Bootstrap`: every immediate `src/Packages/<Name>/Package.php` is loaded as `CodiMcp\\Packages\\<Name>\\Package`, must implement `AbilityPackage`, and is composed in deterministic directory-name order. Adding a bundled package therefore does not require editing core Bootstrap. Directories without `Package.php` are not packages.

### Ability packages

Packages own coherent groups of WordPress Abilities and their domain/application code. A package registers categories and abilities through the standard WordPress Abilities API. It does not create MCP servers and does not implement OAuth.

The WordPress Abilities registry is Codi's canonical capability layer. Packages must reuse a suitable registered WordPress ability when one already provides the required semantics. A package registers a `codi/*` ability only when no suitable ability exists or when Codi intentionally provides materially different safety, validation, transaction, transfer, or orchestration semantics. Consumer interfaces and transports sit above this capability layer and are never package implementation boundaries.

Built-in packages:

1. `plugins` — plugin ZIP deployment/lifecycle, bounded whole-plugin export, and installed/must-use plugin inspection.
2. `system` — runtime, REST, cron, rewrite, Site Health, debug-log, cache and role inspection plus narrowly bounded operational mutations.
3. `site` — typed site settings only; no generic option setter and no theme lifecycle.
4. `themes` — theme ZIP deployment/lifecycle, bounded whole-theme export, and installed-theme inspection; no source-file editing.
5. `content` — content-entity lifecycle, registered structure inspection, taxonomy relationships, REST-exposed registered metadata, and author attribution; no block/body editing.
6. `media` — media-library discovery, bounded transfer, attachment mutation/replacement, and deletion.
7. `database` — read-only database table/schema inspection plus bounded parameterized row-data queries; no database-write ability.
8. `diagnostics` — bounded same-origin request execution and request-local development evidence using WordPress-native hooks/runtime state; no generic HTTP client or arbitrary execution surface.
9. `jobs` — conditional Action Scheduler job inspection plus exact pending-job run/cancel operations; no arbitrary scheduling or storage-specific SQL.
10. `presentation` — WordPress-managed Gutenberg composition, templates, patterns, navigation, Global Styles, preview/commit/history, and rollback.
11. `multisite` — optional network orchestration that lists accessible sites, discovers each target site's live exposed abilities, and routes execution into the target site's normally bootstrapped runtime.

The package interface is an internal composition boundary for Codi MCP's bundled capabilities. Other WordPress plugins do not need to depend on Codi MCP or implement a Codi-specific package API: they register ordinary WordPress Abilities, and core discovers/governs those abilities through the shared registry.

Packages that need package-owned infrastructure outside the Abilities API may additionally implement the optional `RuntimePackage` contract. Core invokes that contract during plugin registration and supplies a narrow `PackageRuntime` context. The context exposes the current site's effective Codi exposure decision without exposing or duplicating the underlying option format. Existing ability-only packages do not implement this contract and require no changes.

On multisite, core applies a separate package-availability policy before invoking any package runtime, category registration, or ability registration. Ordinary bundled packages support `enabled`, `disabled`, and `delegate`: enabled forces the package available network-wide, disabled suppresses it network-wide, and delegate defers availability to a per-site package choice. The `multisite` package itself supports only enabled/disabled because it is network infrastructure. Ordinary packages default to delegated with the site default enabled; `multisite` defaults disabled. This is the only package-policy model: there is no legacy setting migration or fallback path.

Transport authorization always requires an authenticated WordPress user. After authentication, `codi_mcp_transport_permission` may extend the default current-site `read` capability decision for an orchestration package such as a future multisite gateway. It cannot admit an unauthenticated request.

## Dedicated-server exposure model

Codi MCP registers a dedicated site server `codi-mcp` through the official Adapter's public `create_server()` API without changing the Adapter's generic default-server setting. Its endpoint is `/wp-json/mcp/codi` and its OAuth authorization-server namespace is `/wp-json/codi-mcp/v1`. When the Multisite package is network-enabled on the main site, core additionally registers `codi-mcp-network` at `/wp-json/mcp/codi-network` with a separate OAuth authorization-server namespace at `/wp-json/codi-mcp/v1/network`. Other plugins may therefore use the Adapter's default server independently of Codi.

Exposure has two authorities. On multisite, every ability declared by a package whose network state is **Enabled** is network-managed and automatically exposed wherever that package registers; such abilities are omitted from the site exposure form. A package may also declare narrower infrastructure-managed abilities through the internal managed-exposure contract. For packages delegated to a site, the site enabled-ability store remains authoritative and abilities are default-deny until explicitly selected. At server construction, Codi MCP resolves the currently registered WordPress abilities and passes only site-exposed or network-managed ability IDs into the dedicated server's `tools`, `resources`, and `prompts` lists.

The Adapter's generic discovery/get-info/execute gateway abilities are therefore not present on the Codi server. This prevents `mcp-adapter/execute-ability` from bypassing Codi MCP's operator-facing allowlist.

The dedicated server still uses the official Adapter's `HttpTransport`, handlers, session implementation, and protocol machinery. Codi owns server identity, OAuth integration, exposure policy, and transport permission policy; it does not fork or replace the MCP protocol implementation.

### Site-local model

Every site, including the multisite main site, keeps its own site-local ability exposure and OAuth state. Site OAuth clients, authorization codes, access tokens, refresh tokens, registration throttling, and mutexes use ordinary non-autoloaded site options with explicit credential expiries; they do not use transients or depend on the object cache. Site bearer records bind to `site:<site-id>|mcp/codi`. The plugin may be activated per site or network-activated without changing that site-resource identity.

On multisite, **Network Admin → Codi MCP** owns network package policy for every bundled package. **Enabled** forces a package on and makes all abilities it registers network-managed exposure on every site. **Disabled** suppresses its runtime, categories, and abilities everywhere. **Delegate to site** shows the package on each site's ordinary Codi MCP page as a site-level package toggle and leaves its ability exposure under that site's control. Stored site package choices and ability exposure selections are retained while overridden by network policy and become effective again when delegation is restored.

When the `multisite` package is enabled, `codi/sites`, `codi/site-abilities`, and `codi/site-call` register only on the current network's main site and are automatically treated as network-managed infrastructure exposure. They do not appear on the main site's ordinary `/wp-json/mcp/codi` server. Instead, the main site registers `/wp-json/mcp/codi-network` as a distinct network MCP resource. Its catalogue contains only the three gateway abilities; package abilities remain site-local and are discovered/executed against an explicit target. One network connection can therefore route work to any site the authenticated user may access without making the main site's site endpoint network-capable. On each target, network-enabled packages contribute their registered abilities automatically, while delegated packages remain governed by that target site's package toggle and exposure selection. Remote discovery/execution is performed by an authenticated HTTP request to `/wp-json/codi-mcp/v1/federation` on the target site rather than by `switch_to_blog()`. The target therefore boots normally, loads its own site-active plugins, applies effective package/exposure policy locally, and executes through the target `WP_Ability`, including input/output validation and the ability's normal `permission_callback`.

Federation assertions are short-lived HMAC signatures derived from the WordPress authentication salt and bound to network ID, target site ID, originating WordPress user ID, operation, request-body hash, issue/expiry times, and a nonce. Used nonces are retained for the assertion window to reject replay. A site-bound OAuth bearer token is never reused as authority on another site. Federation requires HTTPS by default; loopback/DNS/TLS failures are surfaced as target-site availability errors rather than bypassed with manual plugin loading.

Built-in abilities do not opt into the Adapter's generic public/default-server surface. Explicit inclusion in the dedicated Codi server is the only MCP exposure authority.

## OAuth model

Codi MCP core owns the OAuth implementation: PKCE S256 authorization-code flow, bounded Dynamic Client Registration for current clients, short-lived access tokens, rotating refresh tokens, RFC 7009-style revocation, hashed credential lookup keys, redirect URI validation, login/consent, and OAuth metadata endpoints. Public DCR is rate-limited per source and globally over a one-hour window. Never-authorized clients are pruned after one hour and authorized clients after 90 days of inactivity. Site OAuth state uses non-autoloaded site options and a site-option mutex; network OAuth state uses network options and a network-option mutex. OAuth credential mutation is serialized through one resource-scoped state mutex so client deletion, token exchange, grant revocation, and token issuance cannot race across independent credential buckets. Credential buckets carry explicit expiries, so object-cache flushes cannot invalidate OAuth sessions. Authorization codes and refresh tokens are consumed before replacement credentials are returned, and credential persistence/consumption failures fail closed.

Each authorization creates a random grant identifier carried into its access/refresh records. New refresh tokens also carry that grant identifier in an integrity-protected token envelope signed from the WordPress authentication salt and the exact OAuth resource identity. This lets a replayed invalidated refresh token identify the grant even after the consumed-token storage entry has expired. Replay marks the grant revoked before active family credentials are removed; bearer validation and subsequent issuance both consult the revoked-grant marker, so partial cleanup failures still fail closed. Client deletion removes its outstanding codes/access/refresh credentials, and bearer validation independently requires the issuing client record to remain present. Site/network admin pages expose immediate client disconnect, and the authorization server advertises a token revocation endpoint.

The site and network resources have distinct issuers, endpoints, storage scopes, bearer keys, and refresh-token integrity keys; a token for one cannot authenticate the other. The dedicated HTTP MCP endpoints require a valid Codi Bearer token at the Adapter transport-permission boundary. WordPress cookie authentication remains appropriate for the interactive `/oauth/authorize` login/consent page but is not an alternative credential for MCP requests. Web OAuth clients require HTTPS redirect URIs; HTTP loopback callbacks are limited to clients declaring `application_type=native`, and consent displays the callback origin. CIMD can replace Dynamic Client Registration when full metadata-document validation is implemented.

A valid Codi MCP bearer token resolves to its bound WordPress user early in request authentication. The MCP Adapter transport gate independently validates that the request carries a valid bearer for that exact resource, then every ability sees the normal current WordPress user and performs its own WordPress capability checks.

That keeps authorization simple and live:

- transport gate: authenticated WordPress user
- ability gate: each ability's own `permission_callback`
- multisite/site capabilities are evaluated at call time rather than treated as an OAuth-owned authorization model

OAuth scopes may still describe requested access, but they must not replace WordPress capability checks.

## Native whole-package replacement

`CorePackageInstaller` wraps the existing `Plugin_Upgrader::install()` and `Theme_Upgrader::install()` calls. A short-lived `upgrader_package_options` filter attaches Core's `temp_backup` metadata only to the exact uploaded ZIP, artifact type, and destination. The native pre-install hook rejects a destination mismatch before file movement. Existing recovery backups and maintenance files are preserved and block replacement.

Core owns package installation, backup movement, restoration, and deletion. Codi scopes its post-install hook with a per-call transaction token, runs verification before Core schedules shutdown cleanup, and completes restoration/cleanup while the package lock remains held. Core's queued callbacks for that upgrader instance are removed after the synchronous lifecycle finishes. A failed restoration retains the backup and maintenance protection.

`CoreFatalErrorCheck` is a narrow subclass exposing Core's protected automatic-updater probe. It reuses Core's maintenance-aware loopback and early scraping machinery, including for bootstrap failures that prevent ordinary plugins from loading. A scoped HTTP response observer additionally requires a positive success marker for Core's current scrape key and HTTP 200; missing markers cannot count as a healthy deployment. No generic HTTP/PHP execution tool is exposed.

Package layers resolve every WordPress site context in which the shared plugin/theme source is active. `DeploymentRuntimeVerifier` then runs Core's fresh-request fatal probe in each required context before commit. Network-active plugins cover every current site; site-active plugins cover only their active sites; themes cover sites using the stylesheet directly or as the active parent of a child. Inactive packages are not executed. Folder replacement reuses the same verifier after promotion/OPcache invalidation and before its transaction is marked committed. No autonomous recovery service or database rollback is added.

## Package: plugins

Location: `src/Packages/Plugins/`

Public ability IDs remain:

- `codi/plugin-upload`
- `codi/plugin-install`
- `codi/plugin-activate`
- `codi/plugin-deactivate`
- `codi/plugin-delete`
- `codi/plugins-list`
- `codi/plugin-info`
- `codi/plugin-export`

The package owns deployment-specific validation, ZIP inspection, WordPress upgrader use, activation state, self-protection, plugin-management permissions, installed-plugin metadata inspection, and plugin-specific export eligibility. Inbound artifact transfer uses the shared Core upload-capability system: `plugin-upload` creates a purpose-bound, size/hash-bound staging slot and returns a one-time HTTPS PUT URL; raw bytes are received by the generic Core `/uploads/...` endpoint and never pass through MCP. Export uses the bounded `ArtifactDownloadStore` and Core's short-lived, site/purpose/artifact-bound HTTPS GET capability. Archive bytes bypass MCP by default; sequential base64 chunks remain available only through explicit `transfer=chunks`. Supplied ZIPs pass a shared pre-extraction central-directory guard for entry count, total uncompressed bytes, and unsafe paths before WordPress extraction. Inspection includes must-use plugins plus dependency and Update URI headers. Upload/install is also the supplied-ZIP update path, so there is no redundant `plugin-update` ability. `plugin-delete` uses WordPress native deletion, requires an exact inactive standard plugin, and refuses Codi MCP itself. On multisite only a super administrator may delete shared plugin files, and deletion is refused while the plugin is network-active or active on any site. Export supports directory-backed standard plugins only and refuses Codi MCP itself.

## Package: system

Location: `src/Packages/System/`

Read-only abilities: `codi/runtime-info`, `codi/rest-routes-list`, `codi/cron-list`, `codi/rewrite-rules`, `codi/site-health`, `codi/error-log-tail`, `codi/cache-info`, `codi/roles-list`, and `codi/audit-log`. `site-health` can optionally execute WordPress-native direct callbacks for async tests. `error-log-tail` is fixed to the configured `WP_DEBUG_LOG` inside `WP_CONTENT_DIR`, byte/line bounded, and structurally credential-redacted. On multisite it is explicitly network-shared and super-admin-only. `audit-log` returns the bounded site-local execution/OAuth trail; Codi abilities use WordPress's custom `ability_class` extension point to wrap the native `WP_Ability::execute()` result and record terminal success/failure without duplicating core validation or permission logic. Audit records contain identifiers, input field names, and non-sensitive error codes, never input values or credentials.

Mutating abilities are deliberately narrow: `codi/cron-run` requires an exact scheduled event identity, `codi/rewrite-flush` requires an explicit hard/soft choice, and `codi/cache-flush` requires super-admin permission on multisite. No generic callback, function, shell, filesystem or HTTP execution surface is provided.

## Package: diagnostics

Location: `src/Packages/Diagnostics/`

Abilities: `codi/diagnostic-run`, `codi/diagnostic-arm`, and `codi/diagnostic-inspect`. Diagnostics also implements `RuntimePackage` so a specially authorized target request can register ephemeral collectors before ordinary request handling proceeds.

`diagnostic-run` accepts only one current-origin `GET`. It creates a one-use, short-lived diagnostic authorization bound separately to the authorizing MCP user, effective request actor, hashed target request URI, method, and site-local transient store, then calls the target through WordPress's HTTP API with redirects disabled. The effective actor defaults to the MCP user; `actor_user_id=0` requests an anonymous execution and a positive actor ID must resolve to an existing current-site user (or a multisite super administrator). The target consumes the authorization during plugin bootstrap and establishes that already-authorized WordPress user through `wp_set_current_user()` immediately when available, with an earliest `plugins_loaded` fallback and `determine_current_user` fallback for lazy resolution. Because core `auth_redirect()` validates native auth cookies independently of the current-user object, Diagnostics also creates a two-minute WordPress session token on the target request and injects `AUTH_COOKIE`, `SECURE_AUTH_COOKIE`, and `LOGGED_IN_COOKIE` values into that request's `$_COOKIE` state only; the token is destroyed at shutdown and no auth cookie is transported over HTTP. This bypasses only the interactive login mechanism; normal WordPress bootstrap, plugin hooks, admin routing and authorization checks still run. The target then installs collectors and writes a five-minute capture at shutdown; failure to consume the authorization fails closed. Because target application code can violate HTTP safe-method conventions and mutate state from a `GET`, the run ability is conservatively annotated destructive. This is intentionally not a generic HTTP requester.

`diagnostic-arm` creates the same class of one-use, site-local credential but binds it to an exact target URI and explicit `GET`, `POST`, `PUT`, `PATCH`, or `DELETE` method without originating a request. The consuming request must present the credential in `X-Codi-Diagnostic-Token`. Armed mode never installs Codi's fixed-user `determine_current_user` filter or request-local auth cookies; WordPress's own authentication, nonce and capability mechanisms remain authoritative. The credential authorizes collection only, not the application action, and request bodies/header values are never persisted. Path-only "next request" matching is intentionally not supported because concurrent requests could consume an observation arm nondeterministically.

Collectors prefer WordPress semantics over inference: `wp_error_added`, `doing_it_wrong_run`, the `wp_die` handler filters, nonce/referer verification hooks, HTTP API hooks, option/site-option and metadata mutation hooks, post/term/transient hooks, `current_screen`, REST callback context, `template_include`, `render_block_data`, `wp_redirect`, mail hooks, multisite switching, the active script/style registries, and object-cache counters where available. Database evidence uses request-scoped `SAVEQUERIES` when the constant was not already defined and attaches source attribution through `log_query_custom_data`; an explicit `SAVEQUERIES=false` is authoritative. Because Codi initializes during plugin bootstrap, database and runtime counts explicitly describe only the portion of the request after Diagnostics initialized.
Diagnostics marks queries and semantic state writes performed solely to establish/destroy its request-local authentication context as internal; those events are excluded from target DB/state totals, with excluded-query count retained for transparency.
When `explain_slow_queries` is explicitly enabled, Diagnostics considers only captured `SELECT` statements taking at least 5 ms, executes ordinary `EXPLAIN` for at most the three slowest while the unsanitized SQL remains in request memory, marks those EXPLAINs internal, and persists only redacted query shapes plus bounded plan fields. Database/driver error text from EXPLAIN is not persisted.

All collectors share one bounded source-attribution representation (component type/name, safe relative file, line, callable, and relevant hook). Diagnostic persistence excludes mutation values and mail content/recipients; query literals, URL query values, nonce values and authentication/session tokens are removed before storage/return, and existing sensitive-data redaction is applied to error text. Named hooks may opt into bounded whole-hook timing and registered-callback provenance; named capability checks opt into `map_meta_cap` tracing plus `user_has_cap` final-stage evidence where WordPress reaches that filter. This keeps capability diagnostics useful for multisite super admins, which core otherwise short-circuits before `user_has_cap`. Neither uses global instrumentation. Diagnostics does not own browser/DOM/JavaScript profiling, a database drop-in, arbitrary callback execution, or continuous APM.

## Package: jobs

Location: `src/Packages/Jobs/`

The package registers only when Action Scheduler is present, and execution additionally requires Action Scheduler to have completed its normal `action_scheduler_init` lifecycle. Its external domain is deliberately generic (`jobs`), while `ActionSchedulerBackend` contains all provider-specific class/function references.

Abilities are `codi/jobs-list`, `codi/job-inspect`, `codi/job-run`, and `codi/job-cancel`. All Jobs operations require `manage_options` and additionally a super administrator on multisite. Reads use Action Scheduler's active store/logger abstractions and therefore do not assume the modern custom-table store. Job lists omit arguments. Exact inspection exposes bounded job metadata, claim state, fully string-redacted argument structure, registered callback/source attribution through Core's shared `SourceAttribution`, and bounded credential-redacted scheduler logs.

Mutations mirror Action Scheduler's own administration semantics: `job-run` accepts one exact unclaimed pending action ID and invokes the native queue runner's `process_action()`; `job-cancel` accepts one exact unclaimed pending action ID and invokes the active store's `cancel_action()`, with an already-canceled action treated idempotently. Codi exposes no job creation/scheduling, retry/requeue, deletion, run-all, arbitrary hook invocation, or queue-store SQL. Scheduling policy remains application source code and deployment responsibility.

## Package: site

Location: `src/Packages/Site/`

Read-only ability: `codi/site-config`. Mutating ability: `codi/site-config-update`.

The package owns typed site-administration state rather than visual design, theme lifecycle, or source code. Configuration is an explicit typed allowlist and never accepts an option name from the caller. Front-page/posts-page IDs are validated as usable pages; timezone/locale/permalink inputs are bounded and validated; permalink changes cause only a soft rewrite flush. Theme lifecycle belongs exclusively to the Themes package.

## Package: themes

Location: `src/Packages/Themes/`

Abilities are `codi/theme-upload`, `codi/theme-install`, `codi/theme-activate`, `codi/theme-delete`, `codi/themes-list`, `codi/theme-info`, and `codi/theme-export`.

The package owns installed-theme inspection and lifecycle policy, while raw artifact transfer remains shared Core infrastructure. `theme-upload` creates a purpose-bound, size/hash-bound staging slot and returns the same one-time HTTPS PUT capability used by other inbound uploads; Core receives and verifies the stream. Export remains a separate `ArtifactDownloadStore` workflow. Block-theme export delegates artifact generation to WordPress's native Site Editor exporter so resolved database-backed templates, template parts, and Global Styles are written into the ZIP; an inactive requested stylesheet is exposed to Core only through request-local theme filters, never by switching the site's active theme. Classic themes retain bounded whole-directory export. Native root-layout exports and conventional stylesheet-directory ZIPs are both accepted on install; root-layout packages are given a temporary `stylesheet.zip` package name before `Theme_Upgrader` so the requested installation directory is stable. `theme-install` remains the supplied-ZIP update path, and supplied ZIPs pass the shared pre-extraction entry-count/uncompressed-size/path guard before WordPress extraction. Activation uses an exact installed stylesheet and, on multisite, requires the theme already to be enabled for the current site. Deletion is network-aware: on multisite only a super administrator may delete shared theme files, and deletion is refused while that stylesheet is active or is the active parent of a child theme on any site. Codi never edits theme source files in place; source changes use export, external editing/validation, and ZIP redeployment.


## Folder-scoped package deployment

Plugins and Themes share the bounded `Core/Deployment/ReplacementSelection`, `FolderArchive`, and `FolderReplacement` mechanics. Each package still owns installed identity resolution, native capability checks, plugin entry-point/self-protection, and its cache/result handling. The upload's `replace` selection defaults to `entire`; folder mode binds one relative directory and its original existence/content state to the immutable upload metadata. Install calls continue to accept only `upload_id`.

Entire mode keeps the existing WordPress upgrader path. Folder mode inspects a wrapped archive and stages only the chosen subtree in private same-filesystem storage, checks the upload-bound fingerprint, and performs maintenance-protected staging-to-target promotion with verification and compensation. An existing destination is first moved to backup; a missing destination is added only when its parent already exists, and compensation restores its original absence. It invalidates original paths before moving them, including removed files, then invalidates installed or restored paths. If the owning package is active in one or more WordPress site contexts, the generic folder transaction receives a package-supplied verifier and commits only after all required runtime checks succeed. It does not execute staged PHP directly, infer cross-folder dependency compatibility, modify package root headers, or write theme database overrides.

Per-target filesystem locks coordinate Codi entire installs, folder replacements, and deletion across site and network requests without a short expiring option lease. A durable transaction journal and backup survive process termination; unfinished transactions block subsequent Codi deployments until operator recovery. Deployment recovery storage is independent of upload-slot expiry. Recovery and filesystem/backend requirements are documented in README.md. Folder-scoped export and multi-folder transactions are not implemented.

## Package: content

Location: `src/Packages/Content/`

Read-only structural/entity inspection is `codi/post-types-list`, `codi/taxonomies-list`, `codi/terms-list`, `codi/authors-list`, `codi/registered-blocks-list`, `codi/registered-meta-list`, `codi/posts-list`, and `codi/registered-meta-get`. Term discovery is taxonomy-capability scoped. Post listing is explicitly constrained to entities the caller may edit. `codi/registered-meta-get` reads a value only for an exact post/term key that is registered, REST-exposed, and authorized for that object/key.

Structured content mutations are `codi/term-create`, `codi/term-update`, `codi/term-delete`, `codi/post-terms-update`, `codi/registered-meta-set`, `codi/registered-meta-delete`, `codi/post-author-set`, `codi/post-attributes-update`, `codi/post-create`, `codi/post-identity-update`, `codi/post-status-update`, `codi/post-featured-media-set`, `codi/post-duplicate`, `codi/post-trash`, `codi/post-restore`, and `codi/post-delete`. Term and metadata operations retain their native capability/schema checks. Post deletion/trash uses exact delete permission, publish/private/future transitions use the post type publish capability, and duplication additionally requires the post type create capability. Content deliberately does not edit post bodies, blocks, templates, theme presentation, site settings, or source files; Presentation owns WordPress-managed frontend composition, Media owns attachments, Site owns typed site configuration, and source changes happen through plugin/theme artifact export and redeployment.

## Package: media

Location: `src/Packages/Media/`

Abilities are `codi/media-config`, `codi/media-find`, `codi/media-inspect`, `codi/media-upload`, `codi/media-create`, `codi/media-update`, and `codi/media-delete`. Media discovery and inspection respect exact attachment readability; permanent deletion requires exact delete permission. Attaching or re-parenting requires edit permission on the destination post. Local media transfer uses the same shared Core upload-capability system: `media-upload` returns a one-time HTTPS PUT URL bound to the declared filename, MIME metadata, size, and SHA-256; the resulting `upload_id` is consumed by `media-create` or `media-update`. Genuine remote HTTP(S) sources remain supported separately and are capped by the WordPress upload-size limit. Callers cannot write `post_mime_type` directly. Binary replacement snapshots the previous native file/metadata state, compensates it if a later post mutation fails, consumes a staged upload only after success, and retires old attachment files only after the replacement commits.

## Package: database

Location: `src/Packages/Database/`

The read-only abilities are `codi/db-tables`, `codi/db-schema`, and `codi/db-query`. They require `manage_options` and additionally require super-admin permission on multisite. `db-query` is not an arbitrary SQL gateway: it accepts only one `SELECT` or `EXPLAIN SELECT`; values must use WordPress `%s`, `%d`, or `%f` placeholders and every direct table source must use `%i`, all with exact parameter matching. Validation is dependency-free: Codi contains a deliberately small tokenizer/structural validator for the supported read-only subset, including nested derived SELECTs, table-source extraction, function allowlisting, wildcard-join rejection, and sensitive-projection checks. It is not a general SQL parser. A single identifier privacy predicate is applied to quoted and unquoted simple identifiers before execution, so qualification, aliasing, case, quoting, or derived-query placement cannot change the classification of credential-like source fields. Lexical checks remain defense in depth. Comments, quoted/numeric literals, multi-statements, session assignments, implicit comma joins, writes, locking clauses, unknown or dangerous functions, server schemas, protected credential fields, and tables outside the current-site/shared WordPress scope are rejected. The server enforces row and response-byte ceilings and redacts credential-like fields/values before returning results. No database-write ability is registered.

## Package: presentation

Location: `src/Packages/Presentation/`

Presentation owns WordPress-managed frontend composition while Content owns entity identity/state, Media owns attachment files, Site owns typed site configuration, and Plugins/Themes own source artifacts. Its public workflow is:

`find → inspect → preview → verify → commit → history → rollback`

The package exposes exactly eight abilities: `codi/presentation-help`, `codi/presentation-find`, `codi/presentation-inspect`, `codi/presentation-preview`, `codi/presentation-verify`, `codi/presentation-commit`, `codi/presentation-history`, and `codi/presentation-rollback`. All are ordinary WordPress Abilities and are default-disabled by Codi MCP exposure policy.

Native WordPress state is authoritative. Preview stores an immutable proposed before/after/checksum without performing a native write. Commit rechecks freshness and authorization, persists through WordPress-native semantics, reads the result back for semantic comparison, and records bounded non-autoload rollback history. History index mutations are serialized so concurrent commits/discards cannot lose transaction references. Persistence failures compensate toward the original native state; failed commits preserve the preview for inspection/retry. Rollback uses the same native ownership rules and refuses to overwrite diverged state.

Gutenberg writes are deliberately semantic and fail-closed. Supported static blocks use explicit PHP save codecs under `Presentation/Gutenberg/`; unknown blocks are not attribute-mutable and are preserved only as intact structural nodes. The package provides bounded operations for composition, navigation entries, lists, Query Loop parameters, template assignment, and native user-origin Global Styles. It does not expose generic block-attribute setters, arbitrary CSS/style trees, filesystem/PHP/shell/database execution, or theme/plugin source mutation.

Transport/auth ownership remains in Codi MCP core. The MCP connection is site-local, public Presentation inputs contain no site selector, and OAuth scopes never replace WordPress capability checks. Stable Presentation refs use `codi:presentation:v1:`; operational preview/history metadata is not a second presentation source of truth.

The plugin-level PHP requirement is 8.1.

## Dependency direction

```text
WordPress Abilities API
        ↑
        │ register abilities
        │
 Packages/Plugins     Packages/Presentation     other plugins
        \\                   |                 /
         \\                  |                /
          └────────── Codi MCP core ─────────┘
                    OAuth / exposure
                          │
                          ↓
              official MCP Adapter
                 Codi server
                          │
                          ↓
                       clients
```

Rules:

- core may discover packages and WordPress abilities, but must not call package domain internals
- bundled packages may use WordPress and the small internal package contract, but must not own transport/authentication; external plugins integrate only through the standard WordPress Abilities API
- ability `permission_callback`s remain authoritative for operation-level authorization
- no arbitrary filesystem MCP ability is introduced; deployment/export remain bounded whole-plugin or whole-theme artifact workflows over shared transfer primitives
- no package may silently expose another package's abilities

## Consolidated state

The bundled packages register ordinary WordPress Abilities. Codi MCP core owns the separate site/network OAuth resources, bearer-to-WordPress-user authentication, the site-local exposure store/admin UI, network package policy, bounded audit storage, and dedicated server registration through the official MCP Adapter. Codi-specific ability auditing uses the standard custom-ability-class extension point and does not alter unrelated abilities or the Adapter's default server. Presentation owns only WordPress-managed frontend composition and its preview/verification/history runtime; it has no OAuth or MCP transport ownership.
