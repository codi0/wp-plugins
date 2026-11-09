<?php

namespace CodiMcp\Admin;

use CodiMcp\Auth\OAuthProfile;
use CodiMcp\Auth\OAuthServer;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\PackageAvailabilityPolicy;
use CodiMcp\Core\PackageSourcePolicy;
use CodiMcp\Exposure\ExposurePolicy;

final class AdminPage
{
    private const PAGE_SLUG = 'codi-mcp';

    private ExposurePolicy $exposure;
    private OAuthProfile $profile;
    private OAuthServer $oauthServer;
    private PackageAvailabilityPolicy $availability;
    private PackageSourcePolicy $sourcePolicy;

    /** @var AbilityPackage[] */
    private array $packages;

    /** @param AbilityPackage[] $packages */
    public function __construct(
        ExposurePolicy $exposure,
        OAuthProfile $profile,
        OAuthServer $oauthServer,
        PackageAvailabilityPolicy $availability,
        array $packages,
        ?PackageSourcePolicy $sourcePolicy = null
    ) {
        $this->exposure = $exposure;
        $this->profile = $profile;
        $this->oauthServer = $oauthServer;
        $this->availability = $availability;
        $this->sourcePolicy = $sourcePolicy ?? new PackageSourcePolicy();
        $this->packages = array_values($packages);
    }

    public function saveExposure(): void
    {
        if (!$this->canManage()) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to manage Codi MCP.');
            }
            return;
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer('codi_mcp_save_exposure');
        }

        $enabled = isset($_POST['enabled']) && is_array($_POST['enabled'])
            ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash($_POST['enabled'])), 'is_string'))
            : array();
        if (!$this->exposure->saveSelection($enabled)) {
            $this->redirect(array('error' => 'exposure'));
            return;
        }

        $this->redirect(array('updated' => 'exposure'));
    }

    public function savePackages(): void
    {
        if (!$this->canManage()) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to manage Codi MCP.');
            }
            return;
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer('codi_mcp_save_packages');
        }

        $delegated = $this->delegatedPackages();
        $delegatedKeys = array_values(array_map(static fn (AbilityPackage $package): string => $package->key(), $delegated));
        $enabled = isset($_POST['packages']) && is_array($_POST['packages'])
            ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash($_POST['packages'])), 'is_string'))
            : array();

        if (!$this->availability->saveSiteDelegates($enabled, $delegatedKeys)) {
            $this->redirect(array('error' => 'packages'));
            return;
        }
        $this->redirect(array('updated' => 'packages'));
    }

    public function revokeOAuthClient(): void
    {
        if (!$this->canManage()) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to manage Codi MCP.');
            }
            return;
        }

        $clientId = isset($_POST['client_id']) ? trim((string) sanitize_text_field(wp_unslash($_POST['client_id']))) : '';
        if ($clientId === '') {
            $this->redirect(array('error' => 'oauth'));
            return;
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('codi_mcp_revoke_oauth_client_' . $clientId);
        }
        $this->redirect($this->oauthServer->revokeClient($clientId)
            ? array('updated' => 'oauth')
            : array('error' => 'oauth'));
    }

    public function saveSourcePolicy(): void
    {
        if (!$this->canManage() || (function_exists('is_multisite') && is_multisite())) {
            return;
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('codi_mcp_save_source_policy');
        }
        $managed = isset($_POST['managed_sources']) && is_array($_POST['managed_sources'])
            ? array_values(array_filter(array_map('sanitize_text_field', wp_unslash($_POST['managed_sources'])), 'is_string'))
            : array();
        $this->redirect($this->sourcePolicy->saveManaged($managed)
            ? array('updated' => 'sources')
            : array('error' => 'sources'));
    }

    public function render(): void
    {
        if (!$this->canManage()) {
            return;
        }

        $allRows = $this->exposure->rows();
        $managedCount = count(array_filter($allRows, static fn (array $row): bool => !empty($row['managed'])));
        $rows = array_values(array_filter($allRows, static fn (array $row): bool => empty($row['managed'])));
        $enabledCount = count(array_filter($rows, static fn (array $row): bool => !empty($row['enabled'])));
        $groups = array();
        foreach ($rows as $row) {
            $groups[$this->groupKey($row)][] = $row;
        }
        uksort($groups, 'strnatcasecmp');

        echo '<div class="wrap"><h1>Codi MCP</h1>';
        if (($_GET['error'] ?? '') === 'exposure') {
            echo '<div class="notice notice-error"><p>Ability exposure could not be saved. No persisted change was confirmed.</p></div>';
        } elseif (($_GET['error'] ?? '') === 'packages') {
            echo '<div class="notice notice-error"><p>Site package availability could not be saved. No persisted change was confirmed.</p></div>';
        } elseif (($_GET['error'] ?? '') === 'oauth') {
            echo '<div class="notice notice-error"><p>The OAuth client could not be disconnected.</p></div>';
        } elseif (($_GET['error'] ?? '') === 'sources') {
            echo '<div class="notice notice-error"><p>Managed source policy could not be saved.</p></div>';
        }
        if (($_GET['updated'] ?? '') === 'exposure') {
            echo '<div class="notice notice-success is-dismissible"><p>Ability exposure updated. Reconnect or rescan the MCP client to refresh its tool catalogue.</p></div>';
        } elseif (($_GET['updated'] ?? '') === 'packages') {
            echo '<div class="notice notice-success is-dismissible"><p>Site package availability updated.</p></div>';
        } elseif (($_GET['updated'] ?? '') === 'oauth') {
            echo '<div class="notice notice-success is-dismissible"><p>OAuth client disconnected and its active credentials revoked.</p></div>';
        } elseif (($_GET['updated'] ?? '') === 'sources') {
            echo '<div class="notice notice-success is-dismissible"><p>Managed source policy updated.</p></div>';
        }

        echo '<p>Codi MCP registers a dedicated site-local server through the official WordPress MCP Adapter. Package availability and ability exposure are separate controls.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
        $this->row('MCP endpoint', $this->profile->primaryEndpoint());
        $this->row('OAuth issuer', $this->profile->issuer());
        $this->row('OAuth metadata', $this->profile->authorizationServerMetadataPath());
        $this->row('Adapter available', class_exists('WP\\MCP\\Core\\McpAdapter') ? 'Yes' : 'No');
        $this->row('Enabled site abilities', (string) $enabledCount . ' / ' . (string) count($rows));
        if ($managedCount > 0) {
            $this->row('Network-managed abilities', (string) $managedCount);
        }
        echo '</tbody></table>';

        $this->renderOAuthClients();

        if (!function_exists('is_multisite') || !is_multisite()) {
            $this->renderSourcePolicy();
        }

        $this->renderDelegatedPackages();

        echo '<h2>Ability exposure</h2>';
        echo '<p>Abilities delegated to this site are disabled by default and must be explicitly selected before they are exposed through this site\'s dedicated Codi MCP server. Each ability permission callback still runs on every invocation.</p>';
        if ($managedCount > 0) {
            echo '<p><strong>Network-managed abilities are exposed by network package policy</strong> and are intentionally not editable from this site-level exposure page.</p>';
        }
        if ($groups !== array()) {
            echo '<nav class="nav-tab-wrapper" aria-label="Ability groups">';
            echo '<a href="#codi-mcp-group-all" class="nav-tab nav-tab-active" data-codi-mcp-tab="all">All <span class="count">(' . count($rows) . ')</span></a>';
            foreach ($groups as $groupKey => $groupRows) {
                $token = $this->groupToken($groupKey);
                echo '<a href="#codi-mcp-group-' . $this->escapeAttr($token) . '" class="nav-tab" data-codi-mcp-tab="' . $this->escapeAttr($token) . '">' . $this->escape($this->groupLabel($groupKey)) . ' <span class="count">(' . count($groupRows) . ')</span></a>';
            }
            echo '</nav>';
        }

        echo '<form method="post" action="' . $this->escape($this->adminPostUrl()) . '">';
        echo '<input type="hidden" name="action" value="codi_mcp_save_exposure">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('codi_mcp_save_exposure');
        }

        foreach ($groups as $groupKey => $groupRows) {
            $token = $this->groupToken($groupKey);
            echo '<section id="codi-mcp-group-' . $this->escapeAttr($token) . '" data-codi-mcp-panel="' . $this->escapeAttr($token) . '">';
            echo '<h3>' . $this->escape($this->groupLabel($groupKey)) . '</h3>';
            echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:70px">Expose</th><th>Ability</th><th>Origin</th><th>Type</th><th>Category</th></tr></thead><tbody>';
            foreach ($groupRows as $row) {
                $name = (string) $row['name'];
                echo '<tr><td><input type="checkbox" name="enabled[]" value="' . $this->escapeAttr($name) . '"' . (!empty($row['enabled']) ? ' checked' : '') . '></td>';
                echo '<td><strong>' . $this->escape((string) $row['label']) . '</strong><br><code>' . $this->escape($name) . '</code>';
                if ((string) $row['description'] !== '') {
                    echo '<br><span class="description">' . $this->escape((string) $row['description']) . '</span>';
                }
                echo '</td><td>' . $this->escape((string) $row['origin']) . '</td><td>' . $this->escape((string) $row['type']) . '</td><td><code>' . $this->escape((string) $row['category']) . '</code></td></tr>';
            }
            echo '</tbody></table></section>';
        }

        if (function_exists('submit_button')) {
            submit_button('Save exposure');
        } else {
            echo '<p><button type="submit">Save exposure</button></p>';
        }
        echo '</form>';

        if ($groups !== array()) {
            echo '<script>(function(){var tabs=document.querySelectorAll("[data-codi-mcp-tab]");var panels=document.querySelectorAll("[data-codi-mcp-panel]");function select(group){tabs.forEach(function(tab){tab.classList.toggle("nav-tab-active",tab.getAttribute("data-codi-mcp-tab")===group);});panels.forEach(function(panel){panel.hidden=group!=="all"&&panel.getAttribute("data-codi-mcp-panel")!==group;});}tabs.forEach(function(tab){tab.addEventListener("click",function(event){event.preventDefault();select(tab.getAttribute("data-codi-mcp-tab"));});});select("all");})();</script>';
        }

        echo '</div>';
    }

    private function renderDelegatedPackages(): void
    {
        $packages = $this->delegatedPackages();
        if ($packages === array()) {
            return;
        }

        echo '<h2>Site packages</h2>';
        echo '<p>The network administrator has delegated these packages to this site. Disabling a package removes its abilities from this site without deleting the site\'s saved ability exposure choices.</p>';
        echo '<form method="post" action="' . $this->escape($this->adminPostUrl()) . '">';
        echo '<input type="hidden" name="action" value="codi_mcp_save_packages">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('codi_mcp_save_packages');
        }
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:70px">Active</th><th>Package</th></tr></thead><tbody>';
        foreach ($packages as $package) {
            $key = $package->key();
            echo '<tr><td><input type="checkbox" name="packages[]" value="' . $this->escapeAttr($key) . '"' . ($this->availability->siteEnabled($key) ? ' checked' : '') . '></td>';
            echo '<td><strong>' . $this->escape($package->label()) . '</strong><br><code>' . $this->escape($key) . '</code></td></tr>';
        }
        echo '</tbody></table>';
        if (function_exists('submit_button')) {
            submit_button('Save site packages', 'secondary');
        } else {
            echo '<p><button type="submit">Save site packages</button></p>';
        }
        echo '</form>';
    }

    private function renderOAuthClients(): void
    {
        $clients = $this->oauthServer->clients();
        echo '<h2>Connected OAuth clients</h2>';
        if ($clients === array()) {
            echo '<p>No OAuth clients are currently registered for this site.</p>';
            return;
        }

        echo '<p>Disconnecting a client invalidates its authorization codes, refresh tokens, and access tokens immediately.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Client</th><th>Callback hosts</th><th>Last used</th><th style="width:120px">Action</th></tr></thead><tbody>';
        foreach ($clients as $client) {
            $clientId = trim((string) ($client['client_id'] ?? ''));
            if ($clientId === '') {
                continue;
            }
            $hosts = array();
            foreach ((array) ($client['redirect_uris'] ?? array()) as $uri) {
                $host = parse_url((string) $uri, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[] = strtolower($host);
                }
            }
            $hosts = array_values(array_unique($hosts));
            $lastUsed = (int) ($client['last_used_at'] ?? 0);
            $lastUsedLabel = $lastUsed > 0
                ? (function_exists('date_i18n') ? (string) date_i18n('Y-m-d H:i:s', $lastUsed) : gmdate('Y-m-d H:i:s', $lastUsed) . ' UTC')
                : 'Never';
            echo '<tr><td><strong>' . $this->escape((string) ($client['client_name'] ?? 'OAuth client')) . '</strong><br><code>' . $this->escape($clientId) . '</code></td>';
            echo '<td>' . $this->escape($hosts === array() ? '—' : implode(', ', $hosts)) . '</td><td>' . $this->escape($lastUsedLabel) . '</td><td>';
            echo '<form method="post" action="' . $this->escape($this->adminPostUrl()) . '"><input type="hidden" name="action" value="codi_mcp_revoke_oauth_client"><input type="hidden" name="client_id" value="' . $this->escapeAttr($clientId) . '">';
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('codi_mcp_revoke_oauth_client_' . $clientId);
            }
            echo '<button type="submit" class="button">Disconnect</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderSourcePolicy(): void
    {
        echo '<h2>Source management</h2>';
        echo '<p>Managed packages may be replaced with custom source or deleted by Codi. Codi MCP is protected, and the codi-mcp-custom companion is intrinsically managed. Other packages require explicit source-management approval. External packages remain inspectable and may use WordPress-advertised native updates. Code recovery does not restore database state.</p>';
        echo '<form method="post" action="' . $this->escape($this->adminPostUrl()) . '"><input type="hidden" name="action" value="codi_mcp_save_source_policy">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('codi_mcp_save_source_policy');
        }
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:90px">Managed</th><th>Package</th><th>Policy</th></tr></thead><tbody>';
        foreach ($this->sourceRows() as $row) {
            $fixed = (bool) ($row['fixed'] ?? false);
            echo '<tr><td>';
            if ($fixed) {
                echo '—';
            } else {
                echo '<input type="checkbox" name="managed_sources[]" value="' . $this->escapeAttr($row['key']) . '"' . ($row['policy'] === PackageSourcePolicy::MANAGED ? ' checked' : '') . '>';
            }
            echo '</td><td><strong>' . $this->escape($row['name']) . '</strong><br><code>' . $this->escape($row['key']) . '</code></td><td>' . $this->escape($row['policy']) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (function_exists('submit_button')) {
            submit_button('Save source management', 'secondary');
        } else {
            echo '<p><button type="submit">Save source management</button></p>';
        }
        echo '</form>';
    }

    /** @return array<int,array{key:string,name:string,policy:string}> */
    private function sourceRows(): array
    {
        $rows = array();
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('get_plugins')) {
            foreach ((array) get_plugins() as $file => $data) {
                $slug = dirname((string) $file);
                if ($slug === '.' || str_contains($slug, '/')) {
                    continue;
                }
                $key = $this->sourcePolicy->key('plugin', $slug);
                $rows[] = array('key' => $key, 'name' => (string) ($data['Name'] ?? $slug), 'policy' => $this->sourcePolicy->state('plugin', $slug), 'fixed' => $this->sourcePolicy->isFixed('plugin', $slug));
            }
        }
        if (function_exists('wp_get_themes')) {
            foreach ((array) wp_get_themes(array('errors' => null, 'allowed' => null)) as $stylesheet => $theme) {
                if (!is_object($theme)) {
                    continue;
                }
                $key = $this->sourcePolicy->key('theme', (string) $stylesheet);
                $name = method_exists($theme, 'get') ? (string) $theme->get('Name') : (string) $stylesheet;
                $rows[] = array('key' => $key, 'name' => $name, 'policy' => $this->sourcePolicy->state('theme', (string) $stylesheet), 'fixed' => $this->sourcePolicy->isFixed('theme', (string) $stylesheet));
            }
        }
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['key'], $b['key']));
        return $rows;
    }

    /** @return AbilityPackage[] */
    private function delegatedPackages(): array
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return array();
        }

        return array_values(array_filter(
            $this->packages,
            fn (AbilityPackage $package): bool => $package->key() !== 'multisite' && $this->availability->isDelegated($package->key())
        ));
    }

    private function groupKey(array $row): string
    {
        foreach (array('package', 'category', 'namespace') as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'other';
    }

    private function groupLabel(string $groupKey): string
    {
        return ucwords(str_replace(array('-', '_'), ' ', $groupKey));
    }

    private function groupToken(string $groupKey): string
    {
        return substr(sha1($groupKey), 0, 12);
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th style="width:180px">' . $this->escape($label) . '</th><td><code>' . $this->escape($value) . '</code></td></tr>';
    }

    private function canManage(): bool
    {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    private function redirect(array $query): void
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($this->pageUrl($query));
            exit;
        }
    }

    private function pageUrl(array $query = array()): string
    {
        $base = function_exists('admin_url') ? admin_url('admin.php?page=' . self::PAGE_SLUG) : '';
        if ($query === array()) {
            return $base;
        }
        return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($query);
    }

    private function adminPostUrl(): string
    {
        return function_exists('admin_url') ? (string) admin_url('admin-post.php') : '';
    }

    private function escape(string $value): string
    {
        return function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
