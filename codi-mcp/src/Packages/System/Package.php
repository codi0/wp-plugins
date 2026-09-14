<?php

declare(strict_types=1);

namespace CodiMcp\Packages\System;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\Uploads\UploadCapabilityService;

final class Package implements AbilityPackage
{
    private ?SystemInspector $inspector = null;
    private ?UploadCapabilityService $uploads = null;

    public function key(): string
    {
        return 'system';
    }

    public function label(): string
    {
        return 'System';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('runtime-info'),
            $this->abilityName('rest-routes-list'),
            $this->abilityName('cron-list'),
            $this->abilityName('rewrite-rules'),
            $this->abilityName('site-health'),
            $this->abilityName('error-log-tail'),
            $this->abilityName('cache-info'),
            $this->abilityName('roles-list'),
            $this->abilityName('audit-log'),
            $this->abilityName('upload-cancel'),
            $this->abilityName('cron-run'),
            $this->abilityName('rewrite-flush'),
            $this->abilityName('cache-flush'),
        );
    }

    public function registerCategories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — System',
            'description' => 'WordPress runtime, REST, cron, rewrite, Site Health, error-log, cache, role, and narrowly bounded operational abilities.',
        ));
    }

    public function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $this->registerReadOnly(
            'runtime-info',
            'Inspect site runtime',
            'Return development-safe WordPress, PHP, database, multisite, debug, memory, upload, permalink, cache, and drop-in runtime information. Secrets and wp-config credentials are never returned.',
            $this->emptyInputSchema(),
            $this->runtimeOutputSchema(),
            [$this, 'runtimeInfo']
        );

        $this->registerReadOnly(
            'rest-routes-list',
            'List REST API routes',
            'List registered WordPress REST routes with namespace, supported HTTP methods, endpoint count, and permission-callback coverage.',
            $this->pagedInputSchema(array(
                'namespace' => array('type' => 'string', 'maxLength' => 100),
                'search' => array('type' => 'string', 'maxLength' => 200),
            )),
            $this->pagedOutputSchema($this->restRouteSchema()),
            [$this, 'restRoutesList']
        );

        $this->registerReadOnly(
            'cron-list',
            'List scheduled cron events',
            'List scheduled WordPress cron events with next run, recurrence, signature, event counts, and optionally bounded secret-redacted arguments.',
            $this->pagedInputSchema(array(
                'hook' => array('type' => 'string', 'maxLength' => 191),
                'search' => array('type' => 'string', 'maxLength' => 200),
                'include_args' => array('type' => 'boolean'),
            )),
            $this->pagedOutputSchema($this->cronSchema()),
            [$this, 'cronList']
        );

        $this->registerReadOnly(
            'rewrite-rules',
            'Inspect rewrite rules',
            'List stored WordPress rewrite rules with bounded pagination and optional pattern/query search. This does not regenerate or flush rewrite rules.',
            $this->pagedInputSchema(array(
                'search' => array('type' => 'string', 'maxLength' => 200),
            )),
            $this->pagedOutputSchema($this->rewriteSchema()),
            [$this, 'rewriteRules']
        );

        $this->registerReadOnly(
            'site-health',
            'Run Site Health checks',
            'Run WordPress native Site Health tests and return structured critical, recommended, good, and informational results. When include_async=true, async tests that expose WordPress native direct callbacks are executed without scraping wp-admin or introducing a generic HTTP proxy.',
            array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'search' => array('type' => 'string', 'maxLength' => 100),
                    'include_good' => array('type' => 'boolean'),
                    'include_async' => array('type' => 'boolean'),
                ),
            ),
            $this->siteHealthOutputSchema(),
            [$this, 'siteHealth']
        );

        $this->registerReadOnly(
            'error-log-tail',
            'Read recent WordPress error log',
            'Read a bounded tail of the configured WP_DEBUG_LOG. On multisite the log is network-shared and requires a super administrator. Only a WP_DEBUG_LOG file inside WP_CONTENT_DIR is eligible; caller-selected paths are not accepted, and credential-like values are redacted.',
            array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'lines' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200),
                    'max_bytes' => array('type' => 'integer', 'minimum' => 1024, 'maximum' => 65536),
                ),
            ),
            $this->errorLogOutputSchema(),
            [$this, 'errorLogTail'],
            [$this, 'canReadErrorLog']
        );

        $this->registerReadOnly(
            'cache-info',
            'Inspect object cache',
            'Inspect WordPress object-cache state, drop-in presence, implementation class, and supported cache operations without exposing cache credentials.',
            $this->emptyInputSchema(),
            $this->cacheInfoOutputSchema(),
            [$this, 'cacheInfo']
        );

        $this->registerReadOnly(
            'roles-list',
            'List roles and capabilities',
            'List registered WordPress roles and granted capabilities without returning any user records.',
            $this->pagedInputSchema(array('search' => array('type' => 'string', 'maxLength' => 100))),
            $this->pagedOutputSchema($this->roleSchema()),
            [$this, 'rolesList']
        );

        $this->registerReadOnly(
            'audit-log',
            'Read Codi MCP audit log',
            'Return the bounded site-local audit trail for Codi MCP ability execution and OAuth authorization events. Audit records contain identifiers and input field names, never input values or credentials.',
            array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200)),
            ),
            $this->auditLogOutputSchema(),
            [$this, 'auditLog']
        );

        $this->registerMutation(
            'upload-cancel',
            'Cancel staged upload',
            'Cancel and delete one temporary Codi upload slot before it is consumed. The purpose must match the staged upload, and permission is checked against the owning plugin, theme, or media operation.',
            array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'upload_id' => array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'),
                    'purpose' => array('type' => 'string', 'enum' => array('plugins.install', 'themes.install', 'media.library')),
                ),
                'required' => array('upload_id', 'purpose'),
            ),
            $this->strictObject(array(
                'upload_id' => array('type' => 'string'),
                'purpose' => array('type' => 'string'),
                'canceled' => array('type' => 'boolean'),
            )),
            [$this, 'uploadCancel'],
            [$this, 'canCancelUpload'],
            true
        );

        $this->registerMutation(
            'cron-run',
            'Run selected cron event',
            'Execute exactly one scheduled WordPress cron event identified by the hook, timestamp, and signature returned by codi/cron-list. The scheduled event itself is preserved.',
            $this->cronRunInputSchema(),
            $this->cronRunOutputSchema(),
            [$this, 'cronRun'],
            [$this, 'canMutate'],
            false
        );

        $this->registerMutation(
            'rewrite-flush',
            'Flush rewrite rules',
            'Regenerate WordPress rewrite rules. hard=false performs a soft flush; hard=true may also update .htaccess and should be used deliberately.',
            array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array('hard' => array('type' => 'boolean')),
                'required' => array('hard'),
            ),
            $this->strictObject(array('hard' => array('type' => 'boolean'), 'rule_count' => array('type' => 'integer'))),
            [$this, 'rewriteFlush'],
            [$this, 'canMutate'],
            true
        );

        $this->registerMutation(
            'cache-flush',
            'Flush object cache',
            'Flush the WordPress object cache. On multisite this requires a super administrator because persistent cache implementations may be shared across sites.',
            $this->emptyInputSchema(),
            $this->strictObject(array('success' => array('type' => 'boolean'), 'external_object_cache' => array('type' => 'boolean'))),
            [$this, 'cacheFlush'],
            [$this, 'canFlushCache'],
            true
        );
    }

    public function runtimeInfo($input = array())
    {
        return $this->inspector()->runtimeInfo(is_array($input) ? $input : array());
    }

    public function restRoutesList($input = array())
    {
        return $this->inspector()->restRoutesList(is_array($input) ? $input : array());
    }

    public function cronList($input = array())
    {
        return $this->inspector()->cronList(is_array($input) ? $input : array());
    }

    public function rewriteRules($input = array())
    {
        return $this->inspector()->rewriteRules(is_array($input) ? $input : array());
    }

    public function siteHealth($input = array())
    {
        return $this->inspector()->siteHealth(is_array($input) ? $input : array());
    }

    public function errorLogTail($input = array())
    {
        return $this->inspector()->errorLogTail(is_array($input) ? $input : array());
    }

    public function cacheInfo($input = array())
    {
        return $this->inspector()->cacheInfo(is_array($input) ? $input : array());
    }

    public function rolesList($input = array())
    {
        return $this->inspector()->rolesList(is_array($input) ? $input : array());
    }

    public function auditLog($input = array())
    {
        return $this->inspector()->auditLog(is_array($input) ? $input : array());
    }

    public function uploadCancel($input = array())
    {
        $input = is_array($input) ? $input : array();
        $uploadId = strtolower(trim((string) ($input['upload_id'] ?? '')));
        $purpose = strtolower(trim((string) ($input['purpose'] ?? '')));
        if (!$this->isCancelableUploadPurpose($purpose)) {
            return new \WP_Error('codi_mcp_bad_upload_purpose', 'Upload purpose is not cancelable.');
        }

        $result = $this->uploadService()->discard($uploadId, $purpose);
        if ($result instanceof \WP_Error) {
            $code = method_exists($result, 'get_error_code') ? (string) $result->get_error_code() : (string) ($result->code ?? '');
            if ('codi_mcp_upload_not_found' !== $code) {
                return $result;
            }
            return array('upload_id' => $uploadId, 'purpose' => $purpose, 'canceled' => false);
        }

        return array('upload_id' => $uploadId, 'purpose' => $purpose, 'canceled' => true);
    }

    public function canCancelUpload($input = array()): bool
    {
        $input = is_array($input) ? $input : array();
        $purpose = strtolower(trim((string) ($input['purpose'] ?? '')));
        if ('media.library' === $purpose) {
            return function_exists('current_user_can') && current_user_can('upload_files');
        }
        if ('plugins.install' === $purpose) {
            return function_exists('current_user_can')
                && current_user_can('install_plugins')
                && current_user_can('update_plugins')
                && (!(function_exists('is_multisite') && is_multisite()) || (function_exists('is_super_admin') && is_super_admin()));
        }
        if ('themes.install' === $purpose) {
            return function_exists('current_user_can')
                && current_user_can('install_themes')
                && current_user_can('update_themes')
                && (!(function_exists('is_multisite') && is_multisite()) || (function_exists('is_super_admin') && is_super_admin()));
        }
        return false;
    }

    public function cronRun($input = array())
    {
        return $this->inspector()->cronRun(is_array($input) ? $input : array());
    }

    public function rewriteFlush($input = array())
    {
        return $this->inspector()->rewriteFlush(is_array($input) ? $input : array());
    }

    public function cacheFlush($input = array())
    {
        return $this->inspector()->cacheFlush(is_array($input) ? $input : array());
    }

    public function canInspect(): bool
    {
        return $this->inspector()->canInspect();
    }

    public function canMutate(): bool
    {
        return $this->inspector()->canMutate();
    }

    public function canReadErrorLog(): bool
    {
        return $this->inspector()->canReadErrorLog();
    }

    public function canFlushCache(): bool
    {
        return $this->inspector()->canFlushCache();
    }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, $permissionCallback = null): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => is_callable($permissionCallback) ? $permissionCallback : [$this, 'canInspect'],
            'meta' => $this->readOnlyMeta(),
        ));
    }

    private function registerMutation(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback, callable $permissionCallback, bool $idempotent): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => $permissionCallback,
            'meta' => AbilityMetadata::owned($this->key(), false, true, $idempotent),

        ));
    }

    private function uploadService(): UploadCapabilityService
    {
        return $this->uploads ??= new UploadCapabilityService();
    }

    private function isCancelableUploadPurpose(string $purpose): bool
    {
        return in_array($purpose, array('plugins.install', 'themes.install', 'media.library'), true);
    }

    private function inspector(): SystemInspector
    {
        return $this->inspector ??= new SystemInspector();
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-system';
    }

    private function readOnlyMeta(): array
    {
        return AbilityMetadata::owned($this->key(), true, false, true);
    }

    private function emptyInputSchema(): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => array());
    }

    private function pagedInputSchema(array $properties): array
    {
        $properties['page'] = array('type' => 'integer', 'minimum' => 1);
        $properties['per_page'] = array('type' => 'integer', 'minimum' => 1, 'maximum' => 200);
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties);
    }

    private function pagedOutputSchema(array $itemSchema): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'items' => array('type' => 'array', 'items' => $itemSchema),
                'total' => array('type' => 'integer'),
                'page' => array('type' => 'integer'),
                'per_page' => array('type' => 'integer'),
                'returned' => array('type' => 'integer'),
            ),
            'required' => array('items', 'total', 'page', 'per_page', 'returned'),
        );
    }

    private function runtimeOutputSchema(): array
    {
        $properties = array(
            'wordpress_version' => array('type' => 'string'),
            'php_version' => array('type' => 'string'),
            'database_server' => array('type' => 'string'),
            'site_id' => array('type' => 'integer'),
            'multisite' => array('type' => 'boolean'),
            'home_url' => array('type' => 'string'),
            'site_url' => array('type' => 'string'),
            'environment_type' => array('type' => 'string'),
            'timezone' => array('type' => 'string'),
            'gmt_offset' => array('type' => 'number'),
            'wp_debug' => array('type' => 'boolean'),
            'script_debug' => array('type' => 'boolean'),
            'savequeries' => array('type' => 'boolean'),
            'wp_memory_limit' => array('type' => 'string'),
            'wp_max_memory_limit' => array('type' => 'string'),
            'php_memory_limit' => array('type' => 'string'),
            'upload_max_filesize' => array('type' => 'string'),
            'post_max_size' => array('type' => 'string'),
            'wp_max_upload_size' => array('type' => 'integer'),
            'permalink_structure' => array('type' => 'string'),
            'external_object_cache' => array('type' => 'boolean'),
            'object_cache_dropin' => array('type' => 'boolean'),
            'dropins' => array('type' => 'array', 'items' => array('type' => 'string')),
        );

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        );
    }

    private function restRouteSchema(): array
    {
        $properties = array(
            'namespace' => array('type' => 'string'),
            'route' => array('type' => 'string'),
            'methods' => array('type' => 'array', 'items' => array('type' => 'string')),
            'endpoint_count' => array('type' => 'integer'),
            'permission_callback_count' => array('type' => 'integer'),
            'permission_callbacks_present' => array('type' => 'boolean'),
        );
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function cronSchema(): array
    {
        $properties = array(
            'hook' => array('type' => 'string'),
            'timestamp' => array('type' => 'integer'),
            'next_run_gmt' => array('type' => 'string'),
            'schedule' => array('type' => 'string'),
            'interval' => array('type' => 'integer'),
            'signature' => array('type' => 'string'),
            'args_json' => array('type' => 'string'),
            'event_count_for_hook' => array('type' => 'integer'),
        );
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function siteHealthOutputSchema(): array
    {
        $test = $this->strictObject(array(
            'test' => array('type' => 'string'),
            'label' => array('type' => 'string'),
            'description' => array('type' => 'string'),
            'badge' => array('type' => 'string'),
        ));
        $list = array('type' => 'array', 'items' => $test);
        $counts = $this->strictObject(array(
            'critical' => array('type' => 'integer'),
            'recommended' => array('type' => 'integer'),
            'good' => array('type' => 'integer'),
            'informational' => array('type' => 'integer'),
        ));
        return $this->strictObject(array(
            'critical' => $list,
            'recommended' => $list,
            'good' => $list,
            'informational' => $list,
            'counts' => $counts,
            'async_tests_run' => array('type' => 'integer'),
            'async_tests_omitted' => array('type' => 'integer'),
        ));
    }

    private function errorLogOutputSchema(): array
    {
        return $this->strictObject(array(
            'enabled' => array('type' => 'boolean'),
            'available' => array('type' => 'boolean'),
            'network_shared' => array('type' => 'boolean'),
            'source' => array('type' => 'string'),
            'entries' => array('type' => 'array', 'items' => array('type' => 'string')),
            'bytes_read' => array('type' => 'integer'),
            'truncated' => array('type' => 'boolean'),
        ));
    }

    private function cacheInfoOutputSchema(): array
    {
        return $this->strictObject(array(
            'external_object_cache' => array('type' => 'boolean'),
            'object_cache_dropin' => array('type' => 'boolean'),
            'wp_cache_constant' => array('type' => 'boolean'),
            'implementation_class' => array('type' => 'string'),
            'supports_add_multiple' => array('type' => 'boolean'),
            'supports_set_multiple' => array('type' => 'boolean'),
            'supports_get_multiple' => array('type' => 'boolean'),
            'supports_delete_multiple' => array('type' => 'boolean'),
            'supports_flush_runtime' => array('type' => 'boolean'),
            'supports_flush_group' => array('type' => 'boolean'),
        ));
    }

    private function roleSchema(): array
    {
        return $this->strictObject(array(
            'role' => array('type' => 'string'),
            'name' => array('type' => 'string'),
            'capabilities' => array('type' => 'array', 'items' => array('type' => 'string')),
        ));
    }

    private function auditLogOutputSchema(): array
    {
        $record = $this->strictObject(array(
            'event_id' => array('type' => 'string'),
            'event' => array('type' => 'string'),
            'subject' => array('type' => 'string'),
            'status' => array('type' => 'string'),
            'source' => array('type' => 'string'),
            'site_id' => array('type' => 'integer'),
            'user_id' => array('type' => 'integer'),
            'occurred_at' => array('type' => 'string'),
            'completed_at' => array('type' => 'string'),
            'input_keys' => array('type' => 'array', 'items' => array('type' => 'string')),
            'client_id_hash' => array('type' => 'string'),
            'reason_code' => array('type' => 'string'),
        ));
        return $this->strictObject(array(
            'items' => array('type' => 'array', 'items' => $record),
            'total' => array('type' => 'integer'),
            'returned' => array('type' => 'integer'),
        ));
    }

    private function cronRunInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'hook' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 191),
                'timestamp' => array('type' => 'integer', 'minimum' => 1),
                'signature' => array('type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[a-f0-9]{32}$'),
            ),
            'required' => array('hook', 'timestamp', 'signature'),
        );
    }

    private function cronRunOutputSchema(): array
    {
        return $this->strictObject(array(
            'hook' => array('type' => 'string'),
            'timestamp' => array('type' => 'integer'),
            'signature' => array('type' => 'string'),
            'executed' => array('type' => 'boolean'),
            'scheduled_event_preserved' => array('type' => 'boolean'),
        ));
    }

    private function strictObject(array $properties): array
    {
        return array('type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties));
    }

    private function rewriteSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'pattern' => array('type' => 'string'),
                'query' => array('type' => 'string'),
            ),
            'required' => array('pattern', 'query'),
        );
    }
}
