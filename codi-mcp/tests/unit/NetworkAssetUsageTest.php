<?php

declare(strict_types=1);

namespace CodiMcpTest\Unit;

use CodiMcp\Core\Deployment\NetworkAssetUsage;
use CodiMcpTest\Framework\TestCase;

final class NetworkAssetUsageTest extends TestCase
{
    protected function setUp(): void
    {
        \codi_mcp_test_reset_environment();
        $GLOBALS['codi_mcp_test_wp_multisite'] = true;
        $GLOBALS['codi_mcp_test_wp_site_options']['active_sitewide_plugins'] = array();
        $GLOBALS['codi_mcp_test_wp_options'][1]['active_plugins'] = array();
        $GLOBALS['codi_mcp_test_wp_options'][2]['active_plugins'] = array();
        $GLOBALS['codi_mcp_test_wp_options'][1]['stylesheet'] = 'site-one-theme';
        $GLOBALS['codi_mcp_test_wp_options'][1]['template'] = 'site-one-theme';
        $GLOBALS['codi_mcp_test_wp_options'][2]['stylesheet'] = 'site-two-theme';
        $GLOBALS['codi_mcp_test_wp_options'][2]['template'] = 'site-two-theme';
    }

    public function test_plugin_is_deletable_only_when_inactive_everywhere(): void
    {
        $usage = new NetworkAssetUsage();
        $plugin = 'fixture/fixture.php';

        $this->assertFalse($usage->pluginActiveAnywhere($plugin));

        $GLOBALS['codi_mcp_test_wp_options'][2]['active_plugins'] = array($plugin);
        $this->assertTrue($usage->pluginActiveAnywhere($plugin));

        $GLOBALS['codi_mcp_test_wp_options'][2]['active_plugins'] = array();
        $GLOBALS['codi_mcp_test_wp_site_options']['active_sitewide_plugins'] = array($plugin => time());
        $this->assertTrue($usage->pluginActiveAnywhere($plugin));
    }

    public function test_theme_is_blocked_when_it_or_a_child_is_active_on_any_site(): void
    {
        $usage = new NetworkAssetUsage();

        $GLOBALS['codi_mcp_test_wp_options'][2]['stylesheet'] = 'target-theme';
        $GLOBALS['codi_mcp_test_wp_options'][2]['template'] = 'target-theme';
        $this->assertTrue($usage->themeOrChildActiveAnywhere('target-theme'));

        $GLOBALS['codi_mcp_test_wp_options'][2]['stylesheet'] = 'active-child';
        $GLOBALS['codi_mcp_test_wp_options'][2]['template'] = 'target-theme';
        $this->assertTrue($usage->themeOrChildActiveAnywhere('target-theme'));

        $GLOBALS['codi_mcp_test_wp_options'][2]['stylesheet'] = 'site-two-theme';
        $GLOBALS['codi_mcp_test_wp_options'][2]['template'] = 'site-two-theme';
        $this->assertFalse($usage->themeOrChildActiveAnywhere('target-theme'));
    }

    public function test_multisite_delete_permissions_require_super_admin_authority(): void
    {
        $plugins = new \CodiMcp\Packages\Plugins\PluginDeployment(1024, 16);
        $themes = new \CodiMcp\Packages\Themes\ThemeDeployment(1024, 16);

        $this->assertTrue($plugins->canDeletePlugins());
        $this->assertTrue($themes->canDeleteThemes());

        $GLOBALS['codi_mcp_test_wp_super_admins'] = array();
        $this->assertFalse($plugins->canDeletePlugins());
        $this->assertFalse($themes->canDeleteThemes());
    }
}
