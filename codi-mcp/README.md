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
- `codi/plugin-update`
- `codi/plugin-restore`
- `codi/plugins-list`
- `codi/plugin-info`
- `codi/plugin-export`

Upload/install requires `install_plugins` and `update_plugins`. `plugin-upload` creates a purpose-bound staging slot and returns a one-time HTTPS PUT capability for the declared ZIP size and SHA-256; the file bytes are streamed directly to Core's shared upload receiver and never travel through MCP. For an existing package, custom entire/folder/file replacement is allowed only when source management policy marks that package `managed`; existing external packages must use the native update path instead. File mode replaces exactly one existing regular file and refuses package identity files (plugin entry points and theme `style.css`). Its freshness check and durable recovery point are scoped to that file, so unrelated package edits or package size do not broaden or block the mutation. Codi MCP itself is `protected`: deletion plus folder/file replacement remain refused, while whole-package replacement remains available for controlled Codi upgrades. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation/deactivation requires `activate_plugins`. Deletion requires `delete_plugins`, accepts only an inactive directory-backed managed plugin, and on multisite additionally requires a super administrator and inactivity everywhere. `plugin-update` invokes only the exact update WordPress currently advertises for that plugin. `plugin-restore` restores a Codi-created code recovery point only while installed source still matches the recorded post-change fingerprint. `plugin-export` packages one directory-backed standard plugin into a bounded ZIP and returns a short-lived HTTPS download URL by default; Codi MCP cannot export itself. Plugin inspection reports source policy, allowed source operations, cached update availability, and the latest unexpired code recovery point. `plugins-list` defaults to a compact mutation-relevant summary with 20 rows; `detail=full` restores the complete list row, while `plugin-info` remains the exact full-detail lookup.

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

Inspection abilities require `manage_options`. `cache-info` reports the active object-cache drop-in name/version when WordPress can identify it, plus feature support. `diagnostic-run` separately performs a cross-request persistence probe in both a Codi-owned cache group and WordPress's core `options` group; the resulting cache evidence distinguishes a genuinely non-persistent Core cache path from a cache that persists Core options while deliberately excluding Codi's diagnostic group. Broad read lists default to 20 rows; `roles-list` omits capability names by default and returns only `capability_count` unless `include_capabilities=true`. `audit-log` defaults to 20 records, while `error-log-tail` defaults to 50 lines/16 KiB without changing its explicit maxima. `codi/upload-cancel` removes a temporary unconsumed upload slot and is purpose-bound to plugin, theme, or media staging; it requires the same native capability family as the upload it cancels and is idempotent when the slot has already expired or been consumed. Site Health executes only callbacks owned by the current WordPress `WP_Site_Health` instance by default; plugin/theme tests require explicit `include_third_party=true`. `include_async` can additionally execute available direct forms of async tests. Each Site Health callback is output-contained; unexpected direct output is suppressed and returned only as bounded test ID/byte-count/SHA-256 metadata. `error-log-tail` reads only the configured `WP_DEBUG_LOG` when it resolves inside `WP_CONTENT_DIR`, applies byte/line limits, and structurally redacts credential-like JSON plus credential-shaped text. On multisite it is explicitly network-shared and requires a super administrator. Cron argument inspection redacts sensitive keyed values and all positional string values because positional strings have no trustworthy semantic key. `codi/audit-log` returns a bounded site-local trail of Codi ability executions and OAuth registration/authorization events; it records identifiers, terminal success/failure status, non-sensitive error codes, and input field names, never input values or credentials. `cron-run` executes only an exact event returned by the cron schedule. Rewrite flushing requires an explicit soft/hard choice, and cache flushing requires a super administrator on multisite.

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
- `codi/theme-update`
- `codi/theme-restore`
- `codi/themes-list`
- `codi/theme-info`
- `codi/theme-export`

`theme-upload` uses the same shared Core upload-capability system as plugins: it returns a one-time HTTPS PUT destination bound to the declared ZIP size and SHA-256, while theme export uses the shared bounded artifact store and short-lived HTTPS downloads. There is no package-specific inbound transfer protocol. Block themes are generated with WordPress's native Site Editor exporter so saved template/template-part and Global Styles database overrides are baked into the ZIP. Codi can do this for an inactive installed theme without activating it by applying request-local stylesheet/template context only while Core generates the artifact; classic themes continue to use whole-directory export. `theme-install` accepts both conventional stylesheet-directory ZIPs and WordPress native root-layout theme exports. Existing external themes cannot be custom-replaced; custom deployment and deletion require the theme to be marked `managed`. `theme-update` invokes only the exact update WordPress currently advertises. `theme-restore` is conflict-aware code restoration from a Codi-created recovery point. `themes-list` defaults to a compact mutation-relevant summary with 20 rows; `detail=full` restores complete theme metadata, while `theme-info` remains the exact full-detail lookup. Before extraction, supplied ZIPs are preflighted for bounded entry count, total uncompressed size, and unsafe archive paths. Activation requires `switch_themes`, rejects WordPress-detected broken themes, and on multisite can activate only themes already enabled for the current site. Theme deletion requires `delete_themes`; on multisite it additionally requires a super administrator and scans every site's active stylesheet/template pair. Deletion is refused while the theme itself or any child theme using it as a parent is active anywhere.


### Direct exports

New `plugin-export` and `theme-export` calls need only the package identity. The response includes filename, byte size, SHA-256, and a five-minute HTTPS GET URL. A file-transfer client downloads the archive directly; GET and HEAD require the signed URL and return raw ZIP bytes. URLs are bound to the site, operation, staged artifact, and its integrity metadata. Treat them as temporary credentials.

The staged snapshot expires after one hour. Use its `download_id` with `transfer: "url"` (or with `transfer` omitted) to renew a URL while the snapshot remains available. Sequential base64 transfer must be selected explicitly with `transfer: "chunks"`; `offset` is valid only in chunk mode, and callers continue with `next_offset`.

### Core-backed replacement protection

Entire plugin and theme deployments continue to use WordPress's native uploaded-package installers. When the destination already exists, Codi enables Core's temporary backup through the public `upgrader_package_options` hook. Core performs the file moves, restoration, and cleanup; Codi completes them before releasing its package lock. New installations retain the ordinary Core install path. Activation state is not intentionally changed.

For plugins or themes that are active in any affected WordPress site context, Codi invokes Core's automatic-updater fatal-error probe while maintenance protection and the original backup remain in place. On multisite, shared plugin/theme source is checked against every site where that plugin, theme, or parent theme is active; network-active plugins therefore verify every current network site. Codi requires a positive successful scrape response for every required context. An unreachable, blocked, or unconfirmed probe rejects the update. A handled installation or verification failure restores the original package through `WP_Upgrader::restore_temp_backup()`. Failed restoration retains the Core backup and maintenance protection and blocks another Codi whole-package replacement until recovery is resolved.

This is a fresh-request bootstrap/fatal check of each required site's homepage, not exhaustive application testing. Inactive packages receive file-operation rollback protection but are not executed by the probe. Folder mode uses the same runtime-context policy: after promotion and OPcache invalidation, but before commit, the same verifier must succeed or the existing folder transaction restores the previous directory/absence. There is no separate PHP CLI linter, background recovery service, database rollback, or guarantee of recovery after the deployment process is killed. Backups use Core's `wp-content/upgrade-temp-backup/plugins|themes/<slug>` location and remain subject to Core's cleanup behaviour.

### Source management and durable code recovery

Codi distinguishes source it may rewrite from source it may only inspect/update through WordPress. Source policy is deliberately small: `managed`, `external`, and the intrinsic `protected` state for Codi MCP. On multisite the managed allowlist is network-admin controlled because plugin/theme files are shared; on single-site it is controlled from the ordinary Codi MCP settings page. Packages default to `external`, and the model cannot change this policy through an MCP ability. A newly installed custom package is not automatically promoted to managed status; a human must explicitly allow future custom replacement/deletion if desired.

Model-facing MCP instructions treat source policy as mutation authority, not ownership inference. Diagnostics may inspect any component, but remediation should compare repeated comparable captures, attribute component/caller before changing code, and modify source only where Codi policy permits it. An external/core component should not be patched, updated, or deactivated solely because diagnostics implicate it; prefer supported configuration or public hooks/APIs, and use a native update only when independently justified. Otherwise report the finding and recommend the next action.

### `codi-mcp-custom` companion convention

`codi-mcp-custom` is the designated companion plugin for small installation-specific adaptations that Codi creates or maintains when the correct remediation belongs outside Codi MCP itself and outside the source owner being adapted. Prefer it for narrowly scoped hooks, filters, compatibility shims, multisite/runtime policy, and evidence-backed optimisations affecting WordPress core, third-party plugins, or themes. Examples include suppressing redundant plugin behaviour because equivalent server infrastructure is authoritative, or applying a supported public filter to avoid a measured performance cost.

Do not patch WordPress core, vendor plugin/theme source, or unrelated application plugins when the same result can be achieved through a supported hook/API in `codi-mcp-custom`. Do not put business/domain functionality, substantial standalone features, vendor forks, or changes that properly belong upstream into this companion plugin. If an intervention grows into an independently meaningful subsystem, move it to its own plugin.

`codi-mcp-custom` is intrinsically `managed` by Codi source policy and is not part of the human-managed allowlist; this exception exists specifically for the designated companion plugin. Codi MCP itself remains intrinsically `protected`. On multisite, `codi-mcp-custom` is a network-only plugin (`Network: true`) and is network-activated once for the installation; modules then decide their own applicability per current site. Network-wide modules apply everywhere, while site-specific modules explicitly target one or more WordPress site IDs using the current blog/site context rather than relying on theme identity or domain strings. Each intervention should be isolated, document why it exists and what evidence/configuration justifies it, and be removable when the underlying condition no longer applies.

Modules must also fail inertly when their target is unavailable. A disabled, removed, or incompatible plugin/theme must never make `codi-mcp-custom` fatal or break unrelated sites on the network. Do not reference third-party classes, functions, constants, or files unconditionally at plugin load time. Register adaptations at an appropriate lifecycle hook, verify the required target capability/API exists before using it, and no-op otherwise. Where behavior depends on a particular site, theme, plugin version, or network/site activation state, make that applicability check explicit. Site targeting should use WordPress site/blog IDs (`get_current_blog_id()` or the equivalent current-site context) and should evaluate that context at the point the module acts so `switch_to_blog()` remains correct. Prefer public hooks and capability checks over coupling to implementation classes; when version-sensitive behavior is unavoidable, bound it to known-compatible versions and fail closed outside them.

Before Codi changes existing package source through custom replacement, managed deletion, or a WordPress-advertised native update, it first copies the package into private staging and requires the staged tree and the still-installed tree to match the captured before fingerprint. Only that verified staging copy is archived, so recovery creation cannot silently archive a mixed tree if source files change while the snapshot is being taken. Empty source directories are preserved in native ZIP artifacts. Custom uploads also bind the whole-package fingerprint when the upload is created and refuse installation if any package source changed before the deployment lock is acquired. Successful mutations record the resulting after fingerprint. Pending snapshots never evict committed recovery points; at most three usable committed recovery points are retained per package.

`plugin-restore` and `theme-restore` are deliberately conflict-aware: restoration is refused if current source no longer equals the recorded after fingerprint. A successfully restored recovery point is consumed and no longer advertised or reusable. Inspection also omits a recovery point when its artifact is no longer present. Recovery artifacts are intended to live for seven days, but their bytes are stored under WordPress/PHP temporary storage; that storage must itself be persistent for the requested retention period. Recovery restores package **code only**. It does not restore options, custom tables, posts, scheduled jobs, messages, remote side effects, or database migrations performed by changed PHP. A code recovery point therefore must not be described as a full site/database backup. Codi does not expose generic filesystem backup, database dump/restore, or a model-controlled source-policy change ability.

### Entire package or one folder

Plugin and theme upload calls accept optional `replace` and `folder` fields:

- `replace: "entire"` is the default. It retains the native WordPress whole-package install/update path.
- `replace: "folder"` adds or replaces one exact relative directory, for example `folder: "src/Contacts"`. The installed package and the parent directory (`src` in this example) must already exist; the selected leaf folder may be missing. Supplying a folder without explicitly selecting folder mode is rejected.

For example, call `codi/plugin-upload` with the usual `plugin_slug`, `size`, and `sha256`, plus `replace: "folder"` and `folder: "src/Contacts"`. Stream the ZIP to the returned HTTPS PUT URL, then call `codi/plugin-install` with only `upload_id`. For a theme, use the same fields with `theme-upload`/`theme-install` and the existing `stylesheet` identity.

Folder-mode ZIPs must contain a top-level directory matching the plugin slug or theme stylesheet. A complete ZIP or a reduced ZIP containing only the selected directory is accepted; all entries are inspected, but only the selected subtree is extracted and installed. Root-level native theme exports remain supported in entire mode. An explicitly empty selected directory clears its contents; a missing selected source directory is an error.

The installed package, selection, and destination's original existence/content state are bound to the upload. Installation refuses an intervening change, including a missing destination appearing before installation. An addition does not overwrite an existing file or symlink. Edits outside the selected directory are preserved, even if the ZIP contains different versions of those files. Files omitted inside the selected directory are removed. Package root files, version headers, activation state, and theme database customizations are unchanged. Folder mode rejects symlinks, ambiguous archive paths, installed plugin entry points inside the selection, and Codi MCP self-replacement.

Folder and file modes require PHP ZipArchive, direct WordPress filesystem access, and private temporary staging outside the WordPress/document roots on the target filesystem. Folder inspection is bounded to 5000 entries and 100 MiB per selected tree. File ZIPs may contain only the selected wrapped file plus directory entries; the target must already exist with exact case and may not be a symlink. File replacement/restore fingerprints only that selected file; entire and folder modes retain package-level freshness checks. Codi coordinates entire installs, folder/file replacements, and deletions through a target-specific filesystem lock. Other deployment systems and manual filesystem edits do not participate in this lock.

After staging and rechecking the fingerprint, Codi briefly enables maintenance protection, backs up an existing directory when present, installs and verifies the staged folder, and invalidates affected OPcache paths. Handled failures restore the original directory or undo a new addition. Replacement uses two renames and addition uses one; neither is a zero-downtime atomic exchange; in-flight requests are not drained by maintenance mode.

A killed process or failed restoration can require operator recovery. An unfinished journal blocks further Codi deployments of that target. Recovery data is held separately from expiring upload slots under `<temporary-directory>/codi-mcp/deployments/transactions/<target-key>/<upload-id>/`; the error also identifies this directory. Keep its `journal.json` and any `original/` backup until the destination is restored or explicitly verified. The journal's `exists` field distinguishes replacement from addition; an interrupted addition has no original backup and recovery must restore absence or explicitly verify and retain the new folder. With other deployments stopped, use the journal's root/folder to restore the original directory (moving any incomplete installed replacement aside), invalidate affected OPcache, then remove the resolved transaction directory and only Codi's owned maintenance file. Do not clear a maintenance file owned by another deployment. Maintenance protection follows WordPress's normal expiry behavior, so an interrupted deployment still needs prompt operator attention.

### Content structures

`src/Packages/Content/` owns registered content structures and structured content relationships/attributes, while Presentation owns visual/block composition:

- `codi/post-types-list`
- `codi/taxonomies-list`
- `codi/terms-list`
- `codi/authors-list`
- `codi/registered-blocks-list`

`registered-blocks-list` defaults to compact block identity/rendering/source rows with 20 results. `detail=schema` explicitly adds block attributes, supports, parent/ancestor constraints, and script/style handles.
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
- `codi/diagnostic-compare`

`diagnostic-run` creates a short-lived one-time authorization, performs one same-origin `GET` through WordPress's HTTP API, and records request-local evidence after Codi Diagnostics initializes. Optional `admin`, `frontend`, `rest`, and `cron` profiles add bounded generic hook presets; explicit hooks extend the selected profile within the existing 20-hook limit. Captures retain the normalized diagnostic configuration and a stable fingerprint so comparisons cannot silently mix different instrumentation. By default the target executes as the authorized MCP WordPress user; optional `actor_user_id` can instead select an existing current-site user or `0` for a genuinely anonymous request, while the authorizing MCP user remains separately bound to the one-time credential. The compact run result reports the effective user ID used by the target request. `diagnostic-arm` instead creates a short-lived one-use credential bound to one exact same-origin target and one of `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`. A request that presents that credential in `X-Codi-Diagnostic-Token` is observed under its own normal WordPress identity; the credential enables Diagnostics only and does not authenticate or authorize the application request. The target consumes the credential early in plugin bootstrap and establishes only that effective request actor through core's current-user API. For admin requests, where core `auth_redirect()` independently validates native auth cookies, Diagnostics creates a short-lived request-local WordPress session and injects native auth cookies only into that in-process request; no auth cookie crosses the network. The request therefore exercises normal WordPress/admin routing without depending on an interactive login URL. It uses WordPress hooks and runtime objects rather than replacing the database, cache, HTTP, mail, routing, or rendering layers. Captures include request/status/timing context; PHP errors, `WP_Error`, `wp_die()` and `_doing_it_wrong()` events; nonce/referer verification failures; `SAVEQUERIES` database timing with redacted query shapes and source/component attribution; semantic option/meta/post/term/transient mutations without values; outbound HTTP timing; redirects; cache hit/miss deltas when the active cache exposes counters; admin/REST/rewrite/template/block context; enqueued asset dependencies; mail outcomes and preemption; multisite switches; and a bounded timeline. Repeated query patterns also report request-local binding cardinality (`distinct_bindings` and `max_binding_repetitions`) derived from keyed in-memory fingerprints; no binding values or stable cross-request hashes are persisted. Diagnostics also reports `duplicate_executions` for repeated bindings, records bounded option-access names through WordPress's global `pre_option` hook, and derives bounded database/HTTP opportunities plus autoload metadata. External object-cache write failures, request-local read unavailability, and cross-request misses are promoted separately as first-class cache opportunities without treating a miss alone as proof of backend persistence failure. Cache candidates require repeated identical bindings with material request cost; batch candidates require repeated query shapes with mostly distinct bindings and material aggregate cost; index candidates require an opt-in `EXPLAIN` material full scan with no selected or possible index; autoload candidates combine option byte size with lack of observed access in that capture; HTTP candidates require slow, repeated, or failed outbound requests. These are evidence for repeated-capture review, not automatic remediation. Named hooks report complete callback cardinality/truncation metadata plus bounded source-level query/query-time/HTTP/state costs alongside the existing whole-hook timing. Optional `callback_timing=true` adds bounded per-callback instrumented wall timing on the selected hooks only: at most 250 callbacks are instrumented independently on each selected hook, and only the 100 slowest timing rows per hook are retained. The existing 20-hook selection ceiling provides the request-wide bound without starving later lifecycle hooks. Codi preserves WordPress callback keys, priorities, and accepted-argument counts, conditionally restores only wrappers it still owns, skips callbacks with reference-sensitive or unreflectable signatures, reports nested profiled-child/exclusive-profiled timing, and marks coverage incomplete if a callback is skipped, the hook registry changes, or the profiling limit is reached. A request-local source-attribution context keeps SQL/HTTP/error evidence attributed to the original application callback even though PHP reports the profiler wrapper as the callsite. `overview.diagnostic.overhead.collectors` reports measured self-time for instrumented Codi collector paths, while `overview.diagnostic.overhead.callback_profiler` separately reports callback-profiler setup, restoration, and invocation bookkeeping. `capture_build_ms` remains post-target capture construction. These figures are intentionally separate and are not presented as a mathematically complete estimate of an uninstrumented request. Capability checks remain opt-in by explicit capability name.

`diagnostic-inspect` defaults to compact aggregates. `detail=full` returns the complete bounded section and `detail=items` exposes paginated collections with bounded filtering/sorting (`component`, `hook`, `query_type`, `minimum_ms`, `page`, `per_page`, `sort`). Database item collections include patterns, slowest queries, EXPLAIN results, components, callers, query types, callback-attributed query costs, autoload evidence, and opportunities; HTTP inspection can paginate raw requests or HTTP opportunities. Hook inspection can paginate hooks, source-cost rows, or `callback_timings`; callback timing rows can be filtered by component/hook/minimum wall time and sorted by wall/max/exclusive-profiled time. `include_callbacks=false` omits raw callback descriptors while retaining callback counts and truncation state. The `overview` section derives top database components/query patterns/slow queries plus top hooks, hook sources, measured hook callbacks, and the highest-priority deterministic correlations from the stored capture, so common investigations do not require fetching the largest sections first. A separate `correlations` section contains at most 20 rule-based findings with stable rule IDs, confidence/severity, impact, and structured evidence references. Correlation rules currently cover active-callback-context HTTP overlap, active-callback-context SQL cost, measured callback dominance within a hook, and external object-cache read-path/cross-request issues coinciding with repeated option/site-meta reads; the summaries explicitly distinguish correlation from proof of causation. `diagnostic-compare` accepts 2-10 unexpired captures only when target, method, request type, effective user, site, and diagnostic configuration fingerprint match. Its default summary returns min/median/max request metrics, recurring evidence, and material one-capture HTTP/opportunity evidence under `intermittent`; `detail=full` adds recurring query patterns and the wider evidence lists.

Both run and arm can opt into `explain_slow_queries`. Diagnostics freezes target request duration before capture construction, then selects at most three captured `SELECT` queries taking at least 5 ms and executes ordinary `EXPLAIN` while the original SQL is still request-local. The overview reports `capture_build_ms` separately so EXPLAIN and other finalization work do not inflate target duration. The EXPLAIN operations are marked diagnostic-internal and excluded from target totals; captures persist only the sanitized query shape, source attribution, bounded plan metadata, and a generic failure marker when a plan cannot be obtained.

Diagnostic URLs are restricted to the current WordPress origin. `diagnostic-run` disables redirects; armed credentials are exact target/method bound and one-use. Request bodies and request-header values are not persisted. Run credentials bind the authorizing user and selected effective actor; arm credentials bind the authorizing Codi caller but deliberately preserve the consuming request's own WordPress identity. All diagnostic credentials fail closed if they cannot be consumed. Because WordPress/plugin code can perform side effects from a `GET`, `diagnostic-run` is conservatively annotated destructive even though its intended use is observation. The stored target binding is a hash rather than the raw query string. URL query values, credential-bearing URL path values, SQL literals, query-binding fingerprints, authentication/session tokens, nonce values, credentials, mail content/recipients, and mutation values are not returned. An explicit `SAVEQUERIES=false` is respected. When `SAVEQUERIES` was already enabled before Diagnostics initializes, Codi also includes the existing `$wpdb->queries` prefix as `pre_diagnostics`, with sanitized query shape, timing, and WordPress caller summary but without pretending it has post-registration component attribution or binding fingerprints. Queries caused solely by Codi's credential consumption/request-local authentication setup are excluded and counted separately. When Codi itself must enable `SAVEQUERIES`, earlier queries cannot be recovered. A normal `diagnostic-run` also performs bounded object-cache probes using short-lived random keys in a Codi-owned group and WordPress's core `options` group. The parent request writes cross-request probes; the target checks them and separately verifies whether a fresh set/get is readable in that request context. This distinguishes request-local read unavailability (including intentional admin/cache bypass policies) from a cross-request miss without claiming the miss proves backend persistence loss. The target then deletes the keys while the caller performs cleanup again after the request. This does not enumerate or inspect application cache keys. The cache section reports hit/miss instrumentation as `counters_available`, independently of whether an external object cache is active. Captures expire after five minutes. Diagnostics requires `manage_options` and additionally a super administrator on multisite.

### Jobs

`src/Packages/Jobs/` provides semantic **read-only** background-job inspection:

- `codi/jobs-list`
- `codi/job-inspect`

The two read-only abilities are always registered, avoiding provider load-order dependence in WordPress's lazy Abilities registry. Execution fails closed when Action Scheduler is unavailable or until it has completed its normal `action_scheduler_init` lifecycle. These two read-only abilities use Codi-managed exposure when the package is enabled, so multisite deployments added before the Jobs package do not need a manual exposure migration; Jobs still requires `manage_options` and additionally a super administrator on multisite. Reads use Action Scheduler's active store/logger abstractions rather than querying `actionscheduler_*` tables directly, so custom, legacy, and hybrid stores remain authoritative. Listing exposes job identity, hook/group, status, schedule, priority, and claim state but not arguments. Exact inspection adds bounded arguments with all string values redacted, registered callback/source attribution, and bounded credential-redacted Action Scheduler logs. Codi currently exposes no job mutation, creation, arbitrary hook scheduling, queue-wide run, deletion, or generic callback execution ability.

### Database

`src/Packages/Database/` provides read-only database inspection plus two deliberately narrow optimization mutations:

- `codi/db-tables`
- `codi/db-schema`
- `codi/db-query`
- `codi/db-index`
- `codi/options-autoload`
- `codi/options-autoload-set`

Database abilities require `manage_options` and additionally require a super administrator on multisite. Read-heavy database discovery defaults are intentionally compact: table/autoload pages default to 20 rows and `codi/db-query` defaults to 50 rows while retaining their existing explicit maxima. `codi/db-query` remains strictly read-only: it accepts only one `SELECT` or `EXPLAIN SELECT`, requires parameterized values and table identifiers, rejects writes/locks/system schemas/protected credential fields, and applies hard row/response-size limits plus credential-like response redaction. `codi/db-index` is not a generic DDL gateway: it can add or remove only a named non-unique index on a current-site non-Core table, supports up to eight ordered columns, refuses primary/unique constraint changes, rejects equivalent duplicate indexes, and requires the expected ordered columns when removing an index. `codi/options-autoload` returns option names, serialized byte sizes, and autoload state without returning values. `codi/options-autoload-set` changes only existing current-site option autoload state through WordPress's native bulk autoload API, rejects protected options/transients, verifies the resulting states, and compensates partial bulk failure back to the recorded prior states. Neither optimization ability exposes arbitrary SQL or arbitrary option values.

### Presentation

`src/Packages/Presentation/` owns WordPress-managed frontend composition: post content, block templates and parts, patterns, navigation, Query Loops, native user-origin Global Styles, and template assignment. It registers exactly eight abilities: `codi/presentation-help`, `codi/presentation-find`, `codi/presentation-inspect`, `codi/presentation-preview`, `codi/presentation-verify`, `codi/presentation-commit`, `codi/presentation-history`, and `codi/presentation-rollback`. Mutations follow `find → inspect → preview → verify → commit → history → rollback`; preview is non-writing, commit is stale-checked against native WordPress state, rollback refuses diverged targets, and history index mutations are serialized so concurrent commits cannot silently lose transaction references. The MCP-advertised preview schema is intentionally compact and requires only each operation `action`; native WordPress ability validation still uses the full strict per-operation schema. `presentation-help` returns a compact operation catalogue by default and returns the exact native operation input schema when `action` is supplied. `presentation-inspect` defaults to block/dependency summaries; `detail=raw` returns serialized state and `detail=full` returns both representations. Gutenberg mutation is semantic and fail-closed: supported static blocks use explicit save codecs, unknown blocks remain read-only except for intact structural preservation, and there is no generic attribute/CSS setter. Presentation never edits theme/plugin source; source changes use whole-artifact export, external validation, and ZIP redeployment through Plugins or Themes.

## MCP and ability exposure

Codi MCP registers a dedicated Codi server through the Adapter's public `create_server()` API without changing the Adapter's own default-server setting. The Adapter still owns HTTP transport, protocol handling, sessions/version negotiation, errors, and conversion of WordPress Abilities into MCP components; other plugins remain free to use the Adapter's default server independently.

Codi keeps one canonical ability catalogue over the live WordPress Abilities registry. Catalogue entries are classified as **Codi-owned** or **external**. Codi-owned abilities are ordinary registered WordPress abilities that explicitly opt out of generic client channels (`meta.public=false`, `meta.mcp.public=false`, and `show_in_rest=false`); they remain part of the standard registry while Codi independently governs whether they appear on its dedicated MCP server. External abilities are selectable only when their author has declared effective MCP public intent: explicit `meta.mcp.public` takes precedence, otherwise `meta.public` is used. Codi never overrides a private external ability's exposure intent.

The Codi MCP admin screen lists only abilities eligible for Codi governance in that site's real bootstrap context and labels their origin. On multisite, abilities belonging to packages set to **Enabled** in Network Admin are network-managed and automatically exposed wherever those packages register; they are not editable per site. Abilities belonging to delegated packages and eligible external abilities remain default-deny and must be explicitly selected by a site administrator. Every underlying ability permission callback still runs on each invocation.

Codi's catalogue/governance layer is separate from its MCP presentation layer. The site presentation exposes selected abilities directly as MCP tools/resources/prompts. Codi uses the Adapter's public tools-list filter to omit advertised output schemas on its own servers, remove empty input-schema properties maps, and compact Codi-owned model-facing input schemas without changing native validation. The advertised copy omits regex and string-length validators (`pattern`, `minLength`, `maxLength`) because WordPress still enforces them natively while field types, required fields, enums/defaults, numeric/array bounds, and semantic descriptions remain available to the model. Individual Codi abilities may additionally provide a smaller model-only input schema, as Presentation preview does; native WordPress input/output validation and risk annotations remain intact. This separation allows a future layered or hybrid discovery presentation without changing package implementations, exposure policy, or the canonical catalogue. The Adapter's generic discovery/get-info/execute gateway is not included in Codi's dedicated server.

Codi-owned abilities declare `readonly`, `destructive`, `idempotent`, and `openWorldHint` centrally. Codi-owned execution keeps the Codi audit wrapper; external abilities are audited at the Codi MCP/federation execution boundary without replacing the originating ability implementation or duplicating Codi-owned audit records.

## OAuth

Codi MCP uses an OAuth 2.1-style authorization-code flow with PKCE S256, RFC 9728 protected-resource metadata, RFC 8414 authorization-server metadata, RFC 8707 resource indicators, RFC 9207 `iss` responses, short-lived bearer access tokens, rotating refresh tokens, and RFC 7009-style token revocation. The dedicated HTTP MCP resources are Bearer-only: a normal WordPress login/session is used for the human authorization/consent flow, but cannot substitute for a valid Codi OAuth bearer on `/wp-json/mcp/codi` or `/wp-json/mcp/codi-network`.

Every WordPress site, including the multisite main site, has a permanently site-local `/wp-json/mcp/codi` resource. Site OAuth state is stored in non-autoloaded site options with explicit credential expiries, and bearer records are bound to `site:<site-id>|mcp/codi`, so neither a different site nor the network gateway can accept the token. Access-token validation also requires the issuing client to still exist and rejects any grant marked revoked. OAuth credentials do not use WordPress transients and therefore survive object-cache flushes.

Dynamic Client Registration is supported for current clients, including ChatGPT. Registration metadata is bounded, public registration is limited per source and globally over a one-hour window, never-authorized clients are pruned after one hour, and authorized clients that remain idle for 90 days are pruned. Site registration and credentials use site-option mutexes; network registration and credentials use network-option mutexes. MCP 2026-07-28 prefers Client ID Metadata Documents (CIMD); Codi does not advertise CIMD until it implements full remote client-metadata validation.

`offline_access` is an optional authorization-server scope a client may use to explicitly request refresh-token capability. It is not advertised as an MCP resource scope. Codi may issue a refresh token whenever the registered OAuth client supports the `refresh_token` grant, whether or not the client also requests `offline_access`; refresh-token exchanges rotate the refresh token. Newly issued refresh tokens carry an integrity-protected grant identifier derived under the WordPress authentication salt. Reuse of an invalidated refresh token identifies and revokes the active grant family, including outstanding access and refresh credentials. Rotation consumes the old credential before replacement credentials are returned, and persistence/consumption failures fail closed rather than returning credentials whose state was not durably recorded.

Authorization-server metadata advertises `/oauth/revoke`. Revoking an access or refresh token invalidates the associated grant where Codi has grant-family state. **Settings → Codi MCP** lists registered site OAuth clients with a Disconnect action that invalidates that client's authorization codes, access tokens, and refresh tokens immediately; Network Admin provides the equivalent control for the network MCP resource. Web OAuth clients require HTTPS redirect URIs. HTTP loopback redirects are accepted only for clients registered as `application_type=native`, and the consent page shows the callback origin alongside the requested resource.

## Multisite

Codi MCP keeps site OAuth clients/tokens and delegated ability exposure state per site. On multisite, **Network Admin → Codi MCP** controls bundled packages as enabled, disabled, or delegated. **Enabled** forces the package on and automatically exposes all abilities it registers on each site; **Disabled** removes it; **Delegate to site** leaves package availability and ability exposure to that site's Codi MCP page. The optional `multisite` package is network infrastructure and remains disabled by default.

When `multisite` is enabled on the network, the main site additionally serves `/wp-json/mcp/codi-network` as a distinct network MCP resource with its own OAuth issuer under `/wp-json/codi-mcp/v1/network`. Network OAuth clients, authorization codes, access tokens, refresh tokens, registration throttling, and mutexes are stored in network options and bearer records are bound to `network:<network-id>|mcp/codi-network`. The network catalogue exposes only the multisite gateway abilities: `sites` discovers explicit target IDs, `site-abilities` discovers the target's exposed ability names/descriptions/input schemas and safety annotations when needed, and `site-call` executes a known ability. `site-abilities` accepts optional `search` and `detail` fields. Exact full names, then unique short names, take precedence; otherwise every whitespace-separated term must occur in the name or description. `detail: "auto"` returns input schemas for a single match and summaries for multiple matches; `summary` and `schema` force either view. Retrieve the exact ability schema before constructing arguments, and reuse discovered descriptors. Site-scoped package abilities are never executed implicitly against the main site. Network gateway responses avoid echoing caller-supplied site/ability identifiers and omit target output schemas and other redundant catalogue metadata to keep model context compact. `site-call` treats target execution as a delegated operation: a target `WP_Error` is returned as structured `error` data alongside `result: null`, preserving its code, message, and supplied HTTP status without turning the outer MCP gateway call into a generic protocol error. Packages explicitly **Enabled** by network policy remain automatically available and exposed in each site's own runtime. Delegated main-site abilities remain available only through the main site's ordinary `/wp-json/mcp/codi` resource.

## Tests

The standalone `php -d extension=zip tests/integration/native-upgrader.php <disposable-wordpress-tree> --disposable` harness exercises real Core installation, backup, restoration, and fatal-probe classes without a database. It uses controlled HTTP scrape responses and deliberately writes fixtures into the supplied disposable tree; never point it at a working site.

Run the fast isolated invariant suite with `php tests/run.php`; run the complete release gate, including real filesystem deployment invariants, with `php tests/run.php --full`. Test files remain process-isolated and run with bounded parallelism (eight workers by default); set `CODI_MCP_TEST_WORKERS` to override that count. TestCase owns one canonical state reset before each test. Filesystem-backed theme fixtures are restored only for classes that declare they use them. Folder/file replacement invariants share one abstract fixture but are split into disjoint core, recovery, addition-recovery, integration, and file-replacement shards so the existing worker pool can run them concurrently. The fixture verifies that every inherited deployment invariant belongs to exactly one shard. Transaction scenarios exercise real fingerprints, renames, journals, recovery state, locks, maintenance files, and process interruption; archive-focused invariants use native ZIP paths when ZipArchive is available. The WordPress integration tests use the standard WordPress PHPUnit test library. Set `WP_TESTS_DIR` to that library and run `tests/integration/AuditOAuthIntegrationTest.php` with `tests/integration/bootstrap.php` as the PHPUnit bootstrap. Run `tests/integration/PluginActivationIntegrationTest.php` against a multisite-configured WordPress test environment. These tests exercise real WordPress ability execution, audit persistence, OAuth option storage, and the native activation invariant that a failed promotion from site activation to network activation preserves the original site activation.
