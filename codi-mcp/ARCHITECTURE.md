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

Built-in packages:

1. `plugins` — plugin ZIP deployment/lifecycle, bounded whole-plugin export, and installed/must-use plugin inspection.
2. `system` — runtime, REST, cron, rewrite, Site Health, debug-log, cache and role inspection plus narrowly bounded operational mutations.
3. `site` — typed site settings only; no generic option setter and no theme lifecycle.
4. `themes` — theme ZIP deployment/lifecycle, bounded whole-theme export, and installed-theme inspection; no source-file editing.
5. `content` — content-entity lifecycle, registered structure inspection, taxonomy relationships, REST-exposed registered metadata, and author attribution; no block/body editing.
6. `media` — media-library discovery, bounded transfer, attachment mutation/replacement, and deletion.
7. `database` — read-only database table/schema inspection plus bounded parameterized row-data queries; no database-write ability.
8. `presentation` — WordPress-managed Gutenberg composition, templates, patterns, navigation, Global Styles, preview/commit/history, and rollback.
9. `multisite` — optional network orchestration that lists accessible sites, discovers each target site's live exposed abilities, and routes execution into the target site's normally bootstrapped runtime.

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

When the `multisite` package is enabled, `codi/sites`, `codi/site-abilities`, and `codi/site-call` register only on the current network's main site and are automatically treated as network-managed infrastructure exposure. They do not appear on the main site's ordinary `/wp-json/mcp/codi` server. Instead, the main site registers `/wp-json/mcp/codi-network` as a distinct network MCP resource. Its catalogue contains only abilities from packages explicitly **Enabled** by network policy plus the three gateway abilities; delegated main-site abilities remain site-local. One network connection can therefore route work to any site the authenticated user may access without making the main site's site endpoint network-capable. On each target, network-enabled packages contribute their registered abilities automatically, while delegated packages remain governed by that target site's package toggle and exposure selection. Remote discovery/execution is performed by an authenticated HTTP request to `/wp-json/codi-mcp/v1/federation` on the target site rather than by `switch_to_blog()`. The target therefore boots normally, loads its own site-active plugins, applies effective package/exposure policy locally, and executes through the target `WP_Ability`, including input/output validation and the ability's normal `permission_callback`.

Federation assertions are short-lived HMAC signatures derived from the WordPress authentication salt and bound to network ID, target site ID, originating WordPress user ID, operation, request-body hash, issue/expiry times, and a nonce. Used nonces are retained for the assertion window to reject replay. A site-bound OAuth bearer token is never reused as authority on another site. Federation requires HTTPS by default; loopback/DNS/TLS failures are surfaced as target-site availability errors rather than bypassed with manual plugin loading.

Built-in abilities do not opt into the Adapter's generic public/default-server surface. Explicit inclusion in the dedicated Codi server is the only MCP exposure authority.

## OAuth model

Codi MCP core owns the OAuth implementation: PKCE S256 authorization-code flow, bounded Dynamic Client Registration for current clients, short-lived access tokens and rotating refresh tokens, hashed credential lookup keys, redirect URI validation, login/consent, and OAuth metadata endpoints. Public DCR is rate-limited per source and globally over a one-hour window. Never-authorized clients are pruned after one hour and authorized clients after 90 days of inactivity. Site OAuth state uses non-autoloaded site options and a site-option mutex; network OAuth state uses network options and a network-option mutex. Credential buckets carry explicit expiries, so object-cache flushes cannot invalidate OAuth sessions. Authorization codes and refresh tokens are consumed before replacement credentials are returned, and credential persistence/consumption failures fail closed. The site and network resources have distinct issuers, endpoints, storage scopes, and bearer keys; a token for one cannot authenticate the other. CIMD can replace Dynamic Client Registration when full metadata-document validation is implemented.

A valid Codi MCP bearer token resolves to its bound WordPress user early in request authentication. The MCP Adapter and every ability then see the normal current WordPress user.

That keeps authorization simple and live:

- transport gate: authenticated WordPress user
- ability gate: each ability's own `permission_callback`
- multisite/site capabilities are evaluated at call time rather than treated as an OAuth-owned authorization model

OAuth scopes may still describe requested access, but they must not replace WordPress capability checks.

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

The package owns deployment-specific validation, ZIP inspection, WordPress upgrader use, activation state, self-protection, plugin-management permissions, installed-plugin metadata inspection, and plugin-specific export eligibility. Inbound artifact transfer uses the shared Core upload-capability system: `plugin-upload` creates a purpose-bound, size/hash-bound staging slot and returns a one-time HTTPS PUT URL; raw bytes are received by the generic Core `/uploads/...` endpoint and never pass through MCP. Export remains a separate bounded `ArtifactDownloadStore` workflow. Supplied ZIPs pass a shared pre-extraction central-directory guard for entry count, total uncompressed bytes, and unsafe paths before WordPress extraction. Inspection includes must-use plugins plus dependency and Update URI headers. Upload/install is also the supplied-ZIP update path, so there is no redundant `plugin-update` ability. `plugin-delete` uses WordPress native deletion, requires an exact inactive standard plugin, refuses Codi MCP itself, and is disabled on multisite because plugin files are shared across sites. Export supports directory-backed standard plugins only and refuses Codi MCP itself.

## Package: system

Location: `src/Packages/System/`

Read-only abilities: `codi/runtime-info`, `codi/rest-routes-list`, `codi/cron-list`, `codi/rewrite-rules`, `codi/site-health`, `codi/error-log-tail`, `codi/cache-info`, `codi/roles-list`, and `codi/audit-log`. `site-health` can optionally execute WordPress-native direct callbacks for async tests. `error-log-tail` is fixed to the configured `WP_DEBUG_LOG` inside `WP_CONTENT_DIR`, byte/line bounded, and structurally credential-redacted. On multisite it is explicitly network-shared and super-admin-only. `audit-log` returns the bounded site-local execution/OAuth trail; Codi abilities use WordPress's custom `ability_class` extension point to wrap the native `WP_Ability::execute()` result and record terminal success/failure without duplicating core validation or permission logic. Audit records contain identifiers, input field names, and non-sensitive error codes, never input values or credentials.

Mutating abilities are deliberately narrow: `codi/cron-run` requires an exact scheduled event identity, `codi/rewrite-flush` requires an explicit hard/soft choice, and `codi/cache-flush` requires super-admin permission on multisite. No generic callback, function, shell, filesystem or HTTP execution surface is provided.

## Package: site

Location: `src/Packages/Site/`

Read-only ability: `codi/site-config`. Mutating ability: `codi/site-config-update`.

The package owns typed site-administration state rather than visual design, theme lifecycle, or source code. Configuration is an explicit typed allowlist and never accepts an option name from the caller. Front-page/posts-page IDs are validated as usable pages; timezone/locale/permalink inputs are bounded and validated; permalink changes cause only a soft rewrite flush. Theme lifecycle belongs exclusively to the Themes package.

## Package: themes

Location: `src/Packages/Themes/`

Abilities are `codi/theme-upload`, `codi/theme-install`, `codi/theme-activate`, `codi/theme-delete`, `codi/themes-list`, `codi/theme-info`, and `codi/theme-export`.

The package owns installed-theme inspection and lifecycle policy, while raw artifact transfer remains shared Core infrastructure. `theme-upload` creates a purpose-bound, size/hash-bound staging slot and returns the same one-time HTTPS PUT capability used by other inbound uploads; Core receives and verifies the stream. Export remains a separate `ArtifactDownloadStore` workflow. `theme-install` is also the supplied-ZIP update path, and supplied ZIPs pass the shared pre-extraction entry-count/uncompressed-size/path guard before WordPress extraction. Activation uses an exact installed stylesheet and, on multisite, requires the theme already to be enabled for the current site. Deletion is unavailable on multisite and protects the active theme, active parent, and parent themes required by installed child themes. Theme export is whole-directory ZIP export only. Codi never edits theme source files in place; source changes use export, external editing/validation, and ZIP redeployment.

## Package: content

Location: `src/Packages/Content/`

Read-only structural/entity inspection is `codi/post-types-list`, `codi/taxonomies-list`, `codi/terms-list`, `codi/authors-list`, `codi/registered-blocks-list`, `codi/registered-meta-list`, `codi/posts-list`, and `codi/registered-meta-get`. Term discovery is taxonomy-capability scoped. Post listing is explicitly constrained to entities the caller may edit. `codi/registered-meta-get` reads a value only for an exact post/term key that is registered, REST-exposed, and authorized for that object/key.

Structured content mutations are `codi/term-create`, `codi/term-update`, `codi/term-delete`, `codi/post-terms-update`, `codi/registered-meta-set`, `codi/registered-meta-delete`, `codi/post-author-set`, `codi/post-attributes-update`, `codi/post-create`, `codi/post-identity-update`, `codi/post-status-update`, `codi/post-featured-media-set`, `codi/post-duplicate`, `codi/post-trash`, `codi/post-restore`, and `codi/post-delete`. Term and metadata operations retain their native capability/schema checks. Post deletion/trash uses exact delete permission, publish/private/future transitions use the post type publish capability, and duplication additionally requires the post type create capability. Content deliberately does not edit post bodies, blocks, templates, theme presentation, site settings, or source files; Presentation owns WordPress-managed frontend composition, Media owns attachments, Site owns typed site configuration, and source changes happen through plugin/theme artifact export and redeployment.

## Package: media

Location: `src/Packages/Media/`

Abilities are `codi/media-config`, `codi/media-find`, `codi/media-inspect`, `codi/media-upload`, `codi/media-create`, `codi/media-update`, and `codi/media-delete`. Media discovery and inspection respect exact attachment readability; permanent deletion requires exact delete permission. Attaching or re-parenting requires edit permission on the destination post. Local media transfer uses the same shared Core upload-capability system: `media-upload` returns a one-time HTTPS PUT URL bound to the declared filename, MIME metadata, size, and SHA-256; the resulting `upload_id` is consumed by `media-create` or `media-update`. Genuine remote HTTP(S) sources remain supported separately and are capped by the WordPress upload-size limit. Callers cannot write `post_mime_type` directly. Binary replacement snapshots the previous native file/metadata state, compensates it if a later post mutation fails, consumes a staged upload only after success, and retires old attachment files only after the replacement commits.

## Package: database

Location: `src/Packages/Database/`

The read-only abilities are `codi/db-tables`, `codi/db-schema`, and `codi/db-query`. They require `manage_options` and additionally require super-admin permission on multisite. `db-query` is not an arbitrary SQL gateway: it accepts only one `SELECT` or `EXPLAIN SELECT`; values must use WordPress `%s`, `%d`, or `%f` placeholders and every direct table source must use `%i`, all with exact parameter matching. Validation is dependency-free: Codi contains a deliberately small tokenizer/structural validator for the supported read-only subset, including nested derived SELECTs, table-source extraction, function allowlisting, wildcard-join rejection, and sensitive-projection checks. It is not a general SQL parser. Lexical checks remain defense in depth. Comments, quoted/numeric literals, multi-statements, session assignments, implicit comma joins, writes, locking clauses, unknown or dangerous functions, server schemas, protected credential fields, and tables outside the current-site/shared WordPress scope are rejected. The server enforces row and response-byte ceilings and redacts credential-like fields/values before returning results. No database-write ability is registered.

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
