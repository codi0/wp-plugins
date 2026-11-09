<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Plugins;

use CodiMcp\Core\PackageSourcePolicy;
use CodiMcp\Core\Deployment\CodeRecoveryStore;

final class PluginInspection
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private ?PackageSourcePolicy $sourcePolicy = null,
        private ?CodeRecoveryStore $recoveries = null
    ) {
        $this->sourcePolicy ??= new PackageSourcePolicy();
        $this->recoveries ??= new CodeRecoveryStore();
    }

    public function canInspect(): bool
    {
        return function_exists('current_user_can') && current_user_can('activate_plugins');
    }

    public function list(array $input = array())
    {
        $plugins = $this->plugins();
        if (is_wp_error($plugins)) {
            return $plugins;
        }

        $search = strtolower(trim((string) ($input['search'] ?? '')));
        $status = (string) ($input['status'] ?? 'all');
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 20)));
        $detail = strtolower(trim((string) ($input['detail'] ?? 'summary')));

        $rows = array_values(array_filter($plugins, static function (array $plugin) use ($search, $status): bool {
            if ($search !== '') {
                $haystack = strtolower($plugin['plugin_file'] . ' ' . $plugin['name'] . ' ' . $plugin['description']);
                if (strpos($haystack, $search) === false) {
                    return false;
                }
            }

            if ($status === 'active' && !$plugin['active']) {
                return false;
            }
            if ($status === 'inactive' && $plugin['active']) {
                return false;
            }
            if ($status === 'site-active' && !$plugin['site_active']) {
                return false;
            }
            if ($status === 'network-active' && !$plugin['network_active']) {
                return false;
            }
            if ($status === 'update-available' && !$plugin['update_available']) {
                return false;
            }
            if ($status === 'must-use' && $plugin['kind'] !== 'must-use') {
                return false;
            }

            return true;
        }));

        usort($rows, static fn (array $left, array $right): int => strnatcasecmp($left['plugin_file'], $right['plugin_file']));

        $total = count($rows);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($rows, $offset, $perPage);
        if ($detail !== 'full') {
            $items = array_map([self::class, 'summary'], $items);
        }

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($items),
        );
    }

    public function info(array $input = array())
    {
        $pluginFile = function_exists('plugin_basename') ? plugin_basename((string) ($input['plugin_file'] ?? '')) : (string) ($input['plugin_file'] ?? '');
        if ($pluginFile === '') {
            return new \WP_Error('codi_mcp_plugin_required', 'plugin_file is required.');
        }

        $plugins = $this->plugins();
        if (is_wp_error($plugins)) {
            return $plugins;
        }

        foreach ($plugins as $plugin) {
            if ($plugin['plugin_file'] === $pluginFile) {
                return $plugin;
            }
        }

        return new \WP_Error('codi_mcp_plugin_not_found', 'Installed plugin was not found.');
    }

    /** @return array<int,array<string,mixed>>|\WP_Error */
    private function plugins()
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if (!function_exists('get_plugins')) {
            return new \WP_Error('codi_mcp_plugins_unavailable', 'WordPress plugin inspection APIs are unavailable.');
        }

        $installed = get_plugins();
        $mustUse = function_exists('get_mu_plugins') ? get_mu_plugins() : array();
        $siteActive = array_fill_keys((array) get_option('active_plugins', array()), true);
        $networkActive = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        $updates = get_site_transient('update_plugins');
        $updateResponses = is_object($updates) && is_array($updates->response ?? null) ? $updates->response : array();

        $rows = array();
        foreach ($installed as $pluginFile => $data) {
            $pluginFile = (string) $pluginFile;
            $update = $updateResponses[$pluginFile] ?? null;
            $network = isset($networkActive[$pluginFile]);
            $site = isset($siteActive[$pluginFile]);
            $slug = dirname($pluginFile) === '.' ? basename($pluginFile, '.php') : dirname($pluginFile);
            $policy = $this->sourcePolicy->state('plugin', $slug);
            $latestRecovery = $this->recoveries->latest('plugin', $slug);

            $rows[] = array(
                'kind' => 'plugin',
                'plugin_file' => $pluginFile,

                'slug' => $slug,
                'name' => (string) ($data['Name'] ?? ''),
                'description' => wp_strip_all_tags((string) ($data['Description'] ?? '')),
                'version' => (string) ($data['Version'] ?? ''),
                'author' => wp_strip_all_tags((string) ($data['AuthorName'] ?? ($data['Author'] ?? ''))),
                'plugin_uri' => (string) ($data['PluginURI'] ?? ''),
                'text_domain' => (string) ($data['TextDomain'] ?? ''),
                'requires_wp' => (string) ($data['RequiresWP'] ?? ''),
                'requires_php' => (string) ($data['RequiresPHP'] ?? ''),
                'requires_plugins' => $this->dependencyList((string) ($data['RequiresPlugins'] ?? '')),
                'update_uri' => (string) ($data['UpdateURI'] ?? ''),
                'network_only' => !empty($data['Network']),
                'active' => $site || $network,
                'site_active' => $site,
                'network_active' => $network,
                'update_available' => is_object($update) || is_array($update),
                'update_version' => is_object($update) ? (string) ($update->new_version ?? '') : (is_array($update) ? (string) ($update['new_version'] ?? '') : ''),
                'source_policy' => $policy,
                'custom_replace_allowed' => dirname($pluginFile) !== '.' && $this->sourcePolicy->canCustomReplace('plugin', $slug, 'entire'),
                'delete_allowed' => dirname($pluginFile) !== '.' && $this->sourcePolicy->canDelete('plugin', $slug),
                'native_update_allowed' => is_object($update) || is_array($update),
                'recovery_scope' => 'code',
                'latest_recovery_id' => is_array($latestRecovery) ? (string) ($latestRecovery['recovery_id'] ?? '') : '',
                'latest_recovery_expires_at' => is_array($latestRecovery) ? (string) ($latestRecovery['expires_at'] ?? '') : '',
            );
        }

        foreach ((array) $mustUse as $pluginFile => $data) {
            $pluginFile = (string) $pluginFile;
            $rows[] = array(
                'kind' => 'must-use',
                'plugin_file' => $pluginFile,
                'slug' => basename($pluginFile, '.php'),
                'name' => (string) ($data['Name'] ?? ''),
                'description' => wp_strip_all_tags((string) ($data['Description'] ?? '')),
                'version' => (string) ($data['Version'] ?? ''),
                'author' => wp_strip_all_tags((string) ($data['AuthorName'] ?? ($data['Author'] ?? ''))),
                'plugin_uri' => (string) ($data['PluginURI'] ?? ''),
                'text_domain' => (string) ($data['TextDomain'] ?? ''),
                'requires_wp' => (string) ($data['RequiresWP'] ?? ''),
                'requires_php' => (string) ($data['RequiresPHP'] ?? ''),
                'requires_plugins' => $this->dependencyList((string) ($data['RequiresPlugins'] ?? '')),
                'update_uri' => (string) ($data['UpdateURI'] ?? ''),
                'network_only' => false,
                'active' => true,
                'site_active' => !is_multisite(),
                'network_active' => is_multisite(),
                'update_available' => false,
                'update_version' => '',
                'source_policy' => PackageSourcePolicy::EXTERNAL,
                'custom_replace_allowed' => false,
                'delete_allowed' => false,
                'native_update_allowed' => false,
                'recovery_scope' => 'code',
                'latest_recovery_id' => '',
                'latest_recovery_expires_at' => '',
            );
        }

        return $rows;
    }

    /** @return string[] */
    private function dependencyList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $slug): bool => $slug !== '')));
    }

    public static function listInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'search' => array('type' => 'string', 'maxLength' => 200),
                'status' => array('type' => 'string', 'enum' => array('all', 'active', 'inactive', 'site-active', 'network-active', 'update-available', 'must-use')),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE),
                'detail' => array('type' => 'string', 'enum' => array('summary', 'full'), 'default' => 'summary'),
            ),
        );
    }

    public static function infoInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'plugin_file' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 255),
            ),
            'required' => array('plugin_file'),
        );
    }

    public static function listOutputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'items' => array('type' => 'array', 'items' => self::pluginListSchema()),
                'total' => array('type' => 'integer'),
                'page' => array('type' => 'integer'),
                'per_page' => array('type' => 'integer'),
                'returned' => array('type' => 'integer'),
            ),
            'required' => array('items', 'total', 'page', 'per_page', 'returned'),
        );
    }

    public static function infoOutputSchema(): array
    {
        return self::pluginSchema();
    }

    private static function summary(array $plugin): array
    {
        return array_intersect_key($plugin, array_flip(array(
            'kind', 'plugin_file', 'slug', 'name', 'version',
            'network_only', 'active', 'site_active', 'network_active',
            'update_available', 'update_version', 'source_policy',
            'custom_replace_allowed', 'delete_allowed', 'native_update_allowed',
        )));
    }

    private static function pluginListSchema(): array
    {
        $schema = self::pluginSchema();
        $schema['required'] = array(
            'kind', 'plugin_file', 'slug', 'name', 'version',
            'network_only', 'active', 'site_active', 'network_active',
            'update_available', 'update_version', 'source_policy',
            'custom_replace_allowed', 'delete_allowed', 'native_update_allowed',
        );
        return $schema;
    }

    private static function pluginSchema(): array
    {
        $strings = array('plugin_file', 'slug', 'name', 'description', 'version', 'author', 'plugin_uri', 'text_domain', 'requires_wp', 'requires_php', 'update_uri', 'update_version', 'recovery_scope', 'latest_recovery_id', 'latest_recovery_expires_at');
        $properties = array();
        foreach ($strings as $field) {
            $properties[$field] = array('type' => 'string');
        }
        $properties['kind'] = array('type' => 'string', 'enum' => array('plugin', 'must-use'));
        $properties['requires_plugins'] = array('type' => 'array', 'items' => array('type' => 'string'));
        $properties['source_policy'] = array('type' => 'string', 'enum' => array(PackageSourcePolicy::MANAGED, PackageSourcePolicy::EXTERNAL, PackageSourcePolicy::PROTECTED));
        foreach (array('network_only', 'active', 'site_active', 'network_active', 'update_available', 'custom_replace_allowed', 'delete_allowed', 'native_update_allowed') as $field) {
            $properties[$field] = array('type' => 'boolean');
        }

        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        );
    }
}
