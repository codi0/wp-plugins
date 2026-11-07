<?php

namespace CodiMcp\Admin;

use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\PackageAvailabilityPolicy;
use CodiMcp\Auth\OAuthProfile;
use CodiMcp\Auth\OAuthServer;

final class NetworkAdminPage
{
    private const PAGE_SLUG = 'codi-mcp-network';
    private const SAVE_ACTION = 'codi_mcp_save_network_packages';

    private PackageAvailabilityPolicy $availability;
    private OAuthProfile $profile;
    private OAuthServer $oauthServer;

    /** @var AbilityPackage[] */
    private array $packages;

    /** @param AbilityPackage[] $packages */
    public function __construct(PackageAvailabilityPolicy $availability, array $packages, OAuthProfile $profile, OAuthServer $oauthServer)
    {
        $this->availability = $availability;
        $this->packages = array_values($packages);
        $this->profile = $profile;
        $this->oauthServer = $oauthServer;
    }

    public function registerMenu(): void
    {
        if (!function_exists('add_menu_page')) {
            return;
        }

        add_menu_page(
            'Codi MCP',
            'Codi MCP',
            'manage_network_options',
            self::PAGE_SLUG,
            array($this, 'render'),
            'dashicons-admin-links'
        );
    }

    public function save(): void
    {
        if (!$this->canManage()) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to manage Codi MCP network settings.');
            }
            return;
        }

        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::SAVE_ACTION);
        }

        $raw = isset($_POST['policy']) && is_array($_POST['policy']) ? wp_unslash($_POST['policy']) : array();
        $states = array();
        foreach ($raw as $packageKey => $state) {
            if (!is_string($packageKey) || !is_scalar($state)) {
                continue;
            }
            $states[strtolower(trim(sanitize_text_field($packageKey)))] = sanitize_text_field((string) $state);
        }

        $saved = $this->availability->saveNetworkStates($states, $this->packageKeys());

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($this->pageUrl($saved ? array('updated' => '1') : array('error' => '1')));
            exit;
        }
    }

    public function revokeOAuthClient(): void
    {
        if (!$this->canManage()) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to manage Codi MCP network settings.');
            }
            return;
        }
        $clientId = isset($_POST['client_id']) ? trim((string) sanitize_text_field(wp_unslash($_POST['client_id']))) : '';
        if ($clientId === '') {
            $this->redirect(array('error' => 'oauth'));
            return;
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('codi_mcp_revoke_network_oauth_client_' . $clientId);
        }
        $this->redirect($this->oauthServer->revokeClient($clientId)
            ? array('updated' => 'oauth')
            : array('error' => 'oauth'));
    }

    public function render(): void
    {
        if (!$this->canManage()) {
            return;
        }

        echo '<div class="wrap"><h1>Codi MCP — Network</h1>';
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . (($_GET['error'] ?? '') === 'oauth' ? 'The network OAuth client could not be disconnected.' : 'Network package policy could not be saved. No persisted change was confirmed.') . '</p></div>';
        }
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . (($_GET['updated'] ?? '') === 'oauth' ? 'Network OAuth client disconnected and its active credentials revoked.' : 'Network package policy updated.') . '</p></div>';
        }

        echo '<p>Control bundled package policy across the multisite network. The network endpoint is a separate MCP resource from every site endpoint, including the main site.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
        $this->row('Network MCP endpoint', $this->mcpEndpoint());
        $this->row('Main site ID', (string) $this->mainSiteId());
        echo '</tbody></table>';

        $this->renderOAuthClients();

        echo '<form method="post" action="' . $this->escape($this->saveUrl()) . '" style="margin-top:24px">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(self::SAVE_ACTION);
        }

        echo '<h2>Package availability</h2>';
        echo '<p><strong>Enabled</strong> forces a package on across the network and exposes all abilities it registers as network-managed. <strong>Disabled</strong> removes it everywhere. <strong>Delegate to site</strong> leaves package availability and ability exposure under each site\'s control. The Multisite package is network infrastructure and cannot be delegated.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Package</th><th style="width:240px">Network policy</th><th>Effective behaviour</th></tr></thead><tbody>';

        foreach ($this->packages as $package) {
            $key = $package->key();
            $state = $this->availability->networkState($key);
            $allowDelegate = $key !== 'multisite';
            echo '<tr><td><strong>' . $this->escape($package->label()) . '</strong><br><code>' . $this->escape($key) . '</code></td><td>';
            echo '<select name="policy[' . $this->escapeAttr($key) . ']">';
            $this->option(PackageAvailabilityPolicy::ENABLED, 'Enabled', $state);
            $this->option(PackageAvailabilityPolicy::DISABLED, 'Disabled', $state);
            if ($allowDelegate) {
                $this->option(PackageAvailabilityPolicy::DELEGATE, 'Delegate to site', $state);
            }
            echo '</select></td><td>' . $this->escape($this->description($key, $state)) . '</td></tr>';
        }

        echo '</tbody></table>';
        if (function_exists('submit_button')) {
            submit_button('Save network package policy');
        } else {
            echo '<p><button type="submit">Save network package policy</button></p>';
        }
        echo '</form></div>';
    }

    private function renderOAuthClients(): void
    {
        $clients = $this->oauthServer->clients();
        echo '<h2>Connected network OAuth clients</h2>';
        if ($clients === array()) {
            echo '<p>No OAuth clients are currently registered for the network endpoint.</p>';
            return;
        }
        echo '<p>Disconnecting a client invalidates its network authorization codes, refresh tokens, and access tokens immediately.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Client</th><th>Last used</th><th style="width:120px">Action</th></tr></thead><tbody>';
        foreach ($clients as $client) {
            $clientId = trim((string) ($client['client_id'] ?? ''));
            if ($clientId === '') {
                continue;
            }
            $lastUsed = (int) ($client['last_used_at'] ?? 0);
            $lastUsedLabel = $lastUsed > 0
                ? (function_exists('date_i18n') ? (string) date_i18n('Y-m-d H:i:s', $lastUsed) : gmdate('Y-m-d H:i:s', $lastUsed) . ' UTC')
                : 'Never';
            echo '<tr><td><strong>' . $this->escape((string) ($client['client_name'] ?? 'OAuth client')) . '</strong><br><code>' . $this->escape($clientId) . '</code></td><td>' . $this->escape($lastUsedLabel) . '</td><td>';
            echo '<form method="post" action="' . $this->escape($this->revokeUrl()) . '"><input type="hidden" name="client_id" value="' . $this->escapeAttr($clientId) . '">';
            if (function_exists('wp_nonce_field')) {
                wp_nonce_field('codi_mcp_revoke_network_oauth_client_' . $clientId);
            }
            echo '<button type="submit" class="button">Disconnect</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function description(string $key, string $state): string
    {
        if ($state === PackageAvailabilityPolicy::ENABLED) {
            return $key === 'multisite'
                ? 'The main-site gateway and federation receiver are active across the network.'
                : 'Available on every site; all registered package abilities are exposed by network policy and are not editable per site.';
        }
        if ($state === PackageAvailabilityPolicy::DISABLED) {
            return 'Not registered on any site.';
        }
        return 'Each site controls whether this package is active and which of its abilities are exposed.';
    }

    private function option(string $value, string $label, string $selected): void
    {
        echo '<option value="' . $this->escapeAttr($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . $this->escape($label) . '</option>';
    }

    /** @return string[] */
    private function packageKeys(): array
    {
        return array_values(array_map(static fn (AbilityPackage $package): string => $package->key(), $this->packages));
    }

    private function mainSiteId(): int
    {
        $networkId = function_exists('get_current_network_id') ? max(1, (int) get_current_network_id()) : 1;
        return function_exists('get_main_site_id') ? max(1, (int) get_main_site_id($networkId)) : 1;
    }

    private function mcpEndpoint(): string
    {
        return $this->profile->primaryEndpoint();
    }

    private function canManage(): bool
    {
        if (function_exists('current_user_can') && current_user_can('manage_network_options')) {
            return true;
        }
        $userId = function_exists('wp_get_current_user') ? (int) (wp_get_current_user()->ID ?? 0) : 0;
        return function_exists('is_super_admin') && is_super_admin($userId);
    }

    private function pageUrl(array $query = array()): string
    {
        $base = function_exists('network_admin_url')
            ? (string) network_admin_url('admin.php?page=' . self::PAGE_SLUG)
            : '';
        if ($query === array()) {
            return $base;
        }
        return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($query);
    }

    private function saveUrl(): string
    {
        return function_exists('network_admin_url')
            ? (string) network_admin_url('edit.php?action=' . self::SAVE_ACTION)
            : '';
    }

    private function revokeUrl(): string
    {
        return function_exists('network_admin_url')
            ? (string) network_admin_url('edit.php?action=codi_mcp_revoke_network_oauth_client')
            : '';
    }

    private function redirect(array $query): void
    {
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($this->pageUrl($query));
            exit;
        }
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th style="width:220px">' . $this->escape($label) . '</th><td><code>' . $this->escape($value) . '</code></td></tr>';
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
