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

Upload/install requires `install_plugins` and `update_plugins`. `plugin-upload` creates a purpose-bound staging slot and returns a one-time HTTPS PUT capability for the declared ZIP size and SHA-256; the file bytes are streamed directly to Core's shared upload receiver and never travel through MCP. `plugin-install` then verifies and installs that staged ZIP and is also the supplied-ZIP update path. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation/deactivation requires `activate_plugins`. Deletion requires `delete_plugins`, only accepts an exact inactive standard plugin, refuses to delete Codi MCP itself, and is intentionally unavailable on multisite because plugin files are shared across sites. `plugin-export` packages one directory-backed standard plugin into a bounded ZIP and returns it through Codi's shared chunked-download store; Codi MCP cannot export itself. Plugin inspection also includes must-use plugins plus dependency and Update URI metadata.

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

`src/Packages/Themes/` owns installed-theme lifecycle and whole-theme ZIP transfer:

- `codi/theme-upload`
- `codi/theme-install`
- `codi/theme-activate`
- `codi/theme-delete`
- `codi/themes-list`
- `codi/theme-info`
- `codi/theme-export`

`theme-upload` uses the same shared Core upload-capability system as plugins: it returns a one-time HTTPS PUT destination bound to the declared ZIP size and SHA-256, while theme export remains on the shared bounded chunked-download store. There is no package-specific inbound transfer protocol. `theme-install` is also the supplied-ZIP update path, so no separate theme-update ability is needed. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation requires `switch_themes`, rejects WordPress-detected broken themes, and on multisite can activate only themes already enabled for the current site. Theme deletion is unavailable on multisite and protects the active theme, active parent, and installed parent themes required by child themes. Theme source is never edited in place: export the theme ZIP, edit and validate it externally, then redeploy it through the Themes package.

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

### Database

`src/Packages/Database/` provides read-only database inspection:

- `codi/db-tables`
- `codi/db-schema`
- `codi/db-query`

Database inspection requires `manage_options` and additionally requires a super administrator on multisite. `codi/db-query` accepts only one `SELECT` or `EXPLAIN SELECT`: values must use unquoted `%s`, `%d`, or `%f` placeholders and direct table names must use `%i`. A small dependency-free tokenizer/structural validator checks statement shape, nested derived SELECTs, table sources, function allowlisting, wildcard joins, and redactable sensitive projections; lexical checks remain as defense in depth. It rejects comments, embedded quoted/numeric literals, implicit comma joins, writes, locking clauses, unknown or dangerous functions, server schemas, protected credential fields, and tables outside the current-site/shared WordPress scope, and applies hard row/response-size limits plus credential-like response redaction. Database writes are not exposed.

### Presentation

`src/Packages/Presentation/` owns WordPress-managed frontend composition: post content, block templates and parts, patterns, navigation, Query Loops, native user-origin Global Styles, and template assignment. It registers exactly eight abilities: `codi/presentation-help`, `codi/presentation-find`, `codi/presentation-inspect`, `codi/presentation-preview`, `codi/presentation-verify`, `codi/presentation-commit`, `codi/presentation-history`, and `codi/presentation-rollback`. Mutations follow `find → inspect → preview → verify → commit → history → rollback`; preview is non-writing, commit is stale-checked against native WordPress state, rollback refuses diverged targets, and history index mutations are serialized so concurrent commits cannot silently lose transaction references. Gutenberg mutation is semantic and fail-closed: supported static blocks use explicit save codecs, unknown blocks remain read-only except for intact structural preservation, and there is no generic attribute/CSS setter. Presentation never edits theme/plugin source; source changes use whole-artifact export, external validation, and ZIP redeployment through Plugins or Themes.

## MCP and ability exposure

Codi MCP registers a dedicated Codi server through the Adapter's public `create_server()` API without changing the Adapter's own default-server setting. The Adapter still owns HTTP transport, protocol handling, sessions/version negotiation, errors, and conversion of WordPress Abilities into MCP components; other plugins remain free to use the Adapter's default server independently.

Codi keeps one canonical ability catalogue over the live WordPress Abilities registry. Catalogue entries are classified as **Codi-owned** or **external**. Codi-owned abilities are explicitly private (`meta.public=false`, `meta.mcp.public=false`, `show_in_rest=false`) and are exposed only through Codi policy. External abilities are eligible for adoption only when their effective MCP public intent is true: explicit `meta.mcp.public` takes precedence, otherwise `meta.public` is used. Private external abilities are not selectable through Codi.

The Codi MCP admin screen lists only abilities eligible for Codi governance in that site's real bootstrap context and labels their origin. On multisite, abilities belonging to packages set to **Enabled** in Network Admin are network-managed and automatically exposed wherever those packages register; they are not editable per site. Abilities belonging to delegated packages and eligible adopted external abilities remain default-deny and must be explicitly selected by a site administrator. Every underlying ability permission callback still runs on each invocation.

Codi's catalogue/governance layer is separate from its MCP presentation layer. The current presentation exposes selected abilities directly as MCP tools/resources/prompts, preserving each ability's precise schema and risk annotations. This separation allows a future layered or hybrid discovery presentation without changing package implementations, exposure policy, or the canonical catalogue. The Adapter's generic discovery/get-info/execute gateway is not included in Codi's dedicated server.

Codi-owned abilities declare `readonly`, `destructive`, `idempotent`, and `openWorldHint` centrally. Codi-owned execution keeps the Codi audit wrapper; adopted external abilities are audited at the Codi MCP/federation execution boundary without replacing the originating ability implementation or duplicating Codi-owned audit records.

## OAuth

Codi MCP uses an OAuth 2.1-style authorization-code flow with PKCE S256, RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, RFC 8707 resource indicators, RFC 9207 `iss` responses, short-lived bearer access tokens, and rotating refresh tokens.

Every WordPress site, including the multisite main site, has a permanently site-local `/wp-json/mcp/codi` resource. Site OAuth state is stored in non-autoloaded site options with explicit credential expiries, and bearer records are bound to `site:<site-id>|mcp/codi`, so neither a different site nor the network gateway can accept the token. OAuth credentials do not use WordPress transients and therefore survive object-cache flushes.

Dynamic Client Registration is supported for current clients, including ChatGPT. Registration metadata is bounded, public registration is limited per source and globally over a one-hour window, never-authorized clients are pruned after one hour, and authorized clients that remain idle for 90 days are pruned. Site registration and credentials use site-option mutexes; network registration and credentials use network-option mutexes. MCP 2026-07-28 prefers Client ID Metadata Documents (CIMD); Codi does not advertise CIMD until it implements full remote client-metadata validation.

`offline_access` is an optional authorization-server scope a client may use to explicitly request refresh-token capability. It is not advertised as an MCP resource scope. Codi may issue a refresh token whenever the registered OAuth client supports the `refresh_token` grant, whether or not the client also requests `offline_access`; refresh-token exchanges rotate the refresh token. Rotation consumes the old credential before replacement credentials are returned, and persistence/consumption failures fail closed rather than returning credentials whose state was not durably recorded.

## Multisite

Codi MCP keeps site OAuth clients/tokens and delegated ability exposure state per site. On multisite, **Network Admin → Codi MCP** controls bundled packages as enabled, disabled, or delegated. **Enabled** forces the package on and automatically exposes all abilities it registers on each site; **Disabled** removes it; **Delegate to site** leaves package availability and ability exposure to that site's Codi MCP page. The optional `multisite` package is network infrastructure and remains disabled by default.

When `multisite` is enabled on the network, the main site additionally serves `/wp-json/mcp/codi-network` as a distinct network MCP resource with its own OAuth issuer under `/wp-json/codi-mcp/v1/network`. Network OAuth clients, authorization codes, access tokens, refresh tokens, registration throttling, and mutexes are stored in network options and bearer records are bound to `network:<network-id>|mcp/codi-network`. The network catalogue contains only abilities from packages explicitly **Enabled** by network policy plus the multisite gateway abilities. Delegated main-site abilities remain available only through the main site's ordinary `/wp-json/mcp/codi` resource.

## Tests

Run the isolated unit/regression suite with `php tests/run.php`. The WordPress integration suite uses the standard WordPress PHPUnit test library; set `WP_TESTS_DIR` to that library and run both `phpunit.integration.xml.dist` and `phpunit.integration.multisite.xml.dist`. The single-site configuration exercises real WordPress ability execution, audit persistence, and OAuth option storage. The multisite configuration runs the same coverage plus the native activation fixture that verifies a failed promotion from site activation to network activation preserves the original site activation.
