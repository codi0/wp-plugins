# Codi MCP

Codi MCP is a per-site WordPress MCP host and ability-governance layer for OAuth, governed ability exposure, and packaged WordPress abilities. It uses the official WordPress Abilities API as its canonical capability registry and the official MCP Adapter for protocol and transport.

Each activated site registers a dedicated MCP server through the official Adapter at:

`/wp-json/mcp/codi`

OAuth is also site-local under:

`/wp-json/codi-mcp/v1`

## Ability packages

### Plugins

The Plugins package lives under `src/Packages/Plugins/` and provides:

- `codi/plugin-upload`
- `codi/plugin-install`
- `codi/plugin-activate`
- `codi/plugin-deactivate`
- `codi/plugin-delete`
- `codi/plugins-list`
- `codi/plugin-info`
- `codi/plugin-export`

Upload/install requires `install_plugins` and `update_plugins`. `plugin-upload` creates a purpose-bound staging slot and returns a one-time HTTPS PUT capability for the declared ZIP size and SHA-256; the file bytes are streamed directly to Core's shared upload receiver and never travel through MCP. `plugin-install` then verifies and installs that staged ZIP and is also the supplied-ZIP update path. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation/deactivation requires `activate_plugins`. Deletion requires `delete_plugins`, only accepts an exact inactive standard plugin, and refuses to delete Codi MCP itself. On multisite it additionally requires a super administrator and scans network activation plus every site's `active_plugins`; deletion is refused while the plugin is active anywhere. `plugin-export` packages one directory-backed standard plugin into a bounded ZIP and returns a short-lived HTTPS download URL by default; Codi MCP cannot export itself. Plugin inspection also includes must-use plugins plus dependency and Update URI metadata.

### System

`src/Packages/System/` provides site/runtime inspection and narrow operational abilities:

- `codi/runtime-info`
- `codi/rest-routes-list`
- `codi/cron-list`
- `codi/rewrite-rules`
- `codi/site-health`
- `codi/error-log-tail`
- `codi/cache-info`
- `codi/roles-list`
- `codi/audit-log`
- `codi/upload-cancel`
- `codi/cron-run`
- `codi/rewrite-flush`
- `codi/cache-flush`

Inspection abilities require `manage_options`. `codi/upload-cancel` removes a temporary unconsumed upload slot and is purpose-bound to plugin, theme, or media staging; it requires the same native capability family as the upload it cancels and is idempotent when the slot has already expired or been consumed. Site Health can optionally execute WordPress-native direct callbacks for async checks without exposing a generic HTTP requester. `error-log-tail` reads only the configured `WP_DEBUG_LOG` when it resolves inside `WP_CONTENT_DIR`, applies byte/line limits, and structurally redacts credential-like JSON plus credential-shaped text. On multisite it is explicitly network-shared and requires a super administrator. Cron argument inspection redacts sensitive keyed values and all positional string values because positional strings have no trustworthy semantic key. `codi/audit-log` returns a bounded site-local trail of Codi ability executions and OAuth registration/authorization events; it records identifiers, terminal success/failure status, non-sensitive error codes, and input field names, never input values or credentials. `cron-run` executes only an exact event returned by the cron schedule. Rewrite flushing requires an explicit soft/hard choice, and cache flushing requires a super administrator on multisite.

### Site

`src/Packages/Site/` owns typed site administration that is intentionally outside Presentation and Themes:

- `codi/site-config`
- `codi/site-config-update`

Site configuration exposes only a typed allowlist: title/tagline, front/posts-page assignment, timezone, permalink structure, posts-per-page, search-engine visibility, locale, date/time formats, and start-of-week. It is not a generic option setter. Front/posts pages, timezones, locales, and permalink tags are validated. Multi-setting updates are read back after each write and compensate earlier writes if persistence fails; permalink changes use a soft rewrite flush only after the complete update succeeds. Theme lifecycle is owned exclusively by the Themes package.

### Themes

`src/Packages/Themes/` owns installed-theme lifecycle and ZIP deployment/export:

- `codi/theme-upload`
- `codi/theme-install`
- `codi/theme-activate`
- `codi/theme-delete`
- `codi/themes-list`
- `codi/theme-info`
- `codi/theme-export`

`theme-upload` uses the same shared Core upload-capability system as plugins: it returns a one-time HTTPS PUT destination bound to the declared ZIP size and SHA-256, while theme export uses the shared bounded artifact store and short-lived HTTPS downloads. There is no package-specific inbound transfer protocol. Block themes are generated with WordPress's native Site Editor exporter so saved template/template-part and Global Styles database overrides are baked into the ZIP. Codi can do this for an inactive installed theme without activating it by applying request-local stylesheet/template context only while Core generates the artifact; classic themes continue to use whole-directory export. `theme-install` accepts both conventional stylesheet-directory ZIPs and WordPress native root-layout theme exports, and is also the supplied-ZIP update path, so no separate theme-update ability is needed. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation requires `switch_themes`, rejects WordPress-detected broken themes, and on multisite can activate only themes already enabled for the current site. Theme deletion requires `delete_themes`; on multisite it additionally requires a super administrator and scans every site's active stylesheet/template pair. Deletion is refused while the theme itself or any child theme using it as a parent is active anywhere. Theme source is never edited in place: export the theme ZIP, edit and validate it externally, then redeploy it through the Themes package.


### Direct exports

New `plugin-export` and `theme-export` calls need only the package identity. The response includes filename, byte size, SHA-256, and a five-minute HTTPS GET URL. A file-transfer client downloads the archive directly; GET and HEAD require the signed URL and return raw ZIP bytes. URLs are bound to the site, operation, staged artifact, and its integrity metadata. Treat them as temporary credentials.

The staged snapshot expires after one hour. Use its `download_id` with `transfer: "url"` (or with `transfer` omitted) to renew a URL while the snapshot remains available. Sequential base64 transfer must be selected explicitly with `transfer: "chunks"`; `offset` is valid only in chunk mode, and callers continue with `next_offset`.

### Core-backed replacement protection

Entire plugin and theme deployments continue to use WordPress's native uploaded-package installers. When the destination already exists, Codi enables Core's temporary backup through the public `upgrader_package_options` hook. Core performs the file moves, restoration, and cleanup; Codi completes them before releasing its package lock. New installations retain the ordinary Core install path. Activation state is not intentionally changed.

For plugins or themes that are active in any affected WordPress site context, Codi invokes Core's automatic-updater fatal-error probe while maintenance protection and the original backup remain in place. On multisite, shared plugin/theme source is checked against every site where that plugin, theme, or parent theme is active; network-active plugins therefore verify every current network site. Codi requires a positive successful scrape response for every required context. An unreachable, blocked, or unconfirmed probe rejects the update. A handled installation or verification failure restores the original package through `WP_Upgrader::restore_temp_backup()`. Failed restoration retains the Core backup and maintenance protection and blocks another Codi whole-package replacement until recovery is resolved.

This is a fresh-request bootstrap/fatal check of each required site's homepage, not exhaustive application testing. Inactive packages receive file-operation rollback protection but are not executed by the probe. Folder mode uses the same runtime-context policy: after promotion and OPcache invalidation, but before commit, the same verifier must succeed or the existing folder transaction restores the previous directory/absence. There is no separate PHP CLI linter, background recovery service, database rollback, or guarantee of recovery after the deployment process is killed. Backups use Core's `wp-content/upgrade-temp-backup/plugins|themes/<slug>` location and remain subject to Core's cleanup behaviour.

### Entire package or one folder

Plugin and theme upload calls accept optional `replace` and `folder` fields:

- `replace: "entire"` is the default. It retains the native WordPress whole-package install/update path.
- `replace: "folder"` adds or replaces one exact relative directory, for example `folder: "src/Contacts"`. The installed package and the parent directory (`src` in this example) must already exist; the selected leaf folder may be missing. Supplying a folder without explicitly selecting folder mode is rejected.

For example, call `codi/plugin-upload` with the usual `plugin_slug`, `size`, and `sha256`, plus `replace: "folder"` and `folder: "src/Contacts"`. Stream the ZIP to the returned HTTPS PUT URL, then call `codi/plugin-install` with only `upload_id`. For a theme, use the same fields with `theme-upload`/`theme-install` and the existing `stylesheet` identity.

Folder-mode ZIPs must contain a top-level directory matching the plugin slug or theme stylesheet. A complete ZIP or a reduced ZIP containing only the selected directory is accepted; all entries are inspected, but only the selected subtree is extracted and installed. Root-level native theme exports remain supported in entire mode. An explicitly empty selected directory clears its contents; a missing selected source directory is an error.

The installed package, selection, and destination's original existence/content state are bound to the upload. Installation refuses an intervening change, including a missing destination appearing before installation. An addition does not overwrite an existing file or symlink. Edits outside the selected directory are preserved, even if the ZIP contains different versions of those files. Files omitted inside the selected directory are removed. Package root files, version headers, activation state, and theme database customizations are unchanged. Folder mode rejects symlinks, ambiguous archive paths, installed plugin entry points inside the selection, and Codi MCP self-replacement.

Folder mode requires PHP ZipArchive, direct WordPress filesystem access, and private temporary staging outside the WordPress/document roots on the target filesystem. Inspection is bounded to 5000 entries and 100 MiB per selected tree. Codi coordinates entire installs, folder replacements, and deletions through a target-specific filesystem lock. Other deployment systems and manual filesystem edits do not participate in this lock.

After staging and rechecking the fingerprint, Codi briefly enables maintenance protection, backs up an existing directory when present, installs and verifies the staged folder, and invalidates affected OPcache paths. Handled failures restore the original directory or undo a new addition. Replacement uses two renames and addition uses one; neither is a zero-downtime atomic exchange; in-flight requests are not drained by maintenance mode.

A killed process or failed restoration can require operator recovery. An unfinished journal blocks further Codi deployments of that target. Recovery data is held separately from expiring upload slots under `<temporary-directory>/codi-mcp/deployments/transactions/<target-key>/<upload-id>/`; the error also identifies this directory. Keep its `journal.json` and any `original/` backup until the destination is restored or explicitly verified. The journal's `exists` field distinguishes replacement from addition; an interrupted addition has no original backup and recovery must restore absence or explicitly verify and retain the new folder. With other deployments stopped, use the journal's root/folder to restore the original directory (moving any incomplete installed replacement aside), invalidate affected OPcache, then remove the resolved transaction directory and only Codi's owned maintenance file. Do not clear a maintenance file owned by another deployment. Maintenance protection follows WordPress's normal expiry behavior, so an interrupted deployment still needs prompt operator attention.

### Content structures

`src/Packages/Content/` owns registered content structures and structured content relationships/attributes, while Presentation owns visual/block composition:

- `codi/post-types-list`
- `codi/taxonomies-list`
- `codi/terms-list`
- `codi/authors-list`
- `codi/registered-blocks-list`
- `codi/registered-meta-list`
- `codi/posts-list`
- `codi/registered-meta-get`
- `codi/term-create`
- `codi/term-update`
- `codi/term-delete`
- `codi/post-terms-update`
- `codi/registered-meta-set`
- `codi/registered-meta-delete`
- `codi/post-author-set`
- `codi/post-attributes-update`
- `codi/post-create`
- `codi/post-identity-update`
- `codi/post-status-update`
- `codi/post-featured-media-set`
- `codi/post-duplicate`
- `codi/post-trash`
- `codi/post-restore`
- `codi/post-delete`

Taxonomy discovery and mutations use each taxonomy's native capabilities, and assignment accepts existing term IDs only, so assignment cannot create terms implicitly. Post listing is constrained to entities the caller can edit. Post deletion/trash uses exact delete permission; publish/private/future transitions use the post type's publish capability; duplication additionally requires create permission for that post type. Metadata value access is limited to post/term keys that are registered and explicitly REST-exposed; writes are schema-validated and still pass through WordPress metadata sanitization/authentication. There is no arbitrary post-meta setter. Content owns entity lifecycle and structured attributes, but does not edit post bodies, blocks, templates, themes, or source files.

### Media

`src/Packages/Media/` owns WordPress media-library discovery, uploads, attachment metadata, binary replacement, and deletion:

- `codi/media-config`
- `codi/media-find`
- `codi/media-inspect`
- `codi/media-upload`
- `codi/media-create`
- `codi/media-update`
- `codi/media-delete`

Media reads respect exact attachment readability; deletion requires exact delete permission. Attaching or re-parenting media requires edit permission on the destination post. `media-upload` uses the same shared Core upload-capability system and returns a one-time HTTPS PUT destination for a declared local file; the resulting `upload_id` can be consumed by `media-create` or `media-update`. Genuine remote HTTP(S) sources remain supported separately and are capped by the WordPress upload-size limit. Binary replacement derives MIME type from the accepted file, compensates attachment file/metadata state if a later post update fails, and retires the previous attachment files only after the replacement commits successfully.

### Diagnostics

`src/Packages/Diagnostics/` provides bounded model-facing diagnostics for one real same-site WordPress request:

- `codi/diagnostic-run`
- `codi/diagnostic-arm`
- `codi/diagnostic-inspect`

`diagnostic-run` creates a short-lived one-time authorization, performs one same-origin `GET` through WordPress's HTTP API, and records request-local evidence after Codi Diagnostics initializes. By default the target executes as the authorized MCP WordPress user; optional `actor_user_id` can instead select an existing current-site user or `0` for a genuinely anonymous request, while the authorizing MCP user remains separately bound to the one-time credential. The compact run result reports the effective user ID used by the target request. `diagnostic-arm` instead creates a short-lived one-use credential bound to one exact same-origin target and one of `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`. A request that presents that credential in `X-Codi-Diagnostic-Token` is observed under its own normal WordPress identity; the credential enables Diagnostics only and does not authenticate or authorize the application request. The target consumes the credential early in plugin bootstrap and establishes only that effective request actor through core's current-user API. For admin requests, where core `auth_redirect()` independently validates native auth cookies, Diagnostics creates a short-lived request-local WordPress session and injects native auth cookies only into that in-process request; no auth cookie crosses the network. The request therefore exercises normal WordPress/admin routing without depending on an interactive login URL. It uses WordPress hooks and runtime objects rather than replacing the database, cache, HTTP, mail, routing, or rendering layers. Captures include request/status/timing context; PHP errors, `WP_Error`, `wp_die()` and `_doing_it_wrong()` events; nonce/referer verification failures; `SAVEQUERIES` database timing with redacted query shapes and source/component attribution; semantic option/meta/post/term/transient mutations without values; outbound HTTP timing; redirects; cache hit/miss deltas when the active cache exposes counters; admin/REST/rewrite/template/block context; enqueued asset dependencies; mail outcomes and preemption; multisite switches; and a bounded timeline. Named hooks can additionally report registered callback provenance plus aggregate wall time/query/HTTP counts, and capability checks are opt-in by explicit capability name. Capability tracing observes `map_meta_cap` so multisite super-admin checks remain visible, and also records `user_has_cap` final-stage evidence when WordPress reaches that filter.

Both run and arm can opt into `explain_slow_queries`. Diagnostics then selects at most three captured `SELECT` queries taking at least 5 ms and executes ordinary `EXPLAIN` while the original SQL is still request-local. The EXPLAIN operations are marked diagnostic-internal and excluded from target totals; captures persist only the sanitized query shape, source attribution, bounded plan metadata, and a generic failure marker when a plan cannot be obtained.

Diagnostic URLs are restricted to the current WordPress origin. `diagnostic-run` disables redirects; armed credentials are exact target/method bound and one-use. Request bodies and request-header values are not persisted. Run credentials bind the authorizing user and selected effective actor; arm credentials bind the authorizing Codi caller but deliberately preserve the consuming request's own WordPress identity. All diagnostic credentials fail closed if they cannot be consumed. Because WordPress/plugin code can perform side effects from a `GET`, `diagnostic-run` is conservatively annotated destructive even though its intended use is observation. The stored target binding is a hash rather than the raw query string. URL query values, SQL literals, authentication/session tokens, nonce values, credentials, mail content/recipients, and mutation values are not returned. Database capture is explicitly scoped to queries executed after Diagnostics initializes, and an explicit `SAVEQUERIES=false` is respected. Queries and state mutations caused solely by Diagnostics' request-local authentication setup are marked internal and excluded from target-request totals. Captures expire after five minutes. Diagnostics requires `manage_options` and additionally a super administrator on multisite.

### Jobs

`src/Packages/Jobs/` provides semantic background-job inspection and control when Action Scheduler is loaded on the current site:

- `codi/jobs-list`
- `codi/job-inspect`
- `codi/job-run`
- `codi/job-cancel`

The package registers no category or abilities when Action Scheduler is absent. Jobs requires `manage_options` and additionally a super administrator on multisite. It uses Action Scheduler's active store, logger, and queue runner rather than querying `actionscheduler_*` tables directly, so custom, legacy, and hybrid stores remain authoritative. Listing exposes job identity, hook/group, status, schedule, priority, and claim state but not arguments. Exact job inspection adds bounded arguments with all string values redacted, registered callback/source attribution, and bounded credential-redacted Action Scheduler logs. `job-run` and `job-cancel` accept only an exact existing unclaimed pending action ID; run delegates to Action Scheduler's native `process_action()` path and cancel delegates to its store. Already-canceled jobs make cancellation idempotent. Codi deliberately exposes no job-creation, arbitrary hook scheduling, queue-wide run, deletion, or generic callback execution ability; applications should schedule their own work in version-controlled plugin/theme code.

### Database

`src/Packages/Database/` provides read-only database inspection:

- `codi/db-tables`
- `codi/db-schema`
- `codi/db-query`

Database inspection requires `manage_options` and additionally requires a super administrator on multisite. `codi/db-query` accepts only one `SELECT` or `EXPLAIN SELECT`: values must use unquoted `%s`, `%d`, or `%f` placeholders and direct table names must use `%i`. A small dependency-free tokenizer/structural validator checks statement shape, nested derived SELECTs, table sources, function allowlisting, wildcard joins, and redactable sensitive projections; lexical checks remain as defense in depth. Credential-like identifiers are classified consistently whether quoted, unquoted, qualified, aliased, or nested, so SQL spelling cannot change the privacy policy applied before execution. It rejects comments, embedded quoted/numeric literals, implicit comma joins, writes, locking clauses, unknown or dangerous functions, server schemas, protected credential fields, and tables outside the current-site/shared WordPress scope, and applies hard row/response-size limits plus credential-like response redaction. Database writes are not exposed.

### Presentation

`src/Packages/Presentation/` owns WordPress-managed frontend composition: post content, block templates and parts, patterns, navigation, Query Loops, native user-origin Global Styles, and template assignment. It registers exactly eight abilities: `codi/presentation-help`, `codi/presentation-find`, `codi/presentation-inspect`, `codi/presentation-preview`, `codi/presentation-verify`, `codi/presentation-commit`, `codi/presentation-history`, and `codi/presentation-rollback`. Mutations follow `find → inspect → preview → verify → commit → history → rollback`; preview is non-writing, commit is stale-checked against native WordPress state, rollback refuses diverged targets, and history index mutations are serialized so concurrent commits cannot silently lose transaction references. Gutenberg mutation is semantic and fail-closed: supported static blocks use explicit save codecs, unknown blocks remain read-only except for intact structural preservation, and there is no generic attribute/CSS setter. Presentation never edits theme/plugin source; source changes use whole-artifact export, external validation, and ZIP redeployment through Plugins or Themes.

## MCP and ability exposure

Codi MCP registers a dedicated Codi server through the Adapter's public `create_server()` API without changing the Adapter's own default-server setting. The Adapter still owns HTTP transport, protocol handling, sessions/version negotiation, errors, and conversion of WordPress Abilities into MCP components; other plugins remain free to use the Adapter's default server independently.

Codi keeps one canonical ability catalogue over the live WordPress Abilities registry. Catalogue entries are classified as **Codi-owned** or **external**. Codi-owned abilities are ordinary registered WordPress abilities that explicitly opt out of generic client channels (`meta.public=false`, `meta.mcp.public=false`, and `show_in_rest=false`); they remain part of the standard registry while Codi independently governs whether they appear on its dedicated MCP server. External abilities are selectable only when their author has declared effective MCP public intent: explicit `meta.mcp.public` takes precedence, otherwise `meta.public` is used. Codi never overrides a private external ability's exposure intent.

The Codi MCP admin screen lists only abilities eligible for Codi governance in that site's real bootstrap context and labels their origin. On multisite, abilities belonging to packages set to **Enabled** in Network Admin are network-managed and automatically exposed wherever those packages register; they are not editable per site. Abilities belonging to delegated packages and eligible external abilities remain default-deny and must be explicitly selected by a site administrator. Every underlying ability permission callback still runs on each invocation.

Codi's catalogue/governance layer is separate from its MCP presentation layer. The site presentation exposes selected abilities directly as MCP tools/resources/prompts. Codi uses the Adapter's public tools-list filter to omit advertised output schemas on its own servers and remove empty input-schema properties maps; native WordPress input/output validation and risk annotations remain intact. This separation allows a future layered or hybrid discovery presentation without changing package implementations, exposure policy, or the canonical catalogue. The Adapter's generic discovery/get-info/execute gateway is not included in Codi's dedicated server.

Codi-owned abilities declare `readonly`, `destructive`, `idempotent`, and `openWorldHint` centrally. Codi-owned execution keeps the Codi audit wrapper; external abilities are audited at the Codi MCP/federation execution boundary without replacing the originating ability implementation or duplicating Codi-owned audit records.

## OAuth

Codi MCP uses an OAuth 2.1-style authorization-code flow with PKCE S256, RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, RFC 8707 resource indicators, RFC 9207 `iss` responses, short-lived bearer access tokens, rotating refresh tokens, and RFC 7009-style token revocation. The dedicated HTTP MCP resources are Bearer-only: a normal WordPress login/session is used for the human authorization/consent flow, but cannot substitute for a valid Codi OAuth bearer on `/wp-json/mcp/codi` or `/wp-json/mcp/codi-network`.

Every WordPress site, including the multisite main site, has a permanently site-local `/wp-json/mcp/codi` resource. Site OAuth state is stored in non-autoloaded site options with explicit credential expiries, and bearer records are bound to `site:<site-id>|mcp/codi`, so neither a different site nor the network gateway can accept the token. Access-token validation also requires the issuing client to still exist and rejects any grant marked revoked. OAuth credentials do not use WordPress transients and therefore survive object-cache flushes.

Dynamic Client Registration is supported for current clients, including ChatGPT. Registration metadata is bounded, public registration is limited per source and globally over a one-hour window, never-authorized clients are pruned after one hour, and authorized clients that remain idle for 90 days are pruned. Site registration and credentials use site-option mutexes; network registration and credentials use network-option mutexes. MCP 2026-07-28 prefers Client ID Metadata Documents (CIMD); Codi does not advertise CIMD until it implements full remote client-metadata validation.

`offline_access` is an optional authorization-server scope a client may use to explicitly request refresh-token capability. It is not advertised as an MCP resource scope. Codi may issue a refresh token whenever the registered OAuth client supports the `refresh_token` grant, whether or not the client also requests `offline_access`; refresh-token exchanges rotate the refresh token. Newly issued refresh tokens carry an integrity-protected grant identifier derived under the WordPress authentication salt. Reuse of an invalidated refresh token identifies and revokes the active grant family, including outstanding access and refresh credentials. Rotation consumes the old credential before replacement credentials are returned, and persistence/consumption failures fail closed rather than returning credentials whose state was not durably recorded.

Authorization-server metadata advertises `/oauth/revoke`. Revoking an access or refresh token invalidates the associated grant where Codi has grant-family state. **Settings → Codi MCP** lists registered site OAuth clients with a Disconnect action that invalidates that client's authorization codes, access tokens, and refresh tokens immediately; Network Admin provides the equivalent control for the network MCP resource. Web OAuth clients require HTTPS redirect URIs. HTTP loopback redirects are accepted only for clients registered as `application_type=native`, and the consent page shows the callback origin alongside the requested resource.

## Multisite

Codi MCP keeps site OAuth clients/tokens and delegated ability exposure state per site. On multisite, **Network Admin → Codi MCP** controls bundled packages as enabled, disabled, or delegated. **Enabled** forces the package on and automatically exposes all abilities it registers on each site; **Disabled** removes it; **Delegate to site** leaves package availability and ability exposure to that site's Codi MCP page. The optional `multisite` package is network infrastructure and remains disabled by default.

When `multisite` is enabled on the network, the main site additionally serves `/wp-json/mcp/codi-network` as a distinct network MCP resource with its own OAuth issuer under `/wp-json/codi-mcp/v1/network`. Network OAuth clients, authorization codes, access tokens, refresh tokens, registration throttling, and mutexes are stored in network options and bearer records are bound to `network:<network-id>|mcp/codi-network`. The network catalogue exposes only the multisite gateway abilities: `sites` discovers explicit target IDs, `site-abilities` discovers the target's exposed ability names/descriptions/input schemas and safety annotations when needed, and `site-call` executes a known ability. `site-abilities` accepts optional `search` and `detail` fields. Exact full names, then unique short names, take precedence; otherwise every whitespace-separated term must occur in the name or description. `detail: "auto"` returns input schemas for a single match and summaries for multiple matches; `summary` and `schema` force either view. Retrieve the exact ability schema before constructing arguments, and reuse discovered descriptors. Site-scoped package abilities are never executed implicitly against the main site. Network gateway responses avoid echoing caller-supplied site/ability identifiers and omit target output schemas and other redundant catalogue metadata to keep model context compact. Packages explicitly **Enabled** by network policy remain automatically available and exposed in each site's own runtime. Delegated main-site abilities remain available only through the main site's ordinary `/wp-json/mcp/codi` resource.

## Tests

The standalone `php -d extension=zip tests/integration/native-upgrader.php <disposable-wordpress-tree> --disposable` harness exercises real Core installation, backup, restoration, and fatal-probe classes without a database. It uses controlled HTTP scrape responses and deliberately writes fixtures into the supplied disposable tree; never point it at a working site.

Run the fast isolated invariant suite with `php tests/run.php`; run the complete release gate, including real filesystem deployment invariants, with `php tests/run.php --full`. Test files remain process-isolated and run with bounded parallelism (eight workers by default); set `CODI_MCP_TEST_WORKERS` to override that count. TestCase owns one canonical state reset before each test. Filesystem-backed theme fixtures are restored only for test classes that declare they use them, rather than during every global reset. Folder replacement transaction invariants live in `tests/slow/` because they deliberately exercise real directory fingerprints, renames, journals, recovery state, locks, maintenance files, and process interruption. They use an injected deterministic archive staging seam for transaction scenarios, while the archive-focused invariant exercises the native ZIP path when ZipArchive is available. The WordPress integration tests use the standard WordPress PHPUnit test library. Set `WP_TESTS_DIR` to that library and run `tests/integration/AuditOAuthIntegrationTest.php` with `tests/integration/bootstrap.php` as the PHPUnit bootstrap. Run `tests/integration/PluginActivationIntegrationTest.php` against a multisite-configured WordPress test environment. These tests exercise real WordPress ability execution, audit persistence, OAuth option storage, and the native activation invariant that a failed promotion from site activation to network activation preserves the original site activation.
