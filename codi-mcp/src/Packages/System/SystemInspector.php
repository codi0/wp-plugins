<?php

declare(strict_types=1);

namespace CodiMcp\Packages\System;

use CodiMcp\Audit\AuditLog;
use CodiMcp\Core\Security\SensitiveDataRedactor;

final class SystemInspector
{
    private const MAX_PER_PAGE = 200;

    private ?SensitiveDataRedactor $redactor = null;
    private ?AuditLog $audit = null;

    public function canInspect(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    public function canMutate(): bool
    {
        return $this->canInspect();
    }

    public function canReadErrorLog(): bool
    {
        return $this->canInspect()
            && (!function_exists('is_multisite') || !is_multisite() || (function_exists('is_super_admin') && is_super_admin()));
    }

    public function canFlushCache(): bool
    {
        return $this->canInspect()
            && (!function_exists('is_multisite') || !is_multisite() || (function_exists('is_super_admin') && is_super_admin()));
    }

    public function runtimeInfo(array $input = array()): array
    {
        global $wpdb, $wp_version;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $dropins = function_exists('get_dropins') ? array_keys((array) get_dropins()) : array();
        sort($dropins);

        $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string', '');
        $dbServer = is_object($wpdb) && method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';

        return array(
            'wordpress_version' => isset($wp_version) ? (string) $wp_version : (defined('WP_VERSION') ? (string) WP_VERSION : ''),
            'php_version' => PHP_VERSION,
            'database_server' => $dbServer,
            'site_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'multisite' => function_exists('is_multisite') && is_multisite(),
            'home_url' => function_exists('home_url') ? (string) home_url('/') : '',
            'site_url' => function_exists('site_url') ? (string) site_url('/') : '',
            'environment_type' => function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : '',
            'timezone' => $timezone,
            'gmt_offset' => (float) get_option('gmt_offset', 0),
            'wp_debug' => defined('WP_DEBUG') && (bool) WP_DEBUG,
            'script_debug' => defined('SCRIPT_DEBUG') && (bool) SCRIPT_DEBUG,
            'savequeries' => defined('SAVEQUERIES') && (bool) SAVEQUERIES,
            'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
            'wp_max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? (string) WP_MAX_MEMORY_LIMIT : '',
            'php_memory_limit' => (string) ini_get('memory_limit'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'wp_max_upload_size' => function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0,
            'permalink_structure' => (string) get_option('permalink_structure', ''),
            'external_object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'object_cache_dropin' => defined('WP_CONTENT_DIR') && is_file(WP_CONTENT_DIR . '/object-cache.php'),
            'dropins' => array_values(array_map('strval', $dropins)),
        );
    }

    public function restRoutesList(array $input = array()): array
    {
        $server = function_exists('rest_get_server') ? rest_get_server() : null;
        if (!is_object($server) || !method_exists($server, 'get_routes')) {
            return $this->page(array(), 1, 50);
        }

        if (function_exists('did_action') && function_exists('do_action') && did_action('rest_api_init') === 0) {
            do_action('rest_api_init', $server);
        }

        $namespaceFilter = trim((string) ($input['namespace'] ?? ''));
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $namespaces = method_exists($server, 'get_namespaces') ? array_values(array_map('strval', (array) $server->get_namespaces())) : array();
        usort($namespaces, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $rows = array();
        foreach ((array) $server->get_routes() as $route => $endpoints) {
            $route = (string) $route;
            $namespace = $this->routeNamespace($route, $namespaces);
            if ($namespaceFilter !== '' && $namespace !== $namespaceFilter) {
                continue;
            }
            if ($search !== '' && strpos(strtolower($route), $search) === false) {
                continue;
            }

            $methods = array();
            $permissionCount = 0;
            $endpointCount = 0;
            foreach ((array) $endpoints as $endpoint) {
                if (!is_array($endpoint)) {
                    continue;
                }
                ++$endpointCount;
                foreach ($this->endpointMethods($endpoint['methods'] ?? array()) as $method) {
                    $methods[$method] = true;
                }
                if (array_key_exists('permission_callback', $endpoint) && is_callable($endpoint['permission_callback'])) {
                    ++$permissionCount;
                }
            }

            $methodNames = array_keys($methods);
            sort($methodNames);
            $rows[] = array(
                'namespace' => $namespace,
                'route' => $route,
                'methods' => $methodNames,
                'endpoint_count' => $endpointCount,
                'permission_callback_count' => $permissionCount,
                'permission_callbacks_present' => $endpointCount > 0 && $permissionCount === $endpointCount,
            );
        }

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['route'], $right['route']));
        return $this->page($rows, $page, $perPage);
    }

    public function cronList(array $input = array()): array
    {
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $hookFilter = trim((string) ($input['hook'] ?? ''));
        $includeArgs = !empty($input['include_args']);
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $cron = function_exists('_get_cron_array') ? (array) _get_cron_array() : array();

        $hookCounts = array();
        foreach ($cron as $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                $hookCounts[(string) $hook] = ($hookCounts[(string) $hook] ?? 0) + count((array) $events);
            }
        }

        $rows = array();
        foreach ($cron as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                $hook = (string) $hook;
                if ($hookFilter !== '' && $hook !== $hookFilter) {
                    continue;
                }
                if ($search !== '' && strpos(strtolower($hook), $search) === false) {
                    continue;
                }

                foreach ((array) $events as $signature => $event) {
                    $event = is_array($event) ? $event : array();
                    $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : array();
                    $rows[] = array(
                        'hook' => $hook,
                        'timestamp' => (int) $timestamp,
                        'next_run_gmt' => gmdate('c', (int) $timestamp),
                        'schedule' => is_string($event['schedule'] ?? null) ? $event['schedule'] : '',
                        'interval' => isset($event['interval']) ? (int) $event['interval'] : 0,
                        'signature' => (string) $signature,
                        'args_json' => $includeArgs ? $this->sanitizedJson($args) : '',
                        'event_count_for_hook' => (int) ($hookCounts[$hook] ?? 0),
                    );
                }
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return array($left['timestamp'], $left['hook'], $left['signature']) <=> array($right['timestamp'], $right['hook'], $right['signature']);
        });

        return $this->page($rows, $page, $perPage);
    }

    public function rewriteRules(array $input = array()): array
    {
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $rules = get_option('rewrite_rules', array());
        $rules = is_array($rules) ? $rules : array();

        $rows = array();
        foreach ($rules as $pattern => $query) {
            $pattern = (string) $pattern;
            $query = (string) $query;
            if ($search !== '' && strpos(strtolower($pattern . ' ' . $query), $search) === false) {
                continue;
            }
            $rows[] = array('pattern' => $pattern, 'query' => $query);
        }

        return $this->page($rows, $page, $perPage);
    }

    public function siteHealth(array $input = array())
    {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

        if (!class_exists('WP_Site_Health')) {
            return new \WP_Error('codi_mcp_site_health_unavailable', 'WordPress Site Health APIs are unavailable.');
        }

        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $includeGood = !array_key_exists('include_good', $input) || !empty($input['include_good']);
        $includeAsync = !empty($input['include_async']);
        $instance = \WP_Site_Health::get_instance();
        $tests = \WP_Site_Health::get_tests();
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : array();
        $async = is_array($tests['async'] ?? null) ? $tests['async'] : array();
        $groups = array('critical' => array(), 'recommended' => array(), 'good' => array(), 'informational' => array());

        $runTest = function (string $testId, array $definition, $callback) use (&$groups, $instance, $search, $includeGood): bool {
            $label = wp_strip_all_tags((string) ($definition['label'] ?? $testId));
            if ($search !== '' && strpos(strtolower($testId . ' ' . $label), $search) === false) {
                return false;
            }
            if (is_string($callback)) {
                $method = 'get_test_' . $callback;
                $callback = method_exists($instance, $method) ? array($instance, $method) : null;
            }
            if (!is_callable($callback)) {
                return false;
            }

            try {
                $result = call_user_func($callback);
                if (!is_wp_error($result) && function_exists('apply_filters')) {
                    $result = apply_filters('site_status_test_result', $result);
                }
            } catch (\Throwable $throwable) {
                $result = array(
                    'status' => 'informational',
                    'label' => $label,
                    'description' => 'Site Health test failed to execute: ' . $throwable->getMessage(),
                );
            }
            if (is_wp_error($result)) {
                $result = array(
                    'status' => 'informational',
                    'label' => $label,
                    'description' => $result->get_error_message(),
                );
            }
            if (!is_array($result)) {
                return true;
            }

            $status = strtolower((string) ($result['status'] ?? 'informational'));
            if (!isset($groups[$status])) {
                $status = 'informational';
            }
            if ($status === 'good' && !$includeGood) {
                return true;
            }
            $badge = is_array($result['badge'] ?? null) ? $result['badge'] : array();
            $groups[$status][] = array(
                'test' => $testId,
                'label' => wp_strip_all_tags((string) ($result['label'] ?? $label)),
                'description' => $this->boundedText(wp_strip_all_tags((string) ($result['description'] ?? ''), true), 4000),
                'badge' => wp_strip_all_tags((string) ($badge['label'] ?? '')),
            );
            return true;
        };

        foreach ($direct as $testId => $definition) {
            $definition = is_array($definition) ? $definition : array();
            $runTest((string) $testId, $definition, $definition['test'] ?? null);
        }

        $asyncRun = 0;
        $asyncOmitted = 0;
        if ($includeAsync) {
            foreach ($async as $testId => $definition) {
                $definition = is_array($definition) ? $definition : array();
                $callback = $definition['async_direct_test'] ?? null;
                if (!is_callable($callback)) {
                    ++$asyncOmitted;
                    continue;
                }
                if ($runTest((string) $testId, $definition, $callback)) {
                    ++$asyncRun;
                }
            }
        } else {
            $asyncOmitted = count($async);
        }

        return array(
            'critical' => $groups['critical'],
            'recommended' => $groups['recommended'],
            'good' => $groups['good'],
            'informational' => $groups['informational'],
            'counts' => array(
                'critical' => count($groups['critical']),
                'recommended' => count($groups['recommended']),
                'good' => count($groups['good']),
                'informational' => count($groups['informational']),
            ),
            'async_tests_run' => $asyncRun,
            'async_tests_omitted' => $asyncOmitted,
        );
    }

    public function errorLogTail(array $input = array())
    {
        $networkShared = function_exists('is_multisite') && is_multisite();
        $enabled = defined('WP_DEBUG') && (bool) WP_DEBUG && defined('WP_DEBUG_LOG') && (bool) WP_DEBUG_LOG;
        $empty = array('enabled' => $enabled, 'available' => false, 'network_shared' => $networkShared, 'source' => '', 'entries' => array(), 'bytes_read' => 0, 'truncated' => false);
        if (!$enabled) {
            return $empty;
        }
        if (!defined('WP_CONTENT_DIR')) {
            return new \WP_Error('codi_mcp_debug_log_unavailable', 'WP_CONTENT_DIR is unavailable, so the configured debug log cannot be safely resolved.');
        }

        $configured = WP_DEBUG_LOG;
        $path = $configured === true ? WP_CONTENT_DIR . '/debug.log' : (is_string($configured) ? $configured : '');
        if ($path === '') {
            return $empty;
        }

        $contentRoot = realpath(WP_CONTENT_DIR);
        if (!is_string($contentRoot) || $contentRoot === '') {
            return new \WP_Error('codi_mcp_debug_log_unavailable', 'The WordPress content directory could not be resolved.');
        }
        $contentRoot = rtrim(str_replace('\\', '/', $contentRoot), '/');
        $normalizedConfigured = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($normalizedConfigured, '/') || preg_match('/^[A-Za-z]:\//', $normalizedConfigured) === 1;
        if (!$isAbsolute) {
            return new \WP_Error('codi_mcp_debug_log_unsafe_path', 'WP_DEBUG_LOG must use an absolute path inside WP_CONTENT_DIR.');
        }

        if (!is_file($path)) {
            $normalizedContent = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/');
            if ($normalizedConfigured !== $normalizedContent && strpos($normalizedConfigured, $normalizedContent . '/') !== 0) {
                return new \WP_Error('codi_mcp_debug_log_unsafe_path', 'The configured WP_DEBUG_LOG path is outside WP_CONTENT_DIR.');
            }
            $empty['source'] = basename($normalizedContent) . '/' . ltrim(substr($normalizedConfigured, strlen($normalizedContent)), '/');
            return $empty;
        }

        $resolved = realpath($path);
        if (!is_string($resolved) || $resolved === '') {
            return new \WP_Error('codi_mcp_debug_log_unavailable', 'The configured debug log could not be resolved.');
        }
        $resolved = str_replace('\\', '/', $resolved);
        if ($resolved !== $contentRoot && strpos($resolved, $contentRoot . '/') !== 0) {
            return new \WP_Error('codi_mcp_debug_log_unsafe_path', 'The configured WP_DEBUG_LOG file resolves outside WP_CONTENT_DIR.');
        }

        $lineLimit = min(200, max(1, (int) ($input['lines'] ?? 100)));
        $maxBytes = min(65536, max(1024, (int) ($input['max_bytes'] ?? 32768)));
        $size = filesize($resolved);
        if ($size === false) {
            return new \WP_Error('codi_mcp_debug_log_read_failed', 'The configured debug log size could not be read.');
        }
        $bytesToRead = min((int) $size, $maxBytes);
        $offset = max(0, (int) $size - $bytesToRead);
        $handle = @fopen($resolved, 'rb');
        if (!is_resource($handle)) {
            return new \WP_Error('codi_mcp_debug_log_read_failed', 'The configured debug log could not be opened.');
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $data = (string) stream_get_contents($handle, $bytesToRead);
        fclose($handle);
        $bytesRead = strlen($data);

        $truncated = $offset > 0;
        if ($offset > 0) {
            $firstBreak = strpos($data, "\n");
            if ($firstBreak !== false) {
                $data = substr($data, $firstBreak + 1);
            }
        }
        $entries = preg_split('/\R/u', $data);
        $entries = is_array($entries) ? $entries : array();
        if ($entries !== array() && end($entries) === '') {
            array_pop($entries);
        }
        if (count($entries) > $lineLimit) {
            $entries = array_slice($entries, -$lineLimit);
            $truncated = true;
        }
        $entries = array_values(array_map(fn (string $line): string => $this->redactLogLine($line), $entries));

        $relative = ltrim(substr($resolved, strlen($contentRoot)), '/');
        return array(
            'enabled' => true,
            'available' => true,
            'network_shared' => $networkShared,
            'source' => basename($contentRoot) . '/' . $relative,
            'entries' => $entries,
            'bytes_read' => $bytesRead,
            'truncated' => $truncated,
        );
    }

    public function cacheInfo(array $input = array()): array
    {
        global $wp_object_cache;

        $implementation = is_object($wp_object_cache) ? get_class($wp_object_cache) : '';
        $supports = array();
        foreach (array('add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group') as $feature) {
            $supports[$feature] = function_exists('wp_cache_supports') && wp_cache_supports($feature);
        }

        return array(
            'external_object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
            'object_cache_dropin' => defined('WP_CONTENT_DIR') && is_file(WP_CONTENT_DIR . '/object-cache.php'),
            'wp_cache_constant' => defined('WP_CACHE') && (bool) WP_CACHE,
            'implementation_class' => $implementation,
            'supports_add_multiple' => $supports['add_multiple'],
            'supports_set_multiple' => $supports['set_multiple'],
            'supports_get_multiple' => $supports['get_multiple'],
            'supports_delete_multiple' => $supports['delete_multiple'],
            'supports_flush_runtime' => $supports['flush_runtime'],
            'supports_flush_group' => $supports['flush_group'],
        );
    }

    public function rolesList(array $input = array()): array
    {
        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 50)));
        $rolesObject = function_exists('wp_roles') ? wp_roles() : null;
        $roles = is_object($rolesObject) && is_array($rolesObject->roles ?? null) ? $rolesObject->roles : array();
        $rows = array();
        foreach ($roles as $slug => $role) {
            $role = is_array($role) ? $role : array();
            $name = (string) ($role['name'] ?? $slug);
            if ($search !== '' && strpos(strtolower((string) $slug . ' ' . $name), $search) === false) {
                continue;
            }
            $capabilities = array();
            foreach ((array) ($role['capabilities'] ?? array()) as $capability => $granted) {
                if ($granted) {
                    $capabilities[] = (string) $capability;
                }
            }
            sort($capabilities);
            $rows[] = array('role' => (string) $slug, 'name' => $name, 'capabilities' => $capabilities);
        }
        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['role'], $right['role']));
        return $this->page($rows, $page, $perPage);
    }

    public function auditLog(array $input = array()): array
    {
        $limit = min(200, max(1, (int) ($input['limit'] ?? 50)));
        $all = $this->audit()->list(250);
        return array(
            'items' => array_slice($all, 0, $limit),
            'total' => count($all),
            'returned' => min(count($all), $limit),
        );
    }

    public function cronRun(array $input = array())
    {
        $hook = trim((string) ($input['hook'] ?? ''));
        $timestamp = (int) ($input['timestamp'] ?? 0);
        $signature = strtolower(trim((string) ($input['signature'] ?? '')));
        if ($hook === '' || $timestamp <= 0 || !preg_match('/^[a-f0-9]{32}$/', $signature)) {
            return new \WP_Error('codi_mcp_cron_event_invalid', 'hook, timestamp, and a valid event signature are required.');
        }

        $cron = function_exists('_get_cron_array') ? (array) _get_cron_array() : array();
        $event = $cron[$timestamp][$hook][$signature] ?? null;
        if (!is_array($event)) {
            return new \WP_Error('codi_mcp_cron_event_not_found', 'The explicitly selected scheduled cron event was not found.');
        }

        $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : array();
        try {
            do_action_ref_array($hook, $args);
        } catch (\Throwable $throwable) {
            return new \WP_Error('codi_mcp_cron_event_failed', 'The selected cron event raised an error: ' . $throwable->getMessage());
        }

        return array(
            'hook' => $hook,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'executed' => true,
            'scheduled_event_preserved' => true,
        );
    }

    public function rewriteFlush(array $input = array()): array
    {
        $hard = !empty($input['hard']);
        flush_rewrite_rules($hard);
        $rules = get_option('rewrite_rules', array());
        return array(
            'hard' => $hard,
            'rule_count' => is_array($rules) ? count($rules) : 0,
        );
    }

    public function cacheFlush(array $input = array())
    {
        if (!function_exists('wp_cache_flush')) {
            return new \WP_Error('codi_mcp_cache_flush_unavailable', 'WordPress object cache flush is unavailable.');
        }
        $success = (bool) wp_cache_flush();
        return array(
            'success' => $success,
            'external_object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
        );
    }

    private function redactLogLine(string $line): string
    {
        return $this->boundedText($this->redactor()->redactLine($line), 4000);
    }

    private function boundedText(string $value, int $maxBytes): string
    {
        $value = trim($value);
        return strlen($value) > $maxBytes ? substr($value, 0, $maxBytes) . '…' : $value;
    }

    private function routeNamespace(string $route, array $namespaces): string
    {
        foreach ($namespaces as $namespace) {
            $prefix = '/' . trim($namespace, '/') . '/';
            if (strpos($route . '/', $prefix) === 0) {
                return $namespace;
            }
        }
        return '';
    }

    /** @return string[] */
    private function endpointMethods($methods): array
    {
        if (is_string($methods)) {
            $methods = preg_split('/[\s,|]+/', strtoupper($methods), -1, PREG_SPLIT_NO_EMPTY);
            return is_array($methods) ? array_values(array_unique($methods)) : array();
        }

        $result = array();
        foreach ((array) $methods as $method => $enabled) {
            if (is_int($method)) {
                $result[] = strtoupper((string) $enabled);
            } elseif ($enabled) {
                $result[] = strtoupper((string) $method);
            }
        }
        return array_values(array_unique(array_filter($result)));
    }

    private function page(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);
        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($items),
        );
    }

    private function sanitizedJson(array $value): string
    {
        $sanitized = $this->redactor()->sanitize($value);
        $json = function_exists('wp_json_encode') ? wp_json_encode($sanitized) : json_encode($sanitized);
        $json = is_string($json) ? $json : '[]';
        return strlen($json) > 4000 ? substr($json, 0, 4000) . '…' : $json;
    }

    private function redactor(): SensitiveDataRedactor
    {
        return $this->redactor ??= new SensitiveDataRedactor();
    }

    private function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog();
    }
}
